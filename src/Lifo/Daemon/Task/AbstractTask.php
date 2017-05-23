<?php

namespace Lifo\Daemon\Task;


/**
 * Abstract Task class to reduce some boilerplate code for simple tasks. Extend this class if the only method you
 * need in your task is {@link TaskInterface::run}.
 */
abstract class AbstractTask implements TaskInterface
{
    protected $group = 'task';

    public function setup()
    {
        // noop
    }

    public function teardown()
    {
        // noop
    }

    public function getGroup()
    {
        return $this->group;
    }
}