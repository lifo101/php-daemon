<?php

namespace Lifo\Daemon\Event;


use Lifo\Daemon\Daemon;
use Symfony\Component\EventDispatcher\Event;

/**
 * Basic Event object for the {@see \Symfony\Component\EventDispatcher\EventDispatcher EventDispatcher}
 *
 * Special methods are added/overridden so propagation can be re-enabled on the object since event objects are
 * re-used within the {@link Daemon}
 *
 * @see \Symfony\Component\EventDispatcher\EventDispatcher
 */
class DaemonEvent extends Event
{
    /**
     * Event fired during daemon initialization. Called once.
     */
    const ON_INIT = 'daemon.init';

    /**
     * Event fired every time the daemon is idle.
     */
    const ON_IDLE = 'daemon.idle';

    /**
     * Event fired every time a process is forked. The CHILD process receives the event.
     */
    const ON_FORK = 'daemon.fork';

    /**
     * Event fired every time a process is forked. The PARENT process receives the event.
     */
    const ON_PARENT_FORK = 'daemon.parent_fork';

    /**
     * Event fired if the parent PID changes.
     * Receives an {@link PidEvent} object.
     */
    const ON_PID_CHANGE = 'daemon.pid_change';

    /**
     * Event fired before {@link Daemon::execute} is called.
     * If propagation is stopped from any handler then ({@link Daemon::execute}) is not called.
     */
    const ON_PRE_EXECUTE = 'daemon.pre_execute';

    /**
     * Event fired after {@link Daemon::execute} is called.
     * If propagation is stopped from any handler then {@link Daemon::wait} is not called.
     */
    const ON_POST_EXECUTE = 'daemon.post_execute';

    /**
     * Event fired every time an OS Signal is caught. Handlers should do as little as possible within this event.
     * Receives an {@link SignalEvent} object.
     */
    const ON_SIGNAL = 'daemon.signal';

    /**
     * Event fired when the daemon goes into shutdown mode.
     */
    const ON_SHUTDOWN = 'daemon.shutdown';

    /**
     * Event fired every time {@link Daemon::error} is called. If propagation is stopped normal error handling is
     * not performed.
     * Receives an {@link ErrorEvent} object.
     */
    const ON_ERROR = 'daemon.error';

    /**
     * Event fired every time {@link Daemon::log} is called. If propagation is stopped the message is not logged.
     * Receives an {@link LogEvent} object.
     */
    const ON_LOG = 'daemon.log';

    /**
     * Event fired every time {@link Daemon::stats} is called. Allows plugins and your main application to add or
     * modify the stats that are returned.
     * Receives an {@link StatsEvent} object.
     */
    const ON_STATS = 'daemon.stats';

    /**
     * Event fired when the {@link Mediator} generates a GUID. If a GUID is set on the event then it will be used
     * instead of generating a new one in {@link Mediator::generateId}
     * Receives an {@link GuidEvent} object.
     */
    const ON_GENERATE_GUID = 'mediator.guid';

    /**
     * Event fired every time a child is reaped from the ProcessManager plugin.
     * Receives an {@link ReapedEvent} object.
     */
    const ON_REAPED = 'process.reaped';

    /** @var Daemon */
    protected $daemon;

    private $propagationStopped = false;

    public function __construct(Daemon $daemon = null)
    {
        // todo Refactor DaemonEvent; it doesn't need the Daemon parameter since we can just use Daemon::getInstance
        $this->daemon = $daemon ?: Daemon::getInstance();
    }

    public function isPropagationStopped()
    {
        return $this->propagationStopped;
    }

    public function stopPropagation()
    {
        $this->propagationStopped = true;
    }

    public function startPropagation()
    {
        $this->propagationStopped = false;
    }

    /**
     * @return Daemon
     */
    public function getDaemon()
    {
        return $this->daemon;
    }
}