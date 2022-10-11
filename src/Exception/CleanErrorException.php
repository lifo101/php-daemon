<?php

namespace Lifo\Daemon\Exception;

use Exception;

/**
 * Exception class used by Daemon internals and plugins when the daemon execution should be shutdown due to an error.
 * The error displayed will not include a stack trace and the daemon will not try to restart.
 */
class CleanErrorException extends Exception
{

}