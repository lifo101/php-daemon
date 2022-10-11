<?php
use Lifo\Daemon\Daemon;
use Lifo\Daemon\Event\DaemonEvent;
use Lifo\Daemon\LogTrait;
use Lifo\Daemon\Plugin\AbstractPlugin;
use Lifo\Daemon\StringUtil;

/**
 * This simple plugin shows an example of how to create a plugin that can inject itself into the Daemon event cycle.
 *
 * This plugin will dump some stats every X iterations of the daemon loop.
 */
class MemoryPlugin extends AbstractPlugin
{
    use LogTrait;

    protected function getDefaults(): array
    {
        return [
            // how often to dump stats
            'interval' => 3
        ];
    }

    public function setup(array $options = []): void
    {
        static $last = 0;
        // not the proper place to put this, but it'll do for this example
        $initialMemory = memory_get_usage();

        parent::setup($options);

        $daemon = Daemon::getInstance();

        // setup callback for IDLE events
        $daemon->on(DaemonEvent::ON_IDLE, function () use ($daemon, $initialMemory, $options, &$last) {
            if (!$last || time() - $last >= $options['interval']) {
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