<?php

namespace Lifo\Daemon;

/**
 * Trait that allows classes to easily add custom daemon logging to their class instance. Useful for Workers or Tasks
 * that want to use the native {@link Daemon} logging.
 */
trait LogTrait
{
    /**
     * Modify the arguments before being sent to the daemon logging routines. See:
     * * {@link Daemon::log}
     * * {@link Daemon::error}
     * * {@link Daemon::debug} -- <i>The first argument can optionally be an integer instead of a message string.</i>
     *
     *
     * @see Daemon::log
     * @see Daemon::error
     * @see Daemon::debug
     * @param array  $args
     * @param string $type Type of message being logged ('log', 'error', 'debug')
     * @return array
     */
    protected function setLogArguments($args, $type = 'log')
    {
        // get reference; if 1st param is int then msg is actually 2nd param
        $msg = &$args[$type == 'debug' && is_int($args[0]) ? 1 : 0];
        if ($msg !== null && !$msg instanceof \Exception) {
            // example: prefix message with the class base name. Account for debug messages or Exceptions
            if ($type == 'error') {
                $msg = 'Error: ' . $msg;
            }
            $msg = sprintf("%s: %s", StringUtil::baseClassName($this), $msg);
        }
        return $args;
    }

    /**
     * Write a message to the log. Will also output to STDERR if {@link verbose} is true.
     *
     * The sub-class may override this to provide its own functionality or plugins may intercept this behavior by
     * listening on the {@link DaemonEvent::ON_LOG} event. If propagation of the event is stopped then the log message
     * will not be handled directly by this method.
     *
     * @param string $msg
     * @param mixed  $varargs Extra arguments (arg1, arg2, etc) to pass to {@link sprintf}
     *
     */
    protected function log($msg, $varargs = null)
    {
        call_user_func_array([Daemon::getInstance(), 'log'], $this->setLogArguments(func_get_args(), 'log'));
    }

    /**
     * Log runtime error and dispatch event.
     *
     * @param string|\Exception $err
     * @param mixed             $varargs Extra arguments (arg1, arg2, etc) to pass to {@link sprintf}
     */
    protected function error($err, $varargs = null)
    {
        call_user_func_array([Daemon::getInstance(), 'error'], $this->setLogArguments(func_get_args(), 'error'));
    }

    /**
     * Output a debug message if {@link $debug} is true and optionally if {@link $debugLevel} is higher than
     * the specified $level.
     *
     * @param int|string $level   Optional debug level (int) or message (string)
     * @param string     $msg     Message string if $level is not specified
     * @param mixed      $varargs Extra arguments (arg1, arg2, etc) to pass to {@link sprintf}
     */
    protected function debug($level, $msg = null, $varargs = null)
    {
        call_user_func_array([Daemon::getInstance(), 'debug'], $this->setLogArguments(func_get_args(), 'debug'));
    }
}