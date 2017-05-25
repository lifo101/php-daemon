<?php

use Lifo\Daemon\Daemon;
use Lifo\Daemon\Mediator\Mediator;

class SimpleWorkerDaemon extends Daemon
{

    protected function initialize()
    {
        // passed in the FQCN of the worker class and provided an alias name to be used within the execute loop.
        // the alias for this worker defaults to the snake_case of the class name with "Worker" stripped off. We provide
        // it here anyway for example purposes only.
        $this->addWorker('SimpleWorker', 'example')
            // enable auto-restarts of workers, to show them exit and be re-created on-the-fly
            ->setAutoRestart(true)
            // how many calls a worker will process before exiting
            ->setMaxCalls(10)
            // how long a worker will run before exiting
            ->setMaxRuntime(10)
            // how many workers to fork
            ->setMaxProcesses(2)
            // optional ON_RETURN event for when a worker returns a result. Not needed if you use the Promise result
            // from worker method calls. See the examples below.
            ->onReturn(function ($value) {
                $this->log("Worker returned $value via ON_RETURN callback");
            })//
        ;
        $this->log("Randomly calls a method on a worker every few iterations. The return value is then shown in the parent process.");
    }

    /**
     * Main application logic goes here. Called every loop cycle.
     */
    protected function execute()
    {
        // show that the loop is running independently
        $this->log("Loop %d", $this->getLoopIterations());

        // randomly call a worker method
        if (mt_rand(1, 3) == 1 || $this->getLoopIterations() % 5 == 0) {
            // get a reference to the worker via its alias that was defined in the initialize() method above.
            /** @var SimpleWorker|Mediator $worker */
            $worker = $this->worker('example');

            /*
             * Option 1:
             * Call the worker method and act on the returned Promise.
             */
            $worker->randomString()->then(function ($value) {
                $this->log("Worker returned %s via Promise", $value);
            });

            /*
             * Option 2:
             * Call the worker method and allow the ON_RETURN callback registered above to handle the response.
             */
//            $worker->randomString();

            /*
             * Inline Option:
             * If you want to call a worker method within the parent process (and not a background child process)
             * you can use the "inline" method of the worker to get access to the actual worker class.
             * Doing so will obviously block until the method returns. The return value will be that of the method
             * and NOT a "Promise" like the example above.
             *
             */
//            /** @var string $result */
//            $this->log("Worker returned %s via inline", $worker->inline()->randomString());
        }
    }
}

