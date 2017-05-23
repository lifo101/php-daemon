<?php

namespace Lifo\Daemon\Event;

use Lifo\Daemon\Daemon;


/**
 * Event object for signal handling.
 */
class SignalEvent extends DaemonEvent
{
    /**
     * The signal number
     *
     * @var integer
     */
    private $signal;

    public function __construct(Daemon $daemon, $pid)
    {
        parent::__construct($daemon);

        $this->signal = $pid;
    }

    /**
     * Get the signal number that was caught
     *
     * @return int Signal number
     */
    public function getSignal()
    {
        return $this->signal;
    }
}