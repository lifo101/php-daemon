<?php

namespace Lifo\Daemon\Worker;


use Lifo\Daemon\Daemon;
use Lifo\Daemon\ExceptionsTrait;

/**
 * Example of a simple worker that can be passed to {@link Daemon::addWorker}
 */
class SimpleWorker
{
    use ExceptionsTrait;

    public function rand($num)
    {
//        usleep(500000 * mt_rand(1,4));
        return mt_rand(1,$num);
//        Daemon::getInstance()->log("Worker is doing something! %s", self::serializeArguments(func_get_args()));
    }
}
