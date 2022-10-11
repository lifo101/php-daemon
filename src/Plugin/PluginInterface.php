<?php

namespace Lifo\Daemon\Plugin;


interface PluginInterface
{
    /**
     * Initial setup of plugin. Perform all one-time setup steps needed for the plugin.
     *
     * @param array $options Array of custom options
     */
    public function setup(array $options = []): void;

    /**
     * Teardown the plugin. Release all resources created during the plugins lifetime.
     */
    public function teardown(): void;
}