<?php

namespace Lifo\Daemon\Event;


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
    private array $pids;

    /**
     * ReapedEvent constructor.
     *
     * @param int|int[] $pids 1 or more PIDs that got reaped
     */
    public function __construct($pids)
    {
        parent::__construct();

        $this->pids = (array)$pids;
    }

    /**
     * @return int[]
     */
    public function getPids(): array
    {
        return $this->pids;
    }
}