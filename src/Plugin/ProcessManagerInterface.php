<?php

namespace Lifo\Daemon\Plugin;


use Closure;
use Lifo\Daemon\Process;

interface ProcessManagerInterface
{
    /**
     * Fork the current process. If a callable is provided it will be called and the child process will exit, otherwise
     * TRUE will be returned and the caller will be responsible for doing something before exiting.
     *
     * @param string|null      $group    Optional process group name
     * @param callable|Closure $callable Optional callable to call in child process.
     * @param int|null         $timeout  Optional timeout for process
     *
     * @return Process|bool
     */
    public function fork(?string $group = null, $callable = null, ?int $timeout = null);

    /**
     * Return the total active processes. Optionally filtered on the group name
     *
     * @param string|null $group
     *
     * @return int
     */
    public function count(?string $group = null): int;

    /**
     * Return the {@link Process} matching the PID and optional group name.
     *
     * @param int         $pid
     * @param string|null $group
     *
     * @return Process|null
     */
    public function getProcess(int $pid, ?string $group = null): ?Process;
}