<?php

namespace Lifo\Daemon\Plugin;


use Lifo\Daemon\Process;

interface ProcessManagerInterface
{
    /**
     * Fork the current process. If a callable is provided it will be called and the child process will exit, otherwise
     * TRUE will be returned and the caller will be responsible for doing something before exiting.
     *
     * @param string            $group    Optional process group name
     * @param callable|\Closure $callable Optional callable to call in child process.
     * @param int               $timeout  Optional timeout for process
     * @return Process|bool
     */
    public function fork($group = null, $callable = null, $timeout = null);

    /**
     * Return the total active processes. Optionally filtered on the group name
     *
     * @param string $group
     * @return int
     */
    public function count($group = null);

    /**
     * Return the {@link Process} matching the PID and optional group name.
     *
     * @param int    $pid
     * @param string $group
     * @return Process
     */
    public function getProcess($pid, $group = null);
}