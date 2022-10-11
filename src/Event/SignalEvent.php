<?php

namespace Lifo\Daemon\Event;


/**
 * Event object for signal handling.
 */
class SignalEvent extends DaemonEvent
{
    private int $signal;

    public function __construct($pid)
    {
        parent::__construct();

        $this->signal = $pid;
    }

    /**
     * Get the signal number that was caught
     *
     * @return int Signal number
     */
    public function getSignal(): int
    {
        return $this->signal;
    }
}