<?php

namespace Lifo\Daemon\Mediator;

use Lifo\Daemon\Promise;

/**
 * Represents a method call to a mediated worker process.
 */
class Call implements \Serializable
{
    const UNCALLED  = 0;
    const CALLED    = 1;
    const RUNNING   = 2;
    const RETURNED  = 3;
    const CANCELLED = 4;
    const TIMEOUT   = 9;
    public static $STATUS_TEXT = [ // can be 'const' in PHP 5.6
        self::UNCALLED  => 'UNCALLED',
        self::CALLED    => 'CALLED',
        self::RUNNING   => 'RUNNING',
        self::RETURNED  => 'RETURNED',
        self::CANCELLED => 'CANCELLED',
        self::TIMEOUT   => 'TIMEOUT',
    ];

    public static $NEXT_ID = 2; // must be > SysV::HEADER_ADDRESS

    /**
     * @var int
     */
    private $id;

    /**
     * PID for the call
     *
     * @var int
     */
    private $pid;

    /**
     * Method name to call
     *
     * @var string
     */
    private $method;

    /**
     * Arguments for method
     *
     * @var array
     */
    private $args;

    /**
     * Was GC performed on this call?
     *
     * @var bool
     */
    private $gc = false;

    /**
     * The result from the background process
     *
     * @var mixed
     */
    private $result = null;

    /**
     * How many attempts have been made to complete this call?
     *
     * @var int
     */
    private $attempts = 0;

    /**
     * How many times has this call caused an error?
     *
     * @var int
     */
    private $errors = 0;

    /**
     * Approximate amount of memory the call requires.
     *
     * @var int
     */
    private $size = 1024;

    /**
     * Current status of the call
     *
     * @var int
     */
    private $status;

    /**
     * Track timing of various call states
     *
     * @var float[]
     */
    private $time = [];

    /**
     * @var Promise
     */
    private $promise;

    /**
     * Private constructor. Use {@link Call::create} instead.
     *
     * @param string $method
     * @param array  $args
     */
    private function __construct($method, array $args = null)
    {
        $this->id = self::$NEXT_ID++;
        $this->method = $method;
        $this->args = $args;
        $this->uncalled();
    }

    /**
     * Factory to create a call. Tries to determine the memory consumption of the call.
     *
     * @param string $method
     * @param array  $args
     * @return $this
     */
    public static function create($method, array $args = null)
    {
        // try to determine how much memory was consumed when the call was created.
        // This is not perfect but is close enough for this.
        $before = memory_get_usage();
        $call = new static($method, $args);
        $call->size = memory_get_usage() - $before;
        return $call;
    }

    /**
     * Get the status text string for the Call or status ID given.
     *
     * @param Call|int $call
     * @return string
     */
    public static function getStatusText($call)
    {
        $status = $call instanceof Call ? $call->getStatus() : $call;
        if (isset(self::$STATUS_TEXT[$status])) {
            return self::$STATUS_TEXT[$status];
        }
        return 'unknown';
    }

    public function serialize()
    {
        $data = [
            'id'     => $this->id,
            'pid'    => $this->pid,
            'status' => $this->status,
            'method' => $this->method,
            'args'   => $this->args,
//            'gc'       => $this->gc,
//            'attempts' => $this->attempts,
//            'errors'   => $this->errors,
//            'size'     => $this->size,
            'time'   => $this->time,
            'result' => $this->result,
            // do not include promise
        ];
        $str = serialize($data);
        return $str;
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        foreach ($data as $var => $value) {
            if (property_exists($this, $var)) {
                $this->$var = $value;
            }
        }
    }

    /**
     * Merge the other call data into this one.
     *
     * @param Call $other
     * @return $this
     */
    public function merge(Call $other = null)
    {
        if ($other === null) {
            return $this;
        }

        // the pid is important so the parent can track what process answered a call
        $this->pid = $other->pid ?: $this->pid;
        $this->time = array_replace($this->time, $other->time);
        $this->result = $other->result ?: $this->result;
        return $this;
    }

    /**
     * Return the call header.
     *
     * @return array
     */
    public function getHeader()
    {
        return [
            'id'     => $this->id,
            'status' => $this->status,
            'time'   => isset($this->time[$this->status]) ? $this->time[$this->status] : 0,
            'pid'    => getmypid(),
//            'pid'    => $this->pid,
        ];
    }

    /**
     * Return the message type the call should use
     */
    public function getMessageType()
    {
        switch ($this->status) {
            case self::UNCALLED:
                return Mediator::MSG_CALL;
            case self::RUNNING:
                return Mediator::MSG_RUNNING;
            case self::RETURNED:
                return Mediator::MSG_RETURN;
            default:
                throw new \Exception("Unable to determine call message type based on current status of %s", $this->status ?: 'null');
        }
    }

    /**
     * Is the call in the specified status?
     * If an array of statuses is supplied; true is returned if at least one matches.
     *
     * @param int|int[] $status
     * @return bool
     */
    public function is($status)
    {
        if (is_array($status)) {
            foreach ($status as $s) {
                if ($this->status == $s) {
                    return true;
                }
            }
        }

        return $this->status == $status;
    }

    /**
     * A call is considered DONE if it is in RETURNED
     */
    public function isDone()
    {
        return $this->is(self::RETURNED);
    }

    /**
     * Returns true if the call is in an "active" state
     */
    public function isActive()
    {
        return !in_array($this->status, [self::TIMEOUT, self::RETURNED, self::CANCELLED]);
    }

    /**
     * Set the status of the call.
     * Cannot rewind a call to a previous status, except to reset it to {@link Call::UNCALLED}
     *
     * @param int   $status
     * @param float $when
     * @return $this
     * @throws \Exception
     */
    public function setStatus($status, $when = null)
    {
        // You can restart a call but you can't decrement or arbitrarily set the status
        if ($status < $this->status && $status > 0) {
            throw new \Exception("Cannot rewind call status. Current=$this->status Given=$status");
        }

        $this->status = $status;
        $this->time[$status] = $when ?: microtime(true);
        return $this;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Mark the call as {@link Call::UNCALLED}
     *
     * @param float $when
     * @return Call
     */
    public function uncalled($when = null)
    {
        return $this->setStatus(self::UNCALLED, $when);
    }

    /**
     * Mark the call as {@link Call::CALLED}
     *
     * @param float $when
     * @return Call
     */
    public function called($when = null)
    {
        return $this->setStatus(self::CALLED, $when);
    }

    /**
     * Mark the call as {@link Call::RUNNING}
     *
     * @param float $when
     * @param int   $pid
     * @return Call
     */
    public function running($when = null, $pid = null)
    {
        $this->pid = $pid ?: getmypid();
        return $this->setStatus(self::RUNNING, $when);
    }

    /**
     * Mark the call as {@link Call::RETURNED} and save result
     *
     * @param mixed $result
     * @param float $when
     * @return Call
     */
    public function returned($result, $when = null)
    {
        $this->result = $result;
        // approximately determine the call size based on the returned data; not at all a perfect solution
        $this->size += strlen(serialize($result));
        return $this->setStatus(self::RETURNED, $when);
    }

    /**
     * Mark the call as {@link Call::CANCELLED}
     *
     * @param float $when
     * @return Call
     */
    public function cancelled($when = null)
    {
        return $this->setStatus(self::CANCELLED, $when);
    }

    /**
     * Mark the call as {@link Call::TIMEOUT}
     *
     * @param float $when
     * @return Call
     */
    public function timeout($when = null)
    {
        return $this->setStatus(self::TIMEOUT, $when);
    }

    /**
     * Garbage Collector
     */
    public function gc()
    {
        if ($this->gc || $this->isActive()) {
            return false;
        }

        $this->result = null;
        $this->args = null;
        $this->gc = true;
        return true;
    }

    /**
     * Return the runtime of the call.
     *
     * @return float
     */
    public function runtime()
    {
        switch ($this->status) {
            case self::RUNNING:
                return microtime(true) - $this->time[self::RUNNING];
            case self::RETURNED:
                return $this->time[self::RETURNED] - $this->time[self::RUNNING];
            default:
                if ($this->time) {
                    $end = end($this->time);
                    $start = reset($this->time);
                    return $end - $start;
                }
                return 0;
        }
    }

    /**
     * Retry the call
     */
    public function retry()
    {
        $this->attempts++;
        $this->errors = 0;
        $this->uncalled();
    }

    /**
     * Return the memory size of this call (approximately).
     *
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the result from the call
     *
     * @return mixed
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Set the result obtained from a background process.
     *
     * @param mixed $result
     */
    public function setResult($result)
    {
        $this->result = $result;
    }

    /**
     * @return int
     */
    public function getAttempts()
    {
        return $this->attempts;
    }

    /**
     * Return error count for the call.
     *
     * @return int
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Increment error count
     *
     * @param int $count
     * @return $this
     */
    public function incErrors($count = 1)
    {
        $this->errors += $count;
        return $this;
    }

    /**
     * Get the microtime for the specified status.
     *
     * @param int $status
     * @return float|float[]
     */
    public function getTime($status = null)
    {
        if ($status === null) {
            return $this->time;
        }
        return isset($this->time[$status]) ? $this->time[$status] : 0;
    }

    /**
     * @return int
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @param int $pid
     * @return $this
     */
    public function setPid($pid)
    {
        $this->pid = $pid;
        return $this;
    }

    /**
     * @return array
     */
    public function getArguments()
    {
        return $this->args;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * Returns the promise for this call. If no promise is currently available, one will be created automatically.
     *
     * @return Promise
     */
    public function getPromise()
    {
        if (!$this->promise) {
            $this->setPromise(new Promise());
        }
        return $this->promise;
    }

    /**
     * @param Promise $promise
     */
    public function setPromise(Promise $promise = null)
    {
        $this->promise = $promise;
    }

    public function hasPromise()
    {
        return !empty($this->promise);
    }
}