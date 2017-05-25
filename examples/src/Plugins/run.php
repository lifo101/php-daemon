<?php

$META = [
    'description' => "Shows how to use plugins within a Daemon."
];

return function () {
    exampleHeader("
        This example loads two plugins: 
        
        'FileLock' is a core plugin included with the Daemon library. It prevents multiple instances from running 
        at the same time. Run this example a second time in another window to see this in action. 
        
        'MemoryPlugin' is a custom plugin that shows how to create your own plugin. It will report memory usage every 
        few loop iterations. The daemon will be creating a bunch of objects in order to see the memory grow rapidly 
        (just for example purposes).
    ");

    PluginsDaemon::getInstance()
        ->setVerbose(true)
        ->setDebug(true)
        ->setDebugLevel(3)
        ->setLogFile(__DIR__ . '/daemon.log')
        ->run();
};