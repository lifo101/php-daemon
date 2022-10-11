<?php

namespace Lifo\Daemon\Mediator;

use Exception;

/**
 * Exception used when a child process dies prematurely from a Mediator worker call.
 * User code should always check the return result from a worker and act accordingly.
 *
 */
class CallDiedException extends Exception
{
    private Call $call;

    public function __construct(Call $call)
    {
        parent::__construct();
        $this->call = clone $call;
        $this->call->setPromise(null);
    }

    public function getCall(): Call
    {
        return $this->call;
    }
}