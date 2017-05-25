<?php

use Lifo\Daemon\Daemon;

class HelloWorldDaemon extends Daemon
{
    /**
     * Main application logic goes here. Called every loop cycle.
     */
    protected function execute()
    {
        $this->log("Hello World. Loop %d", $this->getLoopIterations());

        if ($this->getLoopIterations() == 10) {
            $this->log("Why are you still looking at this? Nothing interesting is going to happen here");
        }
    }
}