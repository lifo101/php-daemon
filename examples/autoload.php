<?php

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->add('Lifo\Daemon\\', __DIR__ . '/../src');

// add each example path to the auto-loader.
// I wouldn't normally do this in a production app, but for the examples, it's fine.
$dirs = glob(__DIR__ . '/src/*', GLOB_ONLYDIR);
if ($dirs) {
    foreach ($dirs as $dir) {
        $prefix = basename($dir);
        $loader->add($prefix, $dir);
    }
}
//$loader->add('', __DIR__ . '/src');
