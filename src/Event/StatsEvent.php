<?php

namespace Lifo\Daemon\Event;


/**
 * Event object for stats dump.
 */
class StatsEvent extends DaemonEvent
{
    private array $stats;

    public function __construct(array $stats)
    {
        parent::__construct();

        $this->stats = $stats;
    }

    public function setStats(array $stats)
    {
        $this->stats = $stats;
    }

    /**
     * Get the current set of stats
     *
     * @return array Stats array
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}