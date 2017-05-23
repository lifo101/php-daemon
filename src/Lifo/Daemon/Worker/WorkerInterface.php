<?php

namespace Lifo\Daemon\Worker;


/**
 * Daemon Workers can optionally implement this interface to provide extra functionality.
 *
 * <br/>
 * <b>Implementing this interface is optional.</b>
 */
interface WorkerInterface
{
    /**
     * Initial setup of worker. Perform any initialization required for the worker. This will be called from within
     * the CHILD context after the worker is forked. One common need for this is to set the process title.
     *
     * @return void
     */
    public function initialize();
}