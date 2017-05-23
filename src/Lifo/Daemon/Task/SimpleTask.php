<?php

namespace Lifo\Daemon\Task;


use Lifo\Daemon\Daemon;

/**
 * Example of a simple task that can be passed to {@link Daemon::task}
 */
class SimpleTask extends AbstractTask
{
    public function run()
    {
        $daemon = Daemon::getInstance();
        $daemon->log("TASK STARTED " . getmypid());
        sleep(mt_rand(1, 3));
        $daemon->log("TASK ENDING " . getmypid());
    }
}