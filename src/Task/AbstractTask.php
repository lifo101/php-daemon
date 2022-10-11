<?php

namespace Lifo\Daemon\Task;


/**
 * Abstract Task class to reduce some boilerplate code for simple tasks. Extend this class if the only method you
 * need in your task is {@link TaskInterface::run}.
 */
abstract class AbstractTask implements TaskInterface
{
    public function setup(): void
    {
        // noop
    }

    public function teardown(): void
    {
        // noop
    }

    public function getGroup(): string
    {
        return 'task';
    }
}