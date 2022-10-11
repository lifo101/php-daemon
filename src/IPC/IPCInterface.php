<?php

namespace Lifo\Daemon\IPC;


use Lifo\Daemon\Mediator\Call;
use Lifo\Daemon\Mediator\Mediator;

interface IPCInterface
{
    /**
     * Setup the channels used by IPC. Each IPC class usually needs a message queue and a message payload memory block.
     * Care should be taken to setup the parent and child processes. The parent might need to do more setup than a
     * child would need. Use {@link Daemon::isParent} to determine the context.
     *
     * @return void
     */
    public function setup();

    /**
     * Purge the message queue/buffers and re-setup, as needed.
     *
     * @return void
     */
    public function purge();

    /**
     * Set the size of the IPC message buffer.
     *
     * @param $size
     *
     * @return self
     */
    public function malloc($size): self;

    /**
     * Release the message queue and buffers. This should destroy the queue/buffers the IPC uses.
     *
     * @return void
     */
    public function release();

    /**
     * Return a message from the queue. Optionally block until a message arrives.
     *
     * @param string $msgType See the Mediator::MSG_* constants.
     * @param bool   $block   If true, the call to this function will block until a message is received.
     * @param mixed  $msg     Returned raw message from queue. Optional.
     *
     * @return Call|null The call that made the request. If null, a call could not be found.
     */
    public function get(string $msgType, bool $block = false, &$msg = null): ?Call;

    /**
     * Put a call onto the queue
     *
     * @param Call $call
     *
     * @return bool
     */
    public function put(Call $call): bool;

    /**
     * Drop the call from the call buffer.
     *
     * @param Call|int $call
     */
    public function drop($call);

    /**
     * Set the mediator attached to this IPC. This is set by the Mediator after the class is instantiated.
     *
     * @param Mediator $mediator
     */
    public function setMediator(Mediator $mediator);

    /**
     * Return the total pending messages on the queue
     */
    public function getPendingMessages(): int;
}