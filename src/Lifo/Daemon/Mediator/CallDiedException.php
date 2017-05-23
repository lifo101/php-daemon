<?php

namespace Lifo\Daemon\Mediator;

/**
 * Exception used when a child process dies prematurely from a Mediator worker call.
 * User code should always check the return result from a worker and act accordingly.
 *
 */
class CallDiedException extends \Exception
{
    /**
     * @var Call
     */
    private $call;

    public function __construct(Call $call)
    {
        $this->call = clone $call;
        $this->call->setPromise(null);
    }

    /**
     * @return Call
     */
    public function getCall()
    {
        return $this->call;
    }
}