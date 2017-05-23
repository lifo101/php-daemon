<?php

namespace Lifo\Daemon\Task;


interface TaskInterface
{
    /**
     * One-Time setup of the task before it runs.
     *
     * @return void
     */
    public function setup();

    /**
     * Teardown the task. Release all resources created during the tasks lifetime.
     *
     * @return void
     */
    public function teardown();

    /**
     * Run the task.
     *
     * @return void
     */
    public function run();

    /**
     * Process group identifier. Allows the process manager to group different types of background tasks together.
     *
     * @return string
     */
    public function getGroup();
}