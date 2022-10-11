<?php

use Lifo\Daemon\LogTrait;
use Lifo\Daemon\Task\AbstractTask;

class SimpleTask extends AbstractTask
{
    // give us easy access to the daemon logging routines so we don't have to use Daemon::getInstance()->log
    use LogTrait;

    public function run(): void
    {
        $this->log("Task is running in the background! My PID=%d. I will now sleep for 2 seconds", getmypid());
        sleep(2);
        $this->log("Task is done and will exit now");
    }
}
