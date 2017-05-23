<?php

namespace Lifo\Daemon;

/**
 * Represents a process that was forked from the {@link ProcessManager}.
 * This is used within the parent process.
 *
 * Implements the OptionsTrait and ArrayAccess so that the Mediator can add custom vars to the process as-needed
 * w/o requiring getter/setters.
 */
class Process implements \ArrayAccess
{
    use OptionsTrait;

    /**
     * Minimum timeout for processes.
     *
     * @var int
     */
    public static $MIN_TIMEOUT = 60;

    /**
     * Process PID
     *
     * @var int
     */
    private $pid;

    /**
     * Process group name
     *
     * @var string
     */
    private $group;

    /**
     * Start time
     *
     * @var float
     */
    private $start;

    /**
     * Stop time (after calling {@link stop})
     *
     * @var float
     */
    private $stop;

    /**
     * Maximum timeout for the process after it's stopped. If a process does'nt terminate on its own a SIGKILL will be
     * sent to it to force it to die.
     *
     * @var float
     */
    private $timeout;

    public function __construct($pid, $group, $timeout = null)
    {
        $this->start = microtime(true);
        $this->pid = (int)$pid;
        $this->group = $group ?: null;
        $this->timeout = max($timeout ?: 0, self::$MIN_TIMEOUT);
    }

    /**
     * Return the total runtime of the process.
     *
     * @return float
     */
    public function getRuntime()
    {
        return ($this->stop ?: microtime(true)) - $this->start;
    }

    /**
     * Return the timeout value
     *
     * @return float
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * Returns true if the process has overrun its timeout
     */
    public function isTimedout()
    {
        return $this->timeout && $this->getRuntime() >= $this->timeout;
    }

    /**
     * Attempt to stop the process. First tries to gracefully stop the process by sending a SIGTERM.
     * If the process has timed out a SIGKILL will be sent to forcibly stop it.
     *
     * @return bool True if the process was forcibly stopped
     */
    public function stop()
    {
        if (!$this->stop) {
            $this->stop = microtime(true);
        }

        if (!$this->isAlive()) {
            return false;
        }

        if (microtime(true) > $this->stop + $this->getTimeout()) {
            $this->kill(SIGKILL);
            return true;
        } else {
            $this->kill(SIGTERM);

        }

        return false;
    }

    /**
     * Determine if the process is still alive.
     *
     * @return bool True if the process is still alive.
     */
    public function isAlive()
    {
        return posix_kill($this->pid, 0);
    }

    /**
     * Send a KILL or any other signal to the process. Normally to KILL or TERM the process.
     *
     * @param int $signal
     */
    public function kill($signal = SIGKILL)
    {
        posix_kill($this->pid, $signal);
    }

    /**
     * @return string
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * @return int
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @return float
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * @return float
     */
    public function getStop()
    {
        return $this->stop;
    }

    /**
     * @param float $stop
     */
    public function setStop($stop)
    {
        $this->stop = $stop;
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->options[$offset]);
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->options[$offset];
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
        $this->options[$offset] = $value;
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset)
    {
        unset($this->options[$offset]);
    }
}