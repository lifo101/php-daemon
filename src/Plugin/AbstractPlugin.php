<?php

namespace Lifo\Daemon\Plugin;


use ArrayAccess;
use Exception;
use Lifo\Daemon\Daemon;
use Lifo\Daemon\Event\DaemonEvent;
use Lifo\Daemon\Event\StatsEvent;
use Lifo\Daemon\OptionsTrait;

/**
 * Basic Abstract Plugin class that adds some extra magic functionality to make plugins easier to code.
 * * Options can be accessed via normal array access $this['option_name'].
 * * Plugin information will automatically be added to any STATS dump. Override {@link onStats} to change behavior.
 */
abstract class AbstractPlugin implements PluginInterface, ArrayAccess
{
    use OptionsTrait;

    public function setup(array $options = []): void
    {
        $this->configureOptions($options, $this->getDefaults());
        $this->verify();

        Daemon::getInstance()->on(DaemonEvent::ON_STATS, function (StatsEvent $e) {
            $stats = $this->onStats($e->getStats(), $e->getDaemon()->getPluginAlias($this) ?: get_class($this));
            if ($stats) {
                $e->setStats($stats);
            }
        });
    }

    public function teardown(): void
    {
    }

    /**
     * Intercept "Stats" event to add our own plugin information.
     *
     * @param array  $stats The full array of known stats
     * @param string $alias Plugin alias as known by the {@link Daemon}
     *
     * @return array Updated array of stats
     */
    protected function onStats(array $stats, string $alias): array
    {
        $stats['plugins'][$alias] = [
            'class'   => get_class($this),
            'options' => $this->options,
        ];
        return $stats;
    }

    /**
     * Return the default options for the plugin.
     *
     * @return array
     */
    protected function getDefaults(): array
    {
        return [];
    }

    /**
     * Verify the plugin environment/configuration during setup. Throw an exception if something is wrong.
     *
     * @throws Exception
     */
    protected function verify()
    {
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->options[$offset]);
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->options[$offset];
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        $this->options[$offset] = $value;
    }

    /**
     * Implements \ArrayAccess
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->options[$offset]);
    }
}