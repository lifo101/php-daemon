<?php

namespace Lifo\Daemon\Event;

use Lifo\Daemon\Daemon;


/**
 * Event object for PID changes
 */
class PidEvent extends DaemonEvent
{
    /**
     * The pid
     *
     * @var integer
     */
    private $pid;
    /**
     * @var integer|null
     */
    private $prev;

    public function __construct(Daemon $daemon, $pid, $prev = null)
    {
        parent::__construct($daemon);

        $this->pid = $pid;
        $this->prev = $prev;
    }

    /**
     * Get the signal number that was caught
     *
     * @return int Signal number
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @return int|null
     */
    public function getPreviousPid()
    {
        return $this->prev;
    }
}