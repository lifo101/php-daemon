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
    }
}