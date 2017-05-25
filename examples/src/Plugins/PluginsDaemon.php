<?php

use Lifo\Daemon\Daemon;
use Lifo\Daemon\Event\DaemonEvent;
use Lifo\Daemon\LogTrait;
use Lifo\Daemon\Plugin\AbstractPlugin;
use Lifo\Daemon\StringUtil;

class PluginsDaemon extends Daemon
{

    private $trash = []; // trash variable to take up memory for example

    protected function initialize()
    {

        // add a FileLock plugin. This is a core daemon plugin that provides a locking mechanism to prevent more than
        // 1 instance of a daemon from running at the same time. You can pass in the FQCN or class instance.
        // In this example, the file lock has a TTL (Time to Live) of 10 seconds, meaning if the lock isn't updated
        // within 10 seconds it's considered stale.
        //
        // this plugin doesn't have any public methods that you can call. Once it's added to the daemon it
        // automatically works w/o any user code.
        $this->addPlugin('Lifo\Daemon\Plugin\Lock\FileLock', 'lock', [
            'ttl'  => 10,
            'file' => __DIR__ . '/daemon.pid',
        ]);

        // add our example plugin to the daemon.
        $this->addPlugin('MemoryPlugin', [
            'interval' => 5,
        ]);

        $this->log("Try to run another copy of this daemon in another window and watch what happens");
    }

    /**
     * Main application logic goes here. Called every loop cycle.
     */
    protected function execute()
    {
        // show that the loop is running independently
        $this->log("Loop %d (%d trash objects stored)", $this->getLoopIterations(), count($this->trash));

        // take up memory on each iteration; so you can see the 'growth' in memory from the MyPlugin output
        for ($i=0; $i<25; $i++) {
            $this->trash[] = new DateTime();
        }

        // Dump the plugin stats once
//        if ($this->getLoopIterations() == 1) {
//            $stats = $this->stats();
//            $this->log("Lock plugin stats: ");
//            $this->dump($stats['plugins']['lock']);
//        }
    }
}
