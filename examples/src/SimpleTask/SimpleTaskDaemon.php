<?php

use Lifo\Daemon\Daemon;
use Lifo\Daemon\Task\AbstractTask;

class SimpleTaskDaemon extends Daemon
{

    protected function initialize()
    {
        $this->log("On every 5 iterations a task will be run in the background");
    }

    /**
     * Main application logic goes here. Called every loop cycle.
     */
    protected function execute()
    {
        // show that the loop is running independently
        $this->log("Loop %d", $this->getLoopIterations());

        // every 5 iterations run a task
        if ($this->getLoopIterations() % 5 == 0) {
            static $num = 0;
            $num++;

            // Start a task. Returns instantly.
            // In this example the callable below is actually run in a background process.
            $this->task(function () use ($num) {
                // note: we have full and safe access to all Daemon methods
                $this->log("Task %d is running in the background! My PID=%d. I will now sleep for 2 seconds", $num, $this->getPid(), $this->getParentPid());
                sleep(2);
                $this->log("Task %d is done and will exit now", $num);
            });

            // alternate way to run a task. Pass the FQCN of the class to instantiate. See the SimpleTask class below.
//            $this->task('SimpleTask');
//            $this->task(new SimpleTask); // or use the class instance directly
        }
    }
}

class SimpleTask extends AbstractTask
{
    // give us easy access to the daemon logging routines so we don't have to use Daemon::getInstance()->log
    use \Lifo\Daemon\LogTrait;

    public function run()
    {
        $this->log("Task is running in the background! My PID=%d. I will now sleep for 2 seconds", Daemon::getInstance()->getPid());
        sleep(2);
        $this->log("Task is done and will exit now");
    }
}