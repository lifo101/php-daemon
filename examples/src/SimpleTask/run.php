<?php

$META = [
    'description' => "Shows how to call a simple task that is automatically forked into the background."
];

return function () {
    SimpleWorkerDaemon::getInstance()
        ->setVerbose(true)
        ->setDebug(true)
        ->setLogFile(__DIR__ . '/daemon.log')
        ->run();
};