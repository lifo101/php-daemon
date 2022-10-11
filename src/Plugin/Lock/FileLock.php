<?php

namespace Lifo\Daemon\Plugin\Lock;


use Lifo\Daemon\Daemon;
use Lifo\Daemon\Exception\CleanErrorException;
use Lifo\Daemon\LogTrait;
use Lifo\Daemon\StringUtil;

/**
 * Daemon Plugin for a Filesystem Lock.
 * Only 1 daemon process will be able to start if another has an active lock.
 *
 * Uses a regular file on the local filesystem to maintain a lock.
 */
class FileLock extends AbstractLock
{
    use LogTrait;

    /** @var bool|resource */
    private      $lock    = false;
    private ?int $touched = null;

    protected function getDefaults(): array
    {
        return [
            // absolute path to PID file; defaults to a temp file in the OS /tmp dir using the daemon class name.
            'file'                  => sys_get_temp_dir() . DIRECTORY_SEPARATOR . StringUtil::fqcnToSnake(Daemon::getInstance()) . '.pid',
            // auto create path for PID file
            'create_path'           => true,
            // time-to-live for PID file in seconds.
            // If the lock file is not touched within this threshold it's considered stale.
            'ttl'                   => 0,
            // use flock() to prevent race conditions when acquiring the PID file lock?
            'flock'                 => false,
            // maximum seconds to cache PID from storage medium
            'pid_refresh_frequency' => 5,
            // minimum seconds to wait before updating the PID during the event loop
            'pid_update_frequency'  => 5,
        ];
    }

    private function flock()
    {
        if (!$this['flock']) {
            return;
        }

        $this->lock = fopen($this['file'], 'c+');
        if (!flock($this->lock, LOCK_EX | LOCK_NB)) {
            $pid = intval(trim(fread($this->lock, 8)));
            // if there is a pid and it matches our current PID then we are actually the main process and even though
            // we didn't get the lock due to another process checking it at the same time we're still ok.
            if ($pid && $pid != $this->pid) {
                throw new CleanErrorException(sprintf("PID is locked by another process PID=%s FILE=%s", $pid, $this['file']));
            }
            $this->error("Could not obtain lock FILE=%s. Ignoring.", $this['file']);
//            throw new CleanErrorException(sprintf("Could not obtain lock FILE=%s", $this['file']));
        }
    }

    private function funlock()
    {
        if (!$this['flock']) {
            return;
        }
        if (is_resource($this->lock)) {
            @fclose($this->lock);
            $this->lock = null;
        }
    }

    public function acquire()
    {
        $this->flock();

        // load PID from storage medium
        $pid = $this->getPid();

        // another process has the lock
        // todo: If the pid file is maliciously overwritten it'll cause the daemon to throw an exception
        if ($pid && $pid != $this->pid) {
            if ($this->isLockStale() && !$this->isProcessAlive($pid)) {
                $pid = null;
            } else {
                throw new CleanErrorException(sprintf("Process already running with PID=%d FILE=%s TTL=%d",
                    $pid,
                    $this['file'],
                    $this->getTTL()
                ));
            }
        }

        if (!$pid) {
            // no process has the lock (or it was expired above)
            $pid = $this->pid;
            file_put_contents($this['file'], $pid);
        } else {
            // current process has the lock
            if (!$this['pid_update_frequency'] || !$this->touched || microtime(true) > $this->touched + $this['pid_update_frequency']) {
                touch($this['file']);
                $this->touched = time();
            }
        }

        $this->funlock();
    }

    protected function getTTL(): int
    {
        if (!$this['ttl']) {
            return 0;
        }

        $mtime = @filemtime($this['file']);
        return (($mtime ?: time()) + $this['ttl']) - time();
    }

    /**
     * Determines if the PID file is stale
     *
     * @return bool
     */
    public function isLockStale(): bool
    {
        $mtime = @filemtime($this['file']);
        return $this['ttl'] && $mtime && ($mtime + $this['ttl']) < time();
    }

    public function release()
    {
        $pid = $this->getPid();
        if (file_exists($this['file']) && $pid == $this->pid) {
            @unlink($this['file']);
        }
    }

    public function getPid(): ?int
    {
        static $lastTime, $pid;
        if (!$pid || $pid != $this->pid || !$lastTime || microtime(true) > $lastTime + $this['pid_refresh_frequency']) {
            if (@file_exists($this['file'])) {
                // only load up to 8 bytes to avoid malicious files
                $pid = @file_get_contents($this['file'], null, null, 0, 8);
                if ($pid) {
                    $pid = (int)trim($pid);
                    $lastTime = microtime(true);
                    clearstatcache();
                }
            }
        }
        return $pid ?: null;
    }

    protected function verify()
    {
        if (empty($this['file'])) {
            throw new CleanErrorException("Option 'file' is missing");
        }

        $path = dirname($this['file']);
        if (!@is_writable($path)) {
            if (!@file_exists($path) && $this['create_path']) {
                if (!@mkdir($path, 0777, true)) {
                    throw new CleanErrorException("PID file path \"$path\" could not be created");
                }
            } else {
                throw new CleanErrorException("PID file path \"$path\" is not writable or does not exist");
            }
        }

        if (@file_exists($this['file']) && !@is_writable($this['file'])) {
            throw new CleanErrorException("PID file \"{$this['file']}\" is not writable");
        }

        if ($this['ttl'] && $this['pid_update_frequency'] && $this['ttl'] < $this['pid_update_frequency']) {
            throw new CleanErrorException(sprintf("'ttl' (%s) must be > 'pid_update_frequency' (%s)", $this['ttl'], $this['pid_update_frequency']));
        }
    }

}