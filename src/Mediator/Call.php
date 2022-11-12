<?php

namespace Lifo\Daemon\Mediator;

use Exception;
use Lifo\Daemon\Promise;

/**
 * Represents a method call to a mediated worker process. A call is serialized into a message buffer and passed back
 * and forth between a parent and child process.
 */
class Call
{
    const UNCALLED    = 0;
    const CALLED      = 1;
    const RUNNING     = 2;
    const RETURNED    = 3;
    const CANCELLED   = 4;
    const TIMEOUT     = 9;
    const STATUS_TEXT = [
        self::UNCALLED  => 'UNCALLED',
        self::CALLED    => 'CALLED',
        self::RUNNING   => 'RUNNING',
        self::RETURNED  => 'RETURNED',
        self::CANCELLED => 'CANCELLED',
        self::TIMEOUT   => 'TIMEOUT',
    ];

    public static int $NEXT_ID = 2; // must be > SysV::HEADER_ADDRESS

    /**
     * Unique ID for the call.
     */
    private int $id;

    /**
     * PID for the call
     */
    private ?int $pid = null;

    /**
     * Method name to call
     */
    private string $method;

    /**
     * Arguments for the method call
     */
    private ?array $args;

    /**
     * Was GC performed on this call?
     */
    private bool $gc = false;

    /**
     * The result from the background process. To be returned to the parent.
     *
     * @var mixed|null
     */
    private $result = null;

    /**
     * How many attempts have been made to complete this call?
     */
    private int $attempts = 0;

    /**
     * How many times has this call caused an error?
     */
    private int $errors = 0;

    /**
     * Approximate amount of memory the call requires.
     */
    private int $size = 1024;

    /**
     * Current status of the call. See the {@link Call} constants for more information.
     */
    private int $status = 0;

    /**
     * Track timing of various call states
     *
     * @var float[]
     */
    private array $time = [];

    /**
     * The returned promise when a call is completed.
     */
    private ?Promise $promise = null;

    /**
     * Private constructor. Use {@link Call::create} instead.
     *
     * @param string     $method
     * @param array|null $args
     */
    private function __construct(string $method, array $args = null)
    {
        $this->id = self::$NEXT_ID++;
        $this->method = $method;
        $this->args = $args ?: null;
        $this->uncalled();
    }

    /**
     * Factory to create a call. Tries to determine the memory consumption of the call.
     *
     * @param string     $method
     * @param array|null $args
     *
     * @return $this
     */
    public static function create(string $method, array $args = null): self
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
     *
     * @return string
     */
    public static function getStatusText($call): string
    {
        $status = $call instanceof Call ? $call->getStatus() : $call;
        return self::STATUS_TEXT[$status] ?? 'unknown';
    }

    public function __serialize(): array
    {
        return [
            'id'     => $this->id,
            'pid'    => $this->pid,
            'status' => $this->status,
            'method' => $this->method,
            'args'   => $this->args,
            'time'   => $this->time,
            'result' => $this->result,
            // do not include promise
        ];
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $var => $value) {
            if (property_exists($this, $var)) {
                $this->$var = $value;
            }
        }
    }

    /**
     * Merge the other call data into this one.
     *
     * @param Call|null $other
     *
     * @return $this
     */
    public function merge(?Call $other): self
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
    public function getHeader(): array
    {
        return [
            'id'     => $this->id,
            'status' => $this->status,
            'time'   => $this->time[$this->status] ?? 0,
            'pid'    => getmypid(),
        ];
    }

    /**
     * Return the message type the call should use
     */
    public function getMessageType(): int
    {
        switch ($this->status) {
            case self::UNCALLED:
                return Mediator::MSG_CALL;
            case self::RUNNING:
                return Mediator::MSG_RUNNING;
            case self::RETURNED:
                return Mediator::MSG_RETURN;
            default:
                throw new Exception("Unable to determine call message type based on current status of %s", $this->status ?: 'null');
        }
    }

    /**
     * Is the call in the specified status?
     * If an array of statuses is supplied; true is returned if at least one matches.
     *
     * @param int|int[] $status
     *
     * @return bool
     */
    public function is($status): bool
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
    public function isDone(): bool
    {
        return $this->is(self::RETURNED);
    }

    /**
     * Returns true if the call is in an "active" state
     */
    public function isActive(): bool
    {
        return !in_array($this->status, [self::TIMEOUT, self::RETURNED, self::CANCELLED]);
    }

    /**
     * Set the status of the call.
     * Cannot rewind a call to a previous status, except to reset it to {@link Call::UNCALLED}
     *
     * @param int        $status
     * @param float|null $when
     *
     * @return $this
     * @throws Exception
     */
    public function setStatus(int $status, float $when = null): self
    {
        // You can restart a call but you can't decrement or arbitrarily set the status
        if ($status < $this->status && $status > 0) {
            throw new Exception("Cannot rewind call status. Current=$this->status Given=$status");
        }

        $this->status = $status;
        $this->time[$status] = $when ?: microtime(true);
        return $this;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Mark the call as {@link Call::UNCALLED}
     *
     * @param float|null $when
     *
     * @return self
     */
    public function uncalled(float $when = null): self
    {
        return $this->setStatus(self::UNCALLED, $when);
    }

    /**
     * Mark the call as {@link Call::CALLED}
     *
     * @param float|null $when
     *
     * @return self
     */
    public function called(float $when = null): self
    {
        return $this->setStatus(self::CALLED, $when);
    }

    /**
     * Mark the call as {@link Call::RUNNING}
     *
     * @param float|null $when
     * @param int|null   $pid
     *
     * @return self
     */
    public function running(float $when = null, int $pid = null): self
    {
        $this->pid = $pid ?: (getmypid() ?: null);
        return $this->setStatus(self::RUNNING, $when);
    }

    /**
     * Mark the call as {@link Call::RETURNED} and save result
     *
     * @param mixed      $result
     * @param float|null $when
     *
     * @return self
     */
    public function returned($result, float $when = null): self
    {
        $this->result = $result;
        // approximately determine the call size based on the returned data; not at all a perfect solution
        $this->size += strlen(serialize($result));
        return $this->setStatus(self::RETURNED, $when);
    }

    /**
     * Mark the call as {@link Call::CANCELLED}
     *
     * @param float|null $when
     *
     * @return self
     */
    public function cancelled(float $when = null): self
    {
        return $this->setStatus(self::CANCELLED, $when);
    }

    /**
     * Mark the call as {@link Call::TIMEOUT}
     *
     * @param float|null $when
     *
     * @return self
     */
    public function timeout(float $when = null): self
    {
        return $this->setStatus(self::TIMEOUT, $when);
    }

    /**
     * Garbage Collector
     */
    public function gc(): bool
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
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Return the unique ID of the call
     *
     * @return int
     */
    public function getId(): int
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
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Return error count for the call.
     *
     * @return int
     */
    public function getErrors(): int
    {
        return $this->errors;
    }

    /**
     * Increment error count
     *
     * @param int $count
     *
     * @return $this
     */
    public function incErrors(int $count = 1): self
    {
        $this->errors += $count;
        return $this;
    }

    /**
     * Get the microtime for the specified status.
     *
     * @param int|null $status
     *
     * @return float|float[]
     */
    public function getTime(int $status = null)
    {
        return $status === null ? $this->time : $this->time[$status] ?? 0;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    /**
     * @param int $pid
     *
     * @return $this
     */
    public function setPid(int $pid): self
    {
        $this->pid = $pid;
        return $this;
    }

    /**
     * @return array
     */
    public function getArguments(): array
    {
        return $this->args ?? [];
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Returns the promise for this call. If no promise is currently available, one will be created automatically.
     *
     * @return Promise
     */
    public function getPromise(): ?Promise
    {
        if (!$this->promise) {
            $this->setPromise(new Promise());
        }
        return $this->promise;
    }

    /**
     * Set the Promise for the call.
     *
     * @param Promise|null $promise
     *
     * @return self
     */
    public function setPromise(?Promise $promise): self
    {
        $this->promise = $promise;
        return $this;
    }

    public function hasPromise(): bool
    {
        return !empty($this->promise);
    }
}