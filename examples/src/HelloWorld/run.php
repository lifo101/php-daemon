<?php

$META = [
    'description' => "Shows the minimal steps to run a daemon. No frills, no plugins."
];

return function () {
    // get the daemon instance and configure it. Below are some common configuration settings
    $daemon = HelloWorldDaemon::getInstance()
        // loop once per second; can be fractional, or even 0 (defaults to 1)
        ->setLoopInterval(1)
        // echo log/debug info to console (except if "daemonized" into the background)
        ->setVerbose(true)
        // log extra debugging info
        ->setDebug(true)
        // where to send logs to
        ->setLogFile(__DIR__ . '/daemon.log');

    // start the daemon. This never returns until the daemon loop is stopped.
    // for this example we're not running in the background, so this blocks until the loop stops.
    $daemon->run();
};