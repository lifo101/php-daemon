<?php

use Lifo\Daemon\Daemon;

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

            // Quick way to run a task. Pass the FQCN of the class to instantiate
            $this->task('SimpleTask');
            // or use the class instance directly
//            $this->task(new SimpleTask);

            // Alternate method to create a task.
            // Here, the callable below is actually run in a background process.
//            $this->task(function () use ($num) {
//                $this->log("Task %d is running in the background! My PID=%d. I will now sleep for 2 seconds", $num, $this->getPid(), $this->getParentPid());
//                sleep(2);
//                $this->log("Task %d is done and will exit now", $num);
//            });
        }
    }
}
