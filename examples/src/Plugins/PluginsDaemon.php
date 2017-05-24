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
        $this->addPlugin('MyPlugin', 'my_plugin', [
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
        $this->log("Loop %d", $this->getLoopIterations());

        // take up memory on each iteration; so you can see the 'growth' in memory from the MyPlugin output
        for ($i=0; $i<25; $i++) {
            $this->trash[] = new DateTime();
        }

        // Dump the plugin stats once
        if ($this->getLoopIterations() == 1) {
            $stats = $this->stats();
            $this->log("Lock plugin stats: ");
            $this->dump($stats['plugins']['lock']);
        }
    }
}

/**
 * This simple plugin shows an example of how to create a plugin that can inject itself into the Daemon event cycle.
 *
 * This plugin will dump some stats every X iterations of the daemon loop.
 */
class MyPlugin extends AbstractPlugin
{
    use LogTrait;

    protected function getDefaults()
    {
        return [
            // how often to dump stats
            'interval' => 3
        ];
    }

    public function setup($options = [])
    {
        static $last = 0;
        // not the proper place to put this, but it'll do for this example
        $initialMemory = memory_get_usage();

        parent::setup($options);

        $daemon = PluginsDaemon::getInstance();

        // setup callback for IDLE events
        $daemon->on(DaemonEvent::ON_IDLE, function () use ($daemon, $initialMemory, &$last) {
            if (!$last || time() - $last >= 5) {
                $last = time();

                $suffix = ['b', 'k', 'm', 'g', 't'];
                $this->debug(3, "Runtime: %s | Memory: Usage=%s, Peak=%s, Growth=%s",
                    StringUtil::elapsedFromSeconds($daemon->getRuntime()) ?: '0s',
                    StringUtil::kbytes(memory_get_usage(), 2, $suffix),
                    StringUtil::kbytes(memory_get_peak_usage(), 2, $suffix),
                    StringUtil::kbytes(memory_get_usage() - $initialMemory, 2, $suffix)
                );
            }
        });
    }
}