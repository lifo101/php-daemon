#!/usr/bin/env php
<?php
/**
 * Run an example or list available examples if no example name is provided.
 */

declare(ticks = 1); // needed for the daemon signal handling

/** @var \Composer\Autoload\ClassLoader $loader */
// if php-daemon is installed on its own (not included in another composer.json)
$loader = @include __DIR__ . '/../vendor/autoload.php';
if (!$loader) {
    // if php-daemon is installed inside another composer.json
    $loader = @include __DIR__ . '/../../../autoload.php';
    if (!$loader) {
        exampleHeader("Error: You must initialize your composer in the root of your application before running any examples: composer install", false);
        exit(1);
    }
} else {
    // must add the Lifo\Daemon prefix manually
    $loader->add('Lifo\\Daemon', __DIR__ . '/../src');
}

if (empty($argv[1])) {
    listExamples();
    exit;
}

$name = basename($argv[1]);
$script = getScript($name);
if (!$script) {
    print "Example does not exist: \"$name\"\n";
    listExamples();
    exit(1);
}

// run the example
echo ">> Running example: \"$name\" (Press ^C to exit)\n";
// add the directory of the example we're running to the auto-loader
$loader->add('', dirname($script));
$run = require $script;
if (is_callable($run)) {
    $run();
}

function listExamples()
{
    $names = [];
    $files = glob(__DIR__ . '/src/*');
    if ($files) {
        foreach ($files as $file) {
            $info = pathinfo($file);
            unset($META);
            require getScript($info['filename']);
            $names[$info['filename']] = isset($META) ? $META : '';
        }
        ksort($names);
    } else {
        print "No examples found?\n";
        return;
    }

    $width = (getScreenSize(true) ?: 80) - 9; // account for {tab} and {nl}
    $cmd = './' . basename($GLOBALS['argv'][0]);
    print "Usage: $cmd ExampleName\n\nAvailable Examples:\n===================\n";
    foreach ($names as $name => $meta) {
        print "$name\n";
        if (isset($meta['description'])) {
            $desc = implode("\n", array_map(function ($s) { return "\t" . trim($s); }, explode("\n", wordwrap($meta['description'], $width))));
            print "$desc\n";
        }
    }
    print "\n";
}

function getScript($name)
{
    $path = __DIR__ . "/src/$name";
    switch (true) {
        case file_exists($script = $path . '/run.php'):
            break;
        case file_exists($script = $path . '.php'):
            break;
        default:
            $script = null;
    }
    return $script;
}

/**
 * Output some header text. The text is word-wrapped and optionally wrapped in a border. To be used before an example
 * is run.
 *
 * @param string $msg     Message to display.
 * @param bool   $wrapper If true, an upper/lower border is added to the message.
 */
function exampleHeader($msg, $wrapper = true)
{
    $width = min(getScreenSize(true) ?: 80, 132) - 1;
    $paragraphs = array_map('trim', preg_split('/(\r?\n\s*){2,}/', $msg));

    if ($wrapper) {
        echo "\n";
        echo str_repeat('=', $width), "\n";
    }

    foreach ($paragraphs as $idx => $text) {
        if ($idx != 0) {
            echo "\n";
        }
        $text = wordwrap(trim(implode(" ", array_map('trim', explode("\n", $text)))), $width);
        echo $text, "\n";
    }

    if ($wrapper) {
        echo str_repeat('=', $width), "\n";
        echo "\n";
    }
}

/**
 * Return the console screen size. Returns an array of [width, height], or just width if $widthOnly is true.
 *
 * @param bool $widthOnly If true, only the width is returned.
 * @return array|int|null
 */
function getScreenSize($widthOnly = false)
{
    // non-portable way to get screen size. just for giggles...
    $output = [];
    preg_match_all("/rows.([0-9]+);.columns.([0-9]+);/", strtolower(exec('stty -a |grep columns')), $output);
    if (count($output) == 3) {
        if ($widthOnly) {
            return (int)$output[2][0];
        } else {
            return array_map('intval', [$output[2][0], $output[1][0]]);
        }
    }
    return null;
}
