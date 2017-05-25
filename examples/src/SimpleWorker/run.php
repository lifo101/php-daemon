<?php

$META = [
    'description' => "Shows how to call a simple worker that is automatically forked into the background."
];

return function () {
    exampleHeader("
        This example will call a worker process every few iterations. The method call to the worker is handled in a 
        background process. The returned value from the worker is returned to the parent process via a 'Promise' or
        'onReturn' callback.
        
        You will see a lot of debugging information when worker processes are automatically started, exit and restarted.
        The parent daemon process is unaware of the behind-the-scenes processes and just assumes the worker method 
        calls are simple methods.
    ");

    SimpleWorkerDaemon::getInstance()
        ->setVerbose(true)
        ->setDebug(true)
        ->setDebugLevel(3)
        ->setLogFile(__DIR__ . '/daemon.log')
        ->run();
};