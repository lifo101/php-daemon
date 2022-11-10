<?php

namespace Lifo\Daemon\IPC;


use Exception;
use Lifo\Daemon\Daemon;
use Lifo\Daemon\Exception\CleanErrorException;
use Lifo\Daemon\Mediator\Call;
use Lifo\Daemon\Mediator\Mediator;
use Lifo\Daemon\StringUtil;

class SysV implements IPCInterface
{
    const HEADER_ADDRESS = 1;
    const ERR_UNKNOWN    = -1;
    const ERR_NONE       = -2;

    /**
     * Memory size of shared buffer
     */
    private int      $size;
    private Mediator $mediator;

    /** @var resource|\SysvSemaphore|false */
    private $sem = false;
    /** @var resource|\SysvSharedMemory|false */
    private $shm = false;
    /** @var resource|\SysvMessageQueue|false */
    private $queue = false;

    public function __construct($size = null)
    {
        $this->malloc($size ?: 5 * 1024 * 1024);
    }

    public function setup()
    {
        $this->setupIPC();
        if (Daemon::getInstance()->isParent()) {
            $this->setupSHM();
        }
    }

    public function purge()
    {
        $this->purgeSEM();
        $this->purgeQueue();
        $this->purgeSHM();
        $this->setupIPC();
    }

    /**
     * Check if the variable given is a resource or PHP8 'resource' class
     * @param $var
     *
     * @return bool
     */
    private function isResource($var): bool
    {
        return PHP_MAJOR_VERSION < 8 ? is_resource($var) : is_object($var);
    }

    private function purgeSEM()
    {
        if ($this->sem) {
            @sem_remove($this->sem);
        }
        $this->sem = null;
    }

    private function purgeSHM()
    {
        if (!$this->isResource($this->shm)) {
            $this->setupIPC();
        }

        if ($this->shm) {
            @shm_remove($this->shm);
            @shm_detach($this->shm);
        }
        $this->shm = null;
    }

    private function purgeQueue()
    {
        if (!$this->isResource($this->queue)) {
            $this->setupIPC();
        }

        if ($this->queue) {
            @msg_remove_queue($this->queue);
        }
        $this->queue = null;
    }

    /**
     * Setup the channels used by IPC -- A SysV Message Queue for message headers and a Shared Memory block for the payload.
     *
     */
    private function setupIPC()
    {
        $this->sem = sem_get($this->mediator->getGuid());
        $this->shm = shm_attach($this->mediator->getGuid(), $this->size);
        if (!$this->isResource($this->shm)) {
            throw new Exception(sprintf("Could not attach to Shared Memory Block 0x%08x", $this->mediator->getGuid()));
        }

        $this->queue = msg_get_queue($this->mediator->getGuid());
        if (!$this->isResource($this->queue)) {
            throw new Exception(sprintf("Could not attach to message queue 0x%08x", $this->mediator->getGuid()));
        }
    }

    private function setupSHM()
    {
        $guid = $this->mediator->getGuid();
        $errors = 0;
        do {
            // Shared memory header
            if (!shm_has_var($this->shm, self::HEADER_ADDRESS)) {
                $header = [
                    'version' => Mediator::VERSION,
                    'size'    => $this->size,
                ];
                if (!shm_put_var($this->shm, self::HEADER_ADDRESS, $header)) {
                    throw new Exception(sprintf("SysV Error: Cannot create SHM header with ID 0x%08x. Try manually cleaning the SysV Shared Memory allocation on this system: \"ipcrm -Q 0x%08x -M 0x%08x\"",
                        $guid, $guid, $guid
                    ));
                }
            }

            $header = @shm_get_var($this->shm, self::HEADER_ADDRESS);
//            if (!$header) {
//                // something is wrong with the SHM block. Purge and restart?
//                $this->purge();
//            }
        } while (!$header && $errors++ < 3);

        if (!is_array($header) || !isset($header['version']) || !isset($header['size'])) {
            $this->fatalError(new CleanErrorException('SysV Error: Shared Memory header with ID 0x%08x is corrupted.', $guid));
        }

        if ($header['version'] <> Mediator::VERSION) {
            $this->error('Warning: Existing Shared Memory header with ID 0x%08x was found with a different version. Current=%s Previous=%s',
                $guid,
                Mediator::VERSION,
                $header['version']
            );
        }

        if ($header['size'] <> $this->size) {
            $this->error('Warning: Existing Shared Memory header with ID 0x%08x was found with a different memory limit. Current=%s Previous=%s',
                $guid,
                $this->size,
                $header['size']
            );
        }
    }

    private function log($msg, $varargs = null)
    {
        $args = func_get_args();
        $args[0] = 'SysV: ' . $args[0];
        call_user_func_array([Daemon::getInstance(), 'log'], $args);
    }

    private function error($msg, $varargs = null)
    {
        $args = func_get_args();
        $args[0] = 'SysV Error: ' . $args[0];
        call_user_func_array([Daemon::getInstance(), 'error'], $args);
    }

    private function fatalError($msg, $varargs = null)
    {
        $args = func_get_args();
        if (!$args[0] instanceof Exception) {
            $args[0] = 'SysV: ' . $args[0];
        }
        call_user_func_array([Daemon::getInstance(), 'fatalError'], $args);
    }

    /**
     * Acquire SEM lock
     *
     * @return void
     */
    private function lock(): void
    {
        if ($this->sem) sem_acquire($this->sem);
    }

    /**
     * Release SEM lock
     *
     * @return void
     */
    private function unlock(): void
    {
        if ($this->sem) @sem_release($this->sem);
    }

    public function get(string $msgType, bool $block = false, &$msg = null): ?Call
    {
        $block = $block ? 0 : MSG_IPC_NOWAIT;
        $type = $err = null;
        msg_receive($this->queue, $msgType, $type, $this->size, $msg, true, $block, $err);

        if (!$msg) {
            // $err == 4 === Interrupted
            if ($err != MSG_ENOMSG && $err != 4 && !Daemon::getInstance()->isShutdown()) {
                $this->error("Error fetching message from queue: ERR=%d %s", $err, posix_strerror($err));
            }
            // todo haven't really tested if this is truly needed or not; Try testing it with Mediator::$allowWakeup
//            if ($err == 4) { // Interrupted
//                // re-attach to the queue since the interrupt may have corrupted our connection
//                $this->setup();
//            }
            return null;
        }

        Daemon::getInstance()->debug(5, "GET MSG=%s %s", Mediator::MSG_TEXT[$type], json_encode($msg));
        switch ($msg['status']) {
            case Call::UNCALLED:
                $decoder = function ($msg) {
                    /** @var Call $call */
                    $call = @shm_get_var($this->shm, $msg['id']);
                    // has been re-queued? cancel this call
//                    if ($call && $msg['time'] < $call->getTime(Call::UNCALLED)) {
//                        dump($call, $msg, microtime(true));
//                        $call->cancelled();
//                    }
                    return $call;
                };
                break;
            case Call::RUNNING:
            case Call::RETURNED:
            default:
                $decoder = function ($msg) use ($type) {
                    /** @var Call $call */
                    $call = @shm_get_var($this->shm, $msg['id']);
                    if ($call) {
                        if ($call->is($msg['status']) && $call->isDone()) {
                            @shm_remove_var($this->shm, $msg['id']);
                        }
                    }
                    return $call;
                };
                break;
        }

        $tries = 1;
        do {
            $call = $decoder($msg);
            if ($call && !empty($msg['pid']) && !Daemon::getInstance()->isParent()) {
                $call->setPid($msg['pid']);
            }
        } while (!$call && $this->handleIpcError(self::ERR_NONE, $tries) && $tries++ < 3);

        if (!$call instanceof Call) {
            return null;
//            throw new \Exception(__METHOD__ . " Failed. Could not decode message: " . json_encode($msg));
        }

        // issue a warning (only once) if the received message size is more than 50% of the overall buffer
        static $warning = true;
        if ($warning && $call->getSize() > ($this->size / 50)) {
            $warning = false;
            $suggested = $call->getSize() * 60;
            $this->log(
                "WARNING: The memory allocated to this worker is too low and may lead to out-of-shared-memory errors." .
                "Based on this job, the memory allocation should be at least %d bytes. Current allocation: %d bytes.",
                $suggested,
                $this->size
            );
        }

        return $call;
    }

    public function put(Call $call): bool
    {
        $daemon = Daemon::getInstance();
        switch (true) {
            case $call->is([Call::UNCALLED, Call::RUNNING, Call::RETURNED]):
                $encoder = function (Call $call) use ($daemon) {
                    if ($daemon->isDebugLevel(5)) {
                        $daemon->debug(5, "PUT CALL %d %s\n", $call->getId(), Call::getStatusText($call));
                    }
                    shm_put_var($this->shm, $call->getId(), $call);
                    return shm_has_var($this->shm, $call->getId());
                };
                break;
            default:
                $encoder = function () {
                    return true;
                };
        }

        $this->lock();
        $err = self::ERR_UNKNOWN;
        if ($encoder($call) && @msg_send($this->queue, $call->getMessageType(), $call->getHeader(), true, false, $err)) {
            if ($daemon->isDebugLevel(5)) {
                $daemon->debug(5, "PUT MSG=%s %s\n", Mediator::MSG_TEXT[$call->getMessageType()], json_encode($call->getHeader()));
            }
            $this->unlock();
            return true;
        }

        $call->incErrors();
        if ($this->handleIpcError($err, $call->getErrors()) && $call->getErrors() < 3) {
            $this->error("%s failed for call %d: Retrying. Error Code: %d %s", __METHOD__, $call->getId(), $err, posix_strerror($err));
            $this->unlock();
            return $this->put($call);
        }

        $this->unlock();
        return false;
    }

    public function drop($call)
    {
        $this->lock();
        $call = $call instanceof Call ? $call->getId() : $call;
        if ($this->shm && shm_has_var($this->shm, $call)) {
            @shm_remove_var($this->shm, $call);
        }
        $this->unlock();
    }


    public function release()
    {
        if ($this->shm) {
            @shm_remove($this->shm);
            $this->shm = null;
        }
        if ($this->queue) {
            @msg_remove_queue($this->queue);
            $this->queue = null;
        }
    }

    /**
     * Set the size of the IPC message buffer. If an attempt it made to change the size after the IPC has been setup
     * (ie: after a worker is started) an error is logged.
     *
     * @param $size
     *
     * @return self
     */
    public function malloc($size): self
    {
        if ($this->shm) {
            Daemon::getInstance()->error("SHM Memory size cannot be changed after setup. [Current=%s] [New=%s]",
                StringUtil::kbytes($this->size),
                StringUtil::kbytes($size)
            );
            return $this;
        }
        $this->size = $size;
        return $this;
    }

    /**
     * Return status of the message queue
     *
     * @return array
     */
    public function getQueueStatus(): array
    {
        return @msg_stat_queue($this->queue) ?: [];
    }

    public function getPendingMessages(): int
    {
        $stat = $this->getQueueStatus();
        return ($stat && isset($stat['msg_qnum'])) ? $stat['msg_qnum'] : 0;
    }

    public function setMediator(Mediator $mediator)
    {
        $this->mediator = $mediator;
    }

    /**
     * Run a quick test on the IPC shared memory buffer.
     *
     * @return bool True if SHM is working
     */
    private function testIpc(): bool
    {
        if (!$this->isResource($this->shm)) {
            return false;
        }

        $arr = array_fill(0, mt_rand(10, 100), mt_rand(1000, 1000 * 1000));
        $key = mt_rand(1000 * 1000, 2000 * 1000);
        @shm_put_var($this->shm, $key, $arr);
        usleep(5000);
        return @shm_get_var($this->shm, $key) == $arr;
    }

    /**
     * Increase back-off delay in an exponential way up to a certain plateau.
     *
     * @param int $delay
     * @param int $try
     *
     * @return float
     */
    private function getDelay(int $delay, int $try): float
    {
        return $delay * pow(2, min(max($try, 1), 8)) - $delay;
    }

    /**
     * @param int $error
     * @param int $try
     *
     * @return bool
     * @throws Exception
     */
    private function handleIpcError(int $error, int $try = 1): bool
    {
        switch ($error) {
            case 0: // success
            case 4: // System Interrupt
            case self::ERR_NONE:
            case MSG_ENOMSG:
                // no message of desired type
                return true;
            case MSG_EAGAIN:
                // temporary problem; try again!
                usleep($this->getDelay(20000, $try));
                return true;
            case 13:
                // permission denied
                $this->mediator->countError(Mediator::ERR_TYPE_COMMUNICATION);
                $this->error("Permission Denied: Cannot connect to message queue");
                $this->purgeQueue();
                if (Daemon::getInstance()->isParent()) {
                    usleep($this->getDelay(100000, $try));
                } else {
                    sleep($this->getDelay(3, $try));
                }
                $this->setupIPC();
                return true;
            case 22:
                // Invalid Argument
                // Probably because the queue was removed in another process.
            case 43:
                // Identifier Removed
                // A message queue was re-created at this address but the resource identifier we have needs to be re-created
                $this->mediator->countError(Mediator::ERR_TYPE_COMMUNICATION);
                if (Daemon::getInstance()->isParent()) {
                    usleep($this->getDelay(20000, $try));
                } else {
                    sleep($this->getDelay(2, $try));
                }
                $this->setupIPC();
                return true;
            case self::ERR_UNKNOWN:
                // Almost certainly an issue with shared memory
                $this->error("Shared Memory I/O Error [GUID=0x%08x]", $this->mediator->getGuid());
                $this->mediator->countError(Mediator::ERR_TYPE_CORRUPTION);

                // If this is a worker, all we can do is try to re-attach the shared memory.
                // Any corruption or OOM errors will be handled by the parent exclusively.
                if (!Daemon::getInstance()->isParent()) {
                    sleep($this->getDelay(3, $try));
                    $this->setupIPC();
                    return true;
                }

                // In the parent, do some diagnostic checks and attempt correction.
                usleep($this->getDelay(20000, $try));

                // Test writing to shared memory
                for ($i = 0; $i < 2; $i++) {
                    if ($this->testIpc()) {
                        return true;
                    } else {
                        $this->error("IPC Test Failed [GUID=0x%08x]", $this->mediator->getGuid());
                    }
                    $this->setupIPC();
                }

                $this->error("IPC DIAG: Re-Connect failed");

                // todo recreate shared memory call buffer?

                return true;
            default:
                if ($error) {
                    $this->error("Message Queue Error %s: %s", $error, posix_strerror($error));
                }

                if (Daemon::getInstance()->isParent()) {
                    usleep($this->getDelay(100000, $try));
                } else {
                    sleep($this->getDelay(3, $try));
                }
                $this->mediator->countError(Mediator::ERR_TYPE_CATCHALL);
                $this->setupIPC();

                return false;
        }
    }
}