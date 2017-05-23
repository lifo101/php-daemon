<?php

namespace Lifo\Daemon\Plugin\Lock;


use Lifo\Daemon\Exception\CleanErrorException;

/**
 * Daemon Plugin for a Shared Memory Lock.
 * Only 1 daemon process will be able to start if another has an active lock.
 *
 * Uses Shared Memory on the local system to maintain a lock.
 */
class ShmLock extends AbstractLock
{
    /**
     * Attached SHM resource
     *
     * @var resource
     */
    private $shm;
    /**
     * Timestamp when PID was updated
     *
     * @var int
     */
    private $touched;
    /**
     * SysV IPC key
     *
     * @var int
     */
    private $id;

    protected function getDefaults()
    {
        return [
            // Shared Memory ID; Defaults to the command file that started the daemon $argv[0]
            'id'                    => $GLOBALS['argv'][0],
            // Shared Memory variable address; you won't normally need to change this
            'address'               => 1,
            // Shared Memory size in bytes
            'size'                  => 512,
            // Shared Memory permission bits
            'perm'                  => 0666,
            // time-to-live for PID file in seconds.
            // If the lock file is not touched within this threshold it's considered stale.
            'ttl'                   => 0,
            // maximum seconds to cache PID from storage medium
            'pid_refresh_frequency' => 5,
            // minimum seconds to wait before updating the PID during the event loop
            'pid_update_frequency'  => 5,
        ];
    }

    public function setup($options = [])
    {
        parent::setup($options);
        $this->id = ftok($this['id'], 'L');
        $this->shm = shm_attach($this->id, $this['size'], $this['perm']);
    }

    protected function onStats(array $stats, $alias)
    {
        $stats = parent::onStats($stats, $alias);
        $stats['plugins'][$alias]['options']['perm'] = sprintf('%04o', $this['perm']);
        $stats['plugins'][$alias]['shm_id'] = $this->id;
        return $stats;
    }

    public function acquire()
    {
        // load PID from storage medium
        $pid = $this->getPid();

        // another process has the lock
        if ($pid && $pid != $this->pid) {
            if ($this->isLockStale() && !$this->isProcessAlive($pid)) {
                $pid = null;
            } else {
                throw new CleanErrorException(sprintf("Process already running with PID=%d SHM=%d TTL=%d",
                    $pid,
                    $this->id,
                    $this->getTTL()
                ));
            }
        }

        if (!$pid) {
            // no process has the lock (or it was expired above)
            $pid = $this->pid;
            shm_put_var($this->shm, $this['address'], ['pid' => $pid, 'time' => time()]);
        } else {
            // current process has the lock
            if (!$this['pid_update_frequency'] || !$this->touched || microtime(true) > $this->touched + $this['pid_update_frequency']) {
                shm_put_var($this->shm, $this['address'], ['pid' => $pid, 'time' => time()]);
                $this->touched = time();
            }
        }
    }

    /**
     * Return the number of seconds the lock has before it expires.
     *
     * @return int
     */
    protected function getTTL()
    {
        if (!$this['ttl']) {
            return 0;
        }

        $data = $this->getData();
        return ((isset($data['time']) ? $data['time'] : time()) + $this['ttl']) + 1 - time();
    }

    /**
     * Determines if the PID file is stale
     *
     * @return bool
     */
    public function isLockStale()
    {
        if (!$this['ttl']) {
            return false;
        }

        $data = $this->getData();
        $mtime = isset($data['time']) ? $data['time'] : null;
        return $mtime && ($mtime + $this['ttl']) < time();
    }

    public function release()
    {
        $pid = $this->getPid();
        if ($this->shm) {
            if ($pid == $this->pid && shm_has_var($this->shm, $this['address'])) {
                shm_remove($this->shm);
            }
            shm_detach($this->shm);
        }
    }

    /**
     * Return the shared memory data
     */
    private function getData()
    {
        return shm_has_var($this->shm, $this['address']) ? shm_get_var($this->shm, $this['address']) : null;
    }

    public function getPid()
    {
        static $lastTime, $pid;
        if (!$pid || $pid != $this->pid || !$lastTime || microtime(true) > $lastTime + $this['pid_refresh_frequency']) {
            $data = $this->getData();
            $pid = isset($data['pid']) ? $data['pid'] : null;
        }
        return $pid ?: null;
    }

    protected function verify()
    {
        if (empty($this['id'])) {
            throw new CleanErrorException("Option 'id' is missing");
        }
        if (empty($this['perm']) || !is_numeric($this['perm'])) {
            throw new CleanErrorException("Option 'perm' must be an int >= 1");
        }
        if (empty($this['address']) || !is_numeric($this['address']) || $this['address'] < 1) {
            throw new CleanErrorException("Option 'address' must be an int >= 1");
        }
        if (empty($this['size']) || !is_numeric($this['size'])) {
            throw new CleanErrorException("Option 'size' must be an int >= 1");
        }
        if ($this['ttl'] && $this['pid_update_frequency'] && $this['ttl'] < $this['pid_update_frequency']) {
            throw new CleanErrorException(sprintf("'ttl' (%s) must be > 'pid_update_frequency' (%s)", $this['ttl'], $this['pid_update_frequency']));
        }
    }
}