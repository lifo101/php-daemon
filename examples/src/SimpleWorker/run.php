<?php

$META = [
    'description' => "Shows how to call a simple worker that is automatically forked into the background."
];

return function () {
    SimpleWorkerDaemon::getInstance()
        ->setVerbose(true)
        ->setDebug(true)
        ->setDebugLevel(3)
        ->setLogFile(__DIR__ . '/daemon.log')
        ->run();
};