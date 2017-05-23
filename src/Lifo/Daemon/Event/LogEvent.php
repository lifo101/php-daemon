<?php

namespace Lifo\Daemon\Event;

use Lifo\Daemon\Daemon;


/**
 * Event object for log handling.
 */
class LogEvent extends DaemonEvent
{
    /**
     * The message
     *
     * @var string
     */
    private $message;

    public function __construct(Daemon $daemon, $message)
    {
        parent::__construct($daemon);

        $this->message = $message;
    }

    /**
     * Get the signal number that was caught
     *
     * @return int Signal number
     */
    public function getMessage()
    {
        return $this->message;
    }
}