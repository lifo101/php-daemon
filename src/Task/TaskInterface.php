<?php

namespace Lifo\Daemon\Task;


interface TaskInterface
{
    /**
     * One-Time setup of the task before it runs.
     */
    public function setup(): void;

    /**
     * Teardown the task. Release all resources created during the tasks lifetime.
     */
    public function teardown(): void;

    /**
     * Run the task.
     */
    public function run(): void;

    /**
     * Process group identifier. Allows the process manager to group different types of background tasks together.
     */
    public function getGroup(): string;
}