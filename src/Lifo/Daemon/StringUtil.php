<?php

namespace Lifo\Daemon;


use DateInterval;
use DateTime;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

abstract class StringUtil
{
    use ExceptionsTrait;

    /**
     * Get the calculated interval parts to be used within the {@link elapsed} functions.
     *
     * @param DateInterval $interval
     * @param array        $format
     * @return array
     */
    public static function getIntervalParts(DateInterval $interval, $format = null)
    {
        if (empty($format)) {
            $format = [
                'y' => '%y year',
                'm' => '%m month',
                'w' => '%w week',
                'd' => '%d day',
                'h' => '%h hour',
                'i' => '%i minute',
                's' => '%s second',
            ];
        }

        $fmt = [];
        foreach (['y', 'm', 'd', 'h', 'i', 's'] as $key) {
            $value = $interval->$key;
            if ($key == 'd') {
                if ($value) {
                    if ($value >= 7 && isset($format['w'])) {
                        $weeks = floor($value / 7);
                        $value = ceil($value % 7);
                        $interval->d = $value; // update interval
                        $fmt['w'] = str_replace('%w', $weeks, $format['w']);
                    }
                    if ($value) {
                        $fmt[$key] = str_replace('%' . $key, $value, $format[$key]);
                    }
                }
            } else {
                // always include the time parts
                if (($value || in_array($key, ['h', 'i', 's'])) && isset($format[$key])) {
                    $fmt[$key] = str_replace('%' . $key, $value, $format[$key]);
                }
            }
        }

        return $fmt;
    }

    /**
     * Converts the date interval into a true {@link DateInterval} instance.
     *
     * @param DateInterval|DateTime|string $interval If DateTime, an interval is created using 'now'
     * @return DateInterval
     */
    public static function getInterval($interval)
    {
        switch (true) {
            case $interval instanceof DateInterval:
                return $interval;
            case is_string($interval):
                $dt = date_create($interval);
                return $dt->diff(new DateTime('now'));
                break;
            case $interval instanceof DateTime:
                return $interval->diff(new DateTime('now'));
                break;
            default:
                throw self::createInvalidArgumentException(func_get_args(), "Invalid interval argument #1. Must be a DateInterval|DateTime|string");
        }
    }

    /**
     * Return a human readable string representing the time interval. E.G.: '1 day 8 hours 23 minutes'
     *
     * @param DateInterval|DateTime|string $interval If DateTime, an interval is created using 'now'
     * @param int                          $parts
     * @param array                        $format   Map for DateTime->format() parts; ['y' => '%y year'], etc...
     * @param bool                         $plural   If true, attempt to pluralize strings (english only). Note: Not very smart
     * @return string Human readable string of time interval
     */
    public static function elapsed($interval, $parts = 3, $format = null, $plural = true)
    {
        $interval = self::getInterval($interval);
        $fmt = self::getIntervalParts($interval, $format);
        $fmt = array_slice($fmt, 0, $parts);
        if ($plural) {
            foreach ($fmt as &$v) {
                if (intval($v) != 1 && preg_match('/(?:year|month|day|hour|minute|second)$/', $v)) {
                    $v .= 's';
                }
            }
        }
        $fmt = implode(', ', $fmt);
        return $fmt;
    }

    /**
     * Return a short human readable string representing the time interval. E.G.: '1d8h23m'
     *
     * @param DateInterval|DateTime|string $interval If DateTime, an interval is created using 'now'
     * @param int                          $parts
     * @param array                        $format   Map for DateTime->format() parts; ['y' => '%y year'], etc...
     * @return string Human readable string of time interval
     */
    public static function elapsedShort($interval, $parts = 3, $format = null)
    {
        $interval = self::getInterval($interval);
        $fmt = self::getIntervalParts($interval, array_replace([
            'y' => '%yy',
            'm' => '%mm',
            'w' => '%ww',
            'd' => '%dd',
            'h' => '%hh',
            'i' => '%im',
            's' => '%ss',
        ], (array)$format));
        $fmt = implode('', array_slice(array_filter($fmt, function ($s) { return intval($s) != 0; }), 0, $parts));
        return $fmt;
    }

    /**
     * Shortcut for {@link elapsedShort}($seconds . ' sec')
     *
     * @param int  $seconds
     * @param bool $noSeconds Strip off seconds; unless the total time is < 60 seconds
     * @return string
     */
    public static function elapsedFromSeconds($seconds, $noSeconds = true)
    {
        $time = self::elapsedShort(((int)$seconds) . ' sec');
        if ($noSeconds && $seconds >= 60) {
            // not exactly the way to do this, but works for now.
            $str = preg_replace('/\d+\s*(?:s(?:econds?)?)$/', '', $time);
            if ($str) {
                $time = $str;
            }
        }
        return $time;
    }

    /**
     * Format an integer into a human readable Bytes, KB, MB, GB, TB string.
     *
     * @param int   $num       Number to format
     * @param int   $precision Precision of floating point number. Defaults to 2.
     * @param array $suffix    Optional array of suffixes. Default: [B, KB, MB, GB, TB]
     * @return string
     */
    public static function kbytes($num, $precision = 2, $suffix = null)
    {
        static $_suffix = null;
        if (is_array($suffix) && $suffix) {
            $_suffix = $suffix;
        }
        if (empty($_suffix)) {
            $_suffix = [' B', ' KB', ' MB', ' GB', ' TB'];
        }

        if (!is_numeric($precision)) $precision = 2;
        $i = 0;

        $negative = $num < 0;
        $num = abs($num);
        if (!$num) return '0' . $_suffix[0];
        while ($num >= 1024 and ($i < count($_suffix))) {
            $num /= 1024;
            $i++;
        }
        return sprintf("%s%." . $precision . "f", $negative ? '-' : '', $num) . $_suffix[$i];
    }

    /**
     * Converts a fully-qualified class name to snake_case.
     *
     * Borrowed from Symfony\Component\Form\Util\StringUtil
     *
     * @param string|object $fqcn   The fully-qualified class name
     *
     * @param string        $suffix Optional string regex suffix to strip off. EG: fqcnToSnake('SimpleClass', 'class') == 'simple'
     * @return null|string The snake_case string or null if not a valid FQCN
     */
    public static function fqcnToSnake($fqcn, $suffix = null)
    {
        if (is_object($fqcn)) {
            $fqcn = get_class($fqcn);
        }
        if (preg_match('~([^\\\\]+?)' . ($suffix ? "($suffix)?" : '') . '$~i', $fqcn, $matches)) {
            return strtolower(preg_replace(array('/([A-Z]+)([A-Z][a-z])/', '/([a-z\d])([A-Z])/'), array('\\1_\\2', '\\1_\\2'), $matches[1]));
        }

        return null;
    }

    /**
     * Return the base name of a fully-qualified class name.
     *
     * Example: baseClassName('\Lifo\Something\Blah\MyClass') == 'MyClass'
     *
     * @param string|object $fqcn The fully-qualified class name
     * @return string
     */
    public static function baseClassName($fqcn)
    {
        return basename(str_replace('\\', '/', is_object($fqcn) ? get_class($fqcn) : $fqcn));
    }

    /**
     * Return a string representing the variable. Will use the Symfony VarDump, if available. Otherwise a simple
     * JSON object will be returned.
     *
     * @param mixed $var   Variable to dump
     * @param bool  $color If true, use ANSI color output (if VarDumper is used)
     * @return string String representation of variable
     */
    public static function dump($var, $color = true)
    {
        if (class_exists('\Symfony\Component\VarDumper\VarDumper')) {
            $dumper = 'cli' === PHP_SAPI ? new CliDumper() : new HtmlDumper();
            $clone = new VarCloner();
            $handle = fopen('php://memory', 'r+b');

            $dumper->setColors($color);
            $dumper->dump($clone->cloneVar($var), $handle);
            $output = stream_get_contents($handle, -1, 0);
            fclose($handle);
            return $output;
        }

        return json_encode($var, JSON_PRETTY_PRINT);
    }
}