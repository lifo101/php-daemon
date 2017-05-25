<?php

$META = [
    'description' => "Shows how to call a simple task that is automatically forked into the background."
];

return function () {
    exampleHeader("
        This example will start a task every 5 loop iterations. 
        The task will perform it's action and then exit. 
        Tasks never communicate with the parent process. Tasks are meant for fire-and-forget processes. The parent
        won't really care what happens with the task after it's fired.
        Use a 'Worker' instead, if you need to actually return a result.
    ");

    SimpleTaskDaemon::getInstance()
        ->setVerbose(true)
        ->setDebug(true)
        ->setLogFile(__DIR__ . '/daemon.log')
        ->run();
};