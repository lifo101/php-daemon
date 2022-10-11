<?php

namespace Lifo\Daemon\Event;


/**
 * Event object for PID changes
 */
class PidEvent extends DaemonEvent
{
    private int  $pid;
    private ?int $prev;

    public function __construct(int $pid, ?int $prev = null)
    {
        parent::__construct();

        $this->pid = $pid;
        $this->prev = $prev;
    }

    /**
     * Get the signal number that was caught
     *
     * @return int Signal number
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    public function getPreviousPid(): ?int
    {
        return $this->prev;
    }
}