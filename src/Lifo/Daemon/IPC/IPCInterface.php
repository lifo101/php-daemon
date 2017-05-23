<?php

namespace Lifo\Daemon\IPC;


use Lifo\Daemon\Mediator\Call;
use Lifo\Daemon\Mediator\Mediator;

interface IPCInterface
{
    public function setup();

    public function purge();

    public function malloc($size);

    public function release();

    /**
     * Return a message from the queue. Optionally block until a message arrives.
     *
     * @param string    $msgType
     * @param bool      $block
     * @param \stdClass $msg Returned message from queue
     * @return Call
     */
    public function get($msgType, $block = false, &$msg = null);

    /**
     * Put a call onto the queue
     *
     * @param Call $call
     * @return bool
     */
    public function put(Call $call);

    /**
     * Drop the call from shared memory.
     *
     * @param Call|int $call
     */
    public function drop($call);

    /**
     * Set the mediator attached to this IPC
     *
     * @param Mediator $mediator
     */
    public function setMediator(Mediator $mediator);

    /**
     * Return the total pending messages on the queue
     */
    public function getPendingMessages();
}