<?php
use Lifo\Daemon\Daemon;
use Lifo\Daemon\Event\DaemonEvent;
use Lifo\Daemon\Event\StatsEvent;
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

        parent::setup($options);

        $daemon = Daemon::getInstance();

        // setup callback for STATS events; only needed if you want to add extra stats
        $daemon->on(DaemonEvent::ON_STATS, function(StatsEvent $event) {
            $stats = $event->getStats();
            $stats['example_stat'] = microtime(true);
            $event->setStats($stats);
        });

        // setup callback for IDLE events
        $daemon->on(DaemonEvent::ON_IDLE, function () use ($daemon, $options, &$last) {
            if (!$last || time() - $last >= $options['interval']) {
                $last = time();

                // triggers the DaemonEvent::ON_STATS callback above
                $stats = $daemon->stats();

                $this->debug(3, "Runtime: %s | Memory: Usage=%s, Peak=%s, Growth=%s, MyStat=%s",
                    $stats['runtime_elapsed'],
                    $stats['memory']['current_kb'],
                    $stats['memory']['peak_kb'],
                    $stats['memory']['growth_kb'],
                    // show our custom stat from the ON_STATS event above
                    $stats['example_stat'] ?? '?'
                );
//                dump($stats); // for testing purposes
            }
        });
    }
}