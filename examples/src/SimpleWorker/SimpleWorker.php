<?php

use Lifo\Daemon\LogTrait;
use Lifo\Daemon\Promise;

/**
 * If you implement WorkerInterface than the initialize method will be called after the worker is forked.
 */
class SimpleWorker // optional: implements \Lifo\Daemon\Worker\WorkerInterface
{
    use LogTrait;

    /**
     * Return a 'random' string (not really)
     *
     * Called from the daemon execute loop and is normally processed within a forked child.
     *
     * @return string|Promise
     */
    public function randomString()
    {
        $this->debug(__FUNCTION__ . "() called");
        usleep(100000 * mt_rand(1, 5)); // fake latency
        return md5(microtime(true)); // not really a random string; for brevity
    }
}
