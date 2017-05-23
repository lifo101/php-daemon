<?php

namespace Lifo\Daemon\Event;

use Lifo\Daemon\Daemon;


/**
 * Event object for children that are reaped from the {@link ProcessManager} plugin.
 */
class ReapedEvent extends DaemonEvent
{
    /**
     * list of PIDs
     *
     * @var int[]
     */
    private $pids = [];

    /**
     * ReapedEvent constructor.
     *
     * @param Daemon    $daemon
     * @param int|int[] $pids 1 or more PIDs that got reaped
     */
    public function __construct(Daemon $daemon, $pids)
    {
        parent::__construct($daemon);

        $this->pids = (array)$pids;
    }

    /**
     * @return int[]
     */
    public function getPids()
    {
        return $this->pids;
    }
}