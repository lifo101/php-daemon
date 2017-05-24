<?php

$META = [
    'description' => "Shows how to use plugins within a Daemon."
];

return function () {
    PluginsDaemon::getInstance()
        ->setVerbose(true)
        ->setDebug(true)
        ->setDebugLevel(3)
        ->setLogFile(__DIR__ . '/daemon.log')
        ->run();
};