<?php

namespace Lifo\Daemon\Event;


/**
 * Event object for log handling.
 */
class LogEvent extends DaemonEvent
{
    private string $message;

    public function __construct($message)
    {
        parent::__construct();
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