<?php

namespace Lifo\Daemon\Plugin\Lock;


use Lifo\Daemon\Daemon;
use Lifo\Daemon\Event\DaemonEvent;
use Lifo\Daemon\Event\PidEvent;
use Lifo\Daemon\Plugin\AbstractPlugin;

/**
 * Abstract Lock class.
 *
 * Adds extra functionality that all Lock plugins should implement.
 */
abstract class AbstractLock extends AbstractPlugin
{
    /**
     * Daemon PID
     *
     * @var int
     */
    protected $pid;

    public function setup($options = [])
    {
        parent::setup($options);

        $daemon = Daemon::getInstance();
        $this->pid = $daemon->getPid();

        $daemon
            ->on(DaemonEvent::ON_INIT, function () {
                $this->acquire();
            })
            ->on(DaemonEvent::ON_PRE_EXECUTE, function () {
                $this->acquire();
            })
            ->on(DaemonEvent::ON_PID_CHANGE, function (PidEvent $e) {
                $this->pid = $e->getPid();
                $this->acquire();
            });
    }

    public function teardown()
    {
        $this->release();
    }

    /**
     * Return the number of seconds the lock has before it expires. Mainly used in stats collection only.
     *
     * @return int
     */
    protected function getTTL()
    {
        return 0;
    }

    protected function onStats(array $stats, $alias)
    {
        $stats = parent::onStats($stats, $alias);
        $stats['plugins'][$alias]['ttl'] = $this->getTTL();
        return $stats;
    }

    /**
     * Determine if the process with $pid is alive or not.
     *
     * @param $pid
     * @return bool
     */
    public function isProcessAlive($pid)
    {
        return posix_kill($pid, 0);
    }

    /**
     * Return the current PID as found on the storage medium
     *
     * @return int|null
     */
    abstract public function getPid();

    /**
     * Acquire or re-acquire the lock and return true if successful.
     *
     * @return bool True if our process acquired the lock.
     */
    abstract public function acquire();

    /**
     * Release our hold on the lock.
     */
    abstract public function release();
}