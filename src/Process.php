<?php

namespace Lifo\Daemon;

use ArrayAccess;

/**
 * Represents a process that was forked from the {@link ProcessManager}.
 * This is used within the parent process.
 *
 * Implements the OptionsTrait and ArrayAccess so that the Mediator can add custom vars to the process as-needed
 * w/o requiring getter/setters.
 */
class Process implements ArrayAccess
{
    use OptionsTrait;

    /**
     * Minimum timeout for processes.
     */
    public static float $MIN_TIMEOUT = 60.0;

    /**
     * Process PID
     */
    private int $pid;

    /**
     * Process group name
     */
    private ?string $group;

    /**
     * Start time
     */
    private float $start;

    /**
     * Stop time (after calling {@link stop})
     */
    private float $stopped = 0;

    /**
     * Maximum timeout for the process after it's stopped. If a process doesn't terminate on its own a SIGKILL will be
     * sent to it to force it to die.
     */
    private float $timeout;

    public function __construct(int $pid, ?string $group = null, ?float $timeout = null)
    {
        $this->start = microtime(true);
        $this->pid = $pid;
        $this->group = $group;
        $this->timeout = max($timeout ?? 0.0, self::$MIN_TIMEOUT);
    }

    /**
     * Return the total runtime of the process.
     *
     * @return float
     */
    public function getRuntime(): float
    {
        return ($this->stopped ?: microtime(true)) - $this->start;
    }

    /**
     * Return the timeout value
     *
     * @return float
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * Returns true if the process has overrun its timeout
     */
    public function isTimedOut(): bool
    {
        return $this->timeout && $this->getRuntime() >= $this->timeout;
    }

    /**
     * Attempt to stop the process. First tries to gracefully stop the process by sending a SIGTERM.
     * If the process has timed out a SIGKILL will be sent to forcibly stop it.
     *
     * @return bool True if the process was forcibly stopped
     */
    public function stop(): bool
    {
        if (!$this->stopped) {
            $this->stopped = microtime(true);
        }

        if (!$this->isAlive()) {
            return false;
        }

        if (microtime(true) > $this->stopped + $this->getTimeout()) {
            $this->kill();
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
    public function isAlive(): bool
    {
        return posix_kill($this->pid, 0);
    }

    /**
     * Send a KILL or any other signal to the process. Normally to KILL or TERM the process.
     *
     * @param int $signal
     */
    public function kill(int $signal = SIGKILL)
    {
        posix_kill($this->pid, $signal);
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getStart(): float
    {
        return $this->start;
    }

    public function getStopped(): float
    {
        return $this->stopped;
    }

    public function setStopped(float $stopped)
    {
        $this->stopped = $stopped;
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->options[$offset]);
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
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
    public function offsetSet($offset, $value): void
    {
        $this->options[$offset] = $value;
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->options[$offset]);
    }
}