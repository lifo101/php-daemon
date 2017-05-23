<?php

namespace Lifo\Daemon\Event;

use Lifo\Daemon\Daemon;


/**
 * Event object for stats dump.
 */
class StatsEvent extends DaemonEvent
{
    /**
     * The stats
     *
     * @var array
     */
    private $stats;

    public function __construct(Daemon $daemon, $stats)
    {
        parent::__construct($daemon);

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
    public function getStats()
    {
        return $this->stats;
    }
}