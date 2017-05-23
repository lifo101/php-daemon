<?php

namespace Lifo\Daemon;


/**
 * Trait that allows classes to create some common exceptions.
 *
 */
trait ExceptionsTrait
{
    /**
     * Creates an {@link \InvalidArgumentException} with a backtrace that references the actual caller from user code.
     *
     * @param array  $args  Function arguments to serialize into the exception message for readability.
     * @param string $label Message prefix
     * @param int    $limit Stack trace limit
     * @return \InvalidArgumentException
     */
    protected static function createInvalidArgumentException(array $args = null, $label = 'Invalid argument provided', $limit = 2)
    {
        $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
        $t = end($t);
        if ($t) {
            return new \InvalidArgumentException(sprintf("%s in function %s%s%s(%s) in file %s on line %s",
                $label,
                $t['class'],
                $t['type'],
                $t['function'],
                self::serializeArguments($args),
                $t['file'],
                $t['line']
            ));
        }
        return new \InvalidArgumentException($label);
    }

    /**
     * Creates an {@link \OutOfBoundsException} with a backtrace that references the actual caller from user code.
     *
     * @param        $value
     * @param string $label
     * @return \OutOfBoundsException
     */
    protected static function createOutOfBoundsException($value, $label = 'Invalid value')
    {
        $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $t = end($t);
        if ($t) {
            return new \OutOfBoundsException(sprintf("%s to %s%s%s(%s) in file %s on line %s",
                $label,
                $t['class'],
                $t['type'],
                $t['function'],
                self::serializeArguments([$value]),
                $t['file'],
                $t['line']
            ));
        }
        return new \OutOfBoundsException($label);
    }

    protected static function serializeArguments($args, $limit = 32)
    {
        $list = [];
        if ($args) {
            $i = 0;
            foreach ($args as $a) {
                $i++;
                switch (true) {
                    case $a === null:
                        $list[] = "NULL";
                        break;
                    case is_numeric($a):
                        $list[] = $a;
                        break;
                    case is_object($a) && method_exists($a, '__toString'):
                    case is_string($a):
                        $list[] = '"' . (strlen($a) > $limit ? substr($a, 0, $limit) . '...' : $a) . '"';
                        break;
                    case $a instanceof \DateTime:
                        $list[] = sprintf('"%s"', $a->format(DATE_RFC3339));
                        break;
                    case is_callable($a):
                        $list[] = '{callable}';
                        break;
                    case $a !== null && is_resource($a):
                        $list[] = '{' . get_resource_type($a) . '}';
                        break;
                    default:
                        $list[] = "{arg$i}";
                }
            }
        }
        return $list ? implode(', ', $list) : '';
    }

}