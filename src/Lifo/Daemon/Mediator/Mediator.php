<?php

namespace Lifo\Daemon\Mediator;


use Lifo\Daemon\Daemon;
use Lifo\Daemon\Event\DaemonEvent;
use Lifo\Daemon\Event\GuidEvent;
use Lifo\Daemon\Event\ReapedEvent;
use Lifo\Daemon\Event\StatsEvent;
use Lifo\Daemon\ExceptionsTrait;
use Lifo\Daemon\IPC\IPCInterface;
use Lifo\Daemon\IPC\SysV;
use Lifo\Daemon\LogTrait;
use Lifo\Daemon\Plugin\ProcessManagerInterface;
use Lifo\Daemon\Promise;
use Lifo\Daemon\StringUtil;
use Lifo\Daemon\Task\TaskInterface;
use Lifo\Daemon\Worker\WorkerInterface;

/**
 *
 * Maintains a worker queue using IPC and the ProcessManager Daemon plugin using a Mediator pattern.
 *
 * <br/>
 * When a daemon worker is created via {@link Daemon::addWorker} an instance of this class is returned.
 * When working with the worker in your daemon loop you would call any worker methods as you normally would, except
 * the return value will always be a {@link Promise} so your main process can react at the appropriate time (after the
 * worker actually returns a value). You can ignore the promise and use the {@link Mediator::ON_RETURN} or
 * {@link Mediator::ON_TIMEOUT} callbacks instead.
 *
 * <br/>
 * You can also perform a blocking call (not forked into the background) on your worker by using the
 * {@link Mediator::inline} method.
 *
 * <br/>
 * Example:
 *
 * <code>
 * // inside your Daemon::initialize
 * $m = $this->addWorker('MyWorker', 'worker'); // returns Mediator object
 * // optional callback instead of using Promise:
 * // $m->onReturn(function ($str) { echo "From Worker: $str\n"; });
 *
 * // inside your Daemon::execute
 * $w = $this->worker('worker');
 * // 'capitalize' returns a Promise.
 * $p = $w->capitalize('hello world')->then(function($str) {
 *   echo "From Worker: $str\n";
 * });
 *
 * // do a blocking call instead:
 * echo $w->inline()->capitalize('john doe'), "\n";
 *
 * // MyWorker is a normal class with no dependencies
 * class MyWorker {
 *   // worker method is a normal function that directly returns the result
 *   public function capitalize($str) { return uc_words($str); }
 * }
 * </code>
 */
class Mediator implements TaskInterface
{
    use LogTrait;
    use ExceptionsTrait;

    public static $MAX_RECENT_STATS = 3;

    /**
     * Version used for messaging.
     */
    const VERSION = '1.0';

    const MAX_FORK_ERRORS = 3;

    /**
     * LAZY Forking Strategy. Fork workers 1-by-1, only when needed.
     */
    const FORK_LAZY = 'lazy';
    /**
     * MIXED Forking Strategy. Do not fork any workers until the first call, then fork the maximum workers configured
     * all at once.
     */
    const FORK_MIXED = 'mixed';
    /**
     * AGGRESSIVE Forking Strategy. Fork all workers all at once at the start.
     */
    const FORK_AGGRESSIVE = 'aggressive';
    /**
     * The Call timed out.
     */
    const ON_TIMEOUT = 0;
    /**
     * The Call returned successfully with a possible result.
     */
    const ON_RETURN = 1;
    /**
     * The Call died prematurely w/o returning a result (eg: was killed by the OS).
     */
    const ON_DIED = 2;

    const MSG_ANY     = 0;
    const MSG_CALL    = 30;
    const MSG_RUNNING = 20;
    const MSG_RETURN  = 10;
    public static $MSG_TEXT = [ // can be 'const' in PHP 5.6
        self::MSG_ANY     => 'ANY',
        self::MSG_CALL    => 'CALL',
        self::MSG_RUNNING => 'RUNNING',
        self::MSG_RETURN  => 'RETURN',
    ];

    const ERR_TYPE_COMMUNICATION = 'communication';
    const ERR_TYPE_CORRUPTION    = 'corruption';
    const ERR_TYPE_CATCHALL      = 'catchall';

    public static $ERROR_THRESHOLDS = [
        // 'type'                    => [worker, parent]
        self::ERR_TYPE_COMMUNICATION => [10, 50],
        self::ERR_TYPE_CORRUPTION    => [10, 25],
        self::ERR_TYPE_CATCHALL      => [10, 25],
    ];

    /**
     * Alias for the subject
     *
     * @var string
     */
    private $alias;

    /**
     * Was the object setup?
     *
     * @internal
     * @var bool
     */
    private $initialized;

    /**
     * The subject being mediated.
     *
     * @var object|callable
     */
    private $subject;

    /**
     * IPC object for parent/worker communication
     *
     * @var IPCInterface
     */
    private $ipc;

    /**
     * Forking strategy
     *
     * @var string
     */
    private $forkingStrategy = self::FORK_LAZY;

    /**
     * Allow auto-restarts? If true, and other certain criteria is met the mediator process will exit and another
     * process will be forked on an as-needed basis.
     *
     * Auto restarts will not occur if the subject's callback takes a long time.
     *
     * @var bool
     */
    private $autoRestart = false;

    /**
     * Maximum calls this instance will process before exiting. {@link $autoRestart} must be true.
     * A small amount of entropy is added to reduce all children restarting at the same time.
     *
     * @var int
     */
    private $maxCalls = 0;

    /**
     * Maximum seconds this instance will run before exiting. {@link $autoRestart} must be true.
     * A small amount of entropy is added to reduce all children restarting at the same time.
     *
     * @var int
     */
    private $maxRuntime = 0;

    /**
     * Minimum seconds this instance will run before exiting. {@link $autoRestart} must be true.
     *
     * @var int
     */
    private $minRuntime = 0;

    /**
     * Maximum worker processes allowed
     * A small amount of entropy is added to reduce all children restarting at the same time.
     *
     * @var int
     */
    private $maxProcesses = 1;

    /**
     * Allow the Mediator to wakeup the daemon?
     *
     * @var bool
     */
    private $allowWakeup = false;

    /**
     * Event listeners
     *
     * @var array
     */
    private $events = [];

    /**
     * Deterministic GUID for use when SHM routines
     *
     * @var int
     */
    private $guid;

    /**
     * A list of running calls
     *
     * @var double[]
     */
    private $running = [];

    /**
     * A list of all calls
     *
     * @var Call[]
     */
    private $calls = [];

    /**
     * A list of recent calls made for stats purposes
     *
     * @var array[]
     */
    private $recent = [];

    /**
     * How many calls have been made
     *
     * @var int
     */
    private $callCount = 0;

    /**
     * @var int[][]
     */
    private $errorCounts = [];
    /**
     * @var int[]
     */
    private $reaped = [];

    /**
     * Mediator constructor.
     *
     * @param object|callable|string $subject
     * @param string                 $alias
     * @param IPCInterface           $ipc Defaults to \Lifo\Daemon\IPC\SysV
     */
    public function __construct($subject, $alias, IPCInterface $ipc = null)
    {
        $this->setSubject($subject);
        $this->setAlias($alias);
        $this->setIpc($ipc ?: new SysV());
        $this->determineForkingStrategy();
    }

//    public function __destruct()
//    {
//        if (!Daemon::getInstance()->isParent()) {
//            return;
//        }
//    }

    /**
     * Intercept calls on the subject.
     *
     * @param string $method Method being called.
     * @param array  $args   Optional arguments passed in.
     * @return Promise
     */
    public function __call($method, $args)
    {
        switch (true) {
            case method_exists($this->subject, $method):
                return $this->call($method, $args);
            default:
                throw self::createInvalidArgumentException(func_get_args(), "Unknown method call on subject", 3);
        }
    }

    /**
     * Intercept invocation of subject. This only occurs when the subject is a callable.
     *
     * @return mixed
     */
    public function __invoke()
    {
        if (!is_callable($this->subject)) {
            throw self::createInvalidArgumentException(func_get_args(), 'Subject is not a callable', 3);
        }
        return $this->call('__invoke', func_get_args());
    }

    /**
     * Kill the specified worker process, or all workers associated with this instance.
     *
     * @param null $id
     * @return int Total processes killed.
     */
    public function kill($id = null)
    {
        $cnt = 0;
        foreach ($this->calls as $call) {
            $pid = $call->getPid();
            if ($id && $pid != $id) {
                continue;
            }
            if ($pid && posix_kill($pid, 0)) {
                if (posix_kill($pid, SIGKILL)) {
                    $cnt++;
                }
            }
        }
//        if (!$id) {
//            $this->ipc->purge();
//        }
        return $cnt;
    }

    /**
     * Initial setup of mediator/task. This is called automatically the first time a worker is accessed from the
     * Daemon.
     */
    public function setup()
    {
        if (Daemon::getInstance()->isParent()) {
            if ($this->initialized) {
                return;
            }
            $this->initialized = true;
            $this->parentSetup();
        } else {
            // always do forked setup
            $this->forkedSetup();
        }
    }

    protected function parentSetup()
    {
        try {
            $file = null;
            $this->guid = self::generateId($GLOBALS['argv'][0] ?: __FILE__, $this->alias, $file);
            $this->debug(2, "Setup [GUID=0x%08x] [ftok=%s] [Fork=%s(%d)] [Calls=%s] [RT=%s]",
                $this->guid,
                $file ?: 'NULL',
                $this->forkingStrategy,
                $this->maxProcesses,
                $this->maxCalls ?: 'Inf',
                $this->maxRuntime ? StringUtil::elapsedFromSeconds($this->maxRuntime) : 'Inf'
            );
        } catch (\Exception $e) {
            $this->fatalError($e->getMessage());
        }

        $this->ipc->setup();
        $this->ipc->purge();

        $daemon = Daemon::getInstance();
        $daemon->on(DaemonEvent::ON_PRE_EXECUTE, function () {
            $this->onLoop();
        });

        // handle reaped children after the loop has been processed to prevent a race-condition from children exiting
        // before their RETURN call can be ACKed in the PRE_EXECUTE event.
        $daemon->on(DaemonEvent::ON_POST_EXECUTE, function () {
            $this->handleReapedChildren();
        });

        // keep track of any children that potentially died prematurely.
        $daemon->on(DaemonEvent::ON_REAPED, function (ReapedEvent $e) use ($daemon) {
            $this->reaped = array_merge($this->reaped, $e->getPids());
        });

        $daemon->on(DaemonEvent::ON_IDLE, function () {
            static $last = null;
            if (!$last || time() - $last > 30) {
                $this->gc();
                $last = time();
            }
        });

        $daemon->on(DaemonEvent::ON_STATS, function (StatsEvent $e) {
            $stats = $this->onStats($e->getStats());
            if ($stats) {
                $e->setStats($stats);
            }
        });

        $this->fork();
    }

    protected function forkedSetup()
    {
        $this->callCount = 0;
        $this->calls = [];
        $this->running = [];
        $this->events = [];
        $this->ipc->setup();

        if ($this->subject instanceof WorkerInterface) {
            $this->subject->initialize();
        }
    }

    protected function onStats(array $stats = null)
    {
        static $name = null;
        if (!$name) {
            $name = strtolower(StringUtil::baseClassName($this));
        }

        $stats[$name][$this->alias] = [
            'calls'       => array_map(function (Call $c) {
                return unserialize(serialize($c));
            }, $this->calls),
            'running'     => $this->running,
            'recent'      => $this->recent,
            'errors'      => $this->errorCounts,
            'guid'        => $this->guid,
            'guid_hex'    => sprintf("0x%08x", $this->guid),
            'total_calls' => $this->callCount,
        ];
        return $stats;
    }

    /**
     * Garbage Collector
     */
    protected function gc()
    {
        $daemon = Daemon::getInstance();
//        $this->debug(5, 'gc');

        foreach ($this->calls as $id => $call) {
            // not normally needed since calls are removed when returned. But just in case...
            if ($call->gc() && $daemon->isParent()) {
                $this->ipc->drop($call);
            }
        }
    }

    /**
     * Generate a deterministic ID to be used with Shared Memory routines.
     *
     * @param string $file     File to use ftok on; Defaults to $GLOBALS['argv'][0]
     * @param string $alias
     * @param string $filename Optionally save the filename used to generate to ftok in this variable
     * @return int
     * @throws \Exception On error
     */
    public static function generateId($file = null, $alias = 'mediator', &$filename = null)
    {
        /** @var GuidEvent $e */
        $e = Daemon::getInstance()->dispatch(DaemonEvent::ON_GENERATE_GUID, new GuidEvent($file, $alias, $filename));
        if ($e->getGuid() !== null) {
            return $e->getGuid();
        }

        if (!is_string($alias) || $alias === '') {
            throw self::createInvalidArgumentException(func_get_args(), "Alias argument #2 must be a non-empty string");
        }

        if ($file === null) {
            $file = $GLOBALS['argv'][0];
        }

        // create a deterministic ID for shared memory usage. This allows us to re-create the ID in external processes
        $file = sprintf('%s/%s_%s.ftok',
            sys_get_temp_dir(),
            str_replace(['/', '\\', '.', '__'], '_', $file),
            str_replace(['/', '\\', '.', '__'], '_', $alias)
        );
        $filename = $file;

        $guid = -1;
        if (touch($file)) {
            @chmod($file, 0600);
            $guid = ftok($file, substr($alias, 0, 1));
            // since ftok() essentially uses the inode of the file for the unique ID, we keep the filename around
            // in case multiple calls to generateId() are made in the same program run. Otherwise, even with different
            // aliases you'll most likely end up with the same $guid being returned.
            register_shutdown_function(function ($file) {
                if (@is_file($file)) {
                    @unlink($file);
                }
            }, $file);
        }

        if ($guid == -1) {
            throw new \Exception(sprintf("Error creating GUID. Cannot write to file \"%s\" to generate FTOK token.", $file));
        }

        return $guid;
    }

    /**
     * @return int
     */
    public function getGuid()
    {
        return $this->guid;
    }

    protected function fatalError($msg, $varargs = null)
    {
        $msg = vsprintf($msg, array_slice(func_get_args(), 1));
        Daemon::getInstance()->fatalError("\"%s\": %s", $this->alias, $msg);
    }

    protected function retry($call)
    {
        // todo retry
    }

    /**
     * Return the ProcessManager
     *
     * @return ProcessManagerInterface
     */
    protected function getManager()
    {
        /** @var ProcessManagerInterface $pm */
        $pm = Daemon::getInstance()->plugin(Daemon::PROCESS_MANAGER_PLUGIN_NAME);
        return $pm;
    }

    /**
     * @param int $pid
     * @return \Lifo\Daemon\Process
     */
    protected function getProcess($pid)
    {
        return $this->getManager()->getProcess($pid, $this->alias);
    }

    public function getTotalProcesses()
    {
        return $this->getManager()->count($this->alias);
    }

    /**
     * @param $method
     * @param $args
     * @return Promise
     */
    protected function call($method, $args)
    {
        try {
            $call = Call::create($method, $args);
            $this->calls[$call->getId()] = $call;
            $this->callCount++;

            $promise = $call->getPromise();
            if ($this->ipc->put($call)) {
                $call->called();
                $this->fork();
            } else {
                throw new \Exception("Unable to put call #" . $call->getId() . " on queue");
            }
        } catch (\Exception $e) {
            $this->error(sprintf('Method call failed: %s(%s): %s', $method, self::serializeArguments($args), $e->getMessage()));
            if (!isset($promise)) {
                $promise = new Promise();
            }
            $promise->reject($e);
        }

        return $promise;
    }

    /**
     * Set the subject to mediate. Can be callable, object, or a fully-qualified class name.
     *
     * @param callable|object|string $subject
     * @return $this
     */
    protected function setSubject($subject)
    {
        if (is_string($subject)) {
            if (!class_exists($subject)) {
                throw self::createInvalidArgumentException(func_get_args(), "Invalid mediator subject. Class does not exist", 4);
            }
            $subject = new $subject();
        }

        // if it's an object, make sure its methods do not overlap with the Mediator class
        if (is_object($subject) && !$subject instanceof \Closure) {
            $getMethods = function ($class) {
                $ref = new \ReflectionClass($class);
                $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
                $list = array_filter($methods, function (\ReflectionMethod $method) {
                    $name = $method->getShortName();
                    if (substr($name, 0, 2) == '__') {
                        return false;
                    }
                    return true;
                });
                return array_map(function (\ReflectionMethod $m) { return $m->getShortName(); }, $list);
            };

            $localMethods = $getMethods($this);
            $otherMethods = $getMethods($subject);
            $intersect = array_intersect($localMethods, $otherMethods);
            if (!empty($intersect)) {
                throw self::createInvalidArgumentException(func_get_args(), sprintf("Mediator subject has intersecting public methods [%s] with Mediator class. Subject should not contain any public method with the same name as the Mediator class", implode(', ', $intersect)), 4);
            }
        }

        $this->subject = $subject;
        return $this;
    }

    /**
     * Return the subject being mediated
     *
     * @return callable|object
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Return the worker object directly.
     *
     * Allows the main application code to call a method on the subject w/o interfacing with a background process.
     * Calls made on the subject will block and will directly return any results.
     *
     * @return mixed
     */
    public function inline()
    {
        return is_callable($this->subject) ? call_user_func_array($this->subject, func_get_args()) : $this->subject;
    }

    /**
     * Set the IPC interface for parent/child communication
     *
     * @param IPCInterface $ipc
     * @return $this
     */
    protected function setIpc($ipc)
    {
        $this->ipc = $ipc;
        $ipc->setMediator($this);
        return $this;
    }

    /**
     * Return the IPC interface for parent/child communication
     *
     * @return IPCInterface
     */
    public function getIpc()
    {
        return $this->ipc;
    }

    /**
     * Set the alias for the subject
     *
     * @param string $alias
     * @return $this
     */
    protected function setAlias($alias)
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * Get the alias for the subject
     *
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * Return the forking strategy.
     *
     * @return int
     */
    public function getForkingStrategy()
    {
        return $this->forkingStrategy;
    }

    /**
     * Set the forking strategy. Should be one of the class constants:
     * * {@link Mediator::FORK_LAZY}
     * * {@link Mediator::FORK_MIXED}
     * * {@link Mediator::FORK_AGGRESSIVE}
     *
     * @param int $strategy
     * @return $this
     */
    public function setForkingStrategy($strategy)
    {
        $this->forkingStrategy = $strategy;
        return $this;
    }

    /**
     * Determine the initial forking strategy to use. Based on the {@link Daemon::$loopInterval} the forking strategy
     * will be more aggressive the slower the interval is. Manually set by calling {@link Mediator::setForkingStrategy}
     *
     * @return string
     */
    protected function determineForkingStrategy()
    {
        $interval = Daemon::getInstance()->getLoopInterval();
        switch (true) {
            case $interval > 2 || $interval === 0:
                return $this->forkingStrategy = self::FORK_LAZY;
            case $interval > 1:
                return $this->forkingStrategy = self::FORK_MIXED;
            default:
                return $this->forkingStrategy = self::FORK_AGGRESSIVE;
        }
    }

    /**
     * @return int
     */
    public function getMaxProcesses()
    {
        return $this->maxProcesses;
    }

    /**
     * Set the IPC memory buffer size.
     *
     * @param int $size Memory size of IPC buffer
     * @return $this
     */
    public function malloc($size)
    {
        $this->ipc->malloc($size);
        return $this;
    }

    /**
     * Set the maximum number of worker processes
     *
     * @param int $max
     * @return $this
     */
    public function setMaxProcesses($max)
    {
        $this->maxProcesses = (int)$max;
        return $this;
    }

    /**
     * Allow auto-restarts? If true, and other certain criteria is met the mediator process will exit and another
     * process will be forked based on the {@link $forkingStrategy}.
     *
     * @param bool $autoRestart
     * @return $this
     */
    public function setAutoRestart($autoRestart)
    {
        $this->autoRestart = $autoRestart;
        return $this;
    }

    /**
     * Maximum calls this instance will process before exiting. A small amount of random entropy is added.
     * {@link $autoRestart} must be true.
     *
     * @param int $maxCalls
     * @return $this
     */
    public function setMaxCalls($maxCalls)
    {
        $this->maxCalls = (int)$maxCalls;
        return $this;
    }

    /**
     * Maximum seconds a worker will run before exiting. A small amount of random entropy is added.
     * The time is takes for the {@link $subject} callback to return is not taken into account and thus the runtime
     * of a worker process may run longer than expected.
     * {@link $autoRestart} must be true.
     *
     * @param int $maxRuntime
     * @return $this
     */
    public function setMaxRuntime($maxRuntime)
    {
        $this->maxRuntime = (int)$maxRuntime;
        return $this;
    }

    /**
     * @param mixed $minRuntime
     */
    public function setMinRuntime($minRuntime)
    {
        $this->minRuntime = (int)$minRuntime;
    }

    /**
     * Dispatch the event
     *
     * @param string $eventName
     * @param mixed  $args
     * @return bool True if callback was actually called
     */
    protected function dispatch($eventName, $args = null)
    {
        if (isset($this->events[$eventName])) {
            call_user_func_array($this->events[$eventName], is_array($args) ? $args : [$args]);
            return true;
        }
        return false;
    }

    /**
     * Register a callback for the event name given.
     *
     * @param string   $eventName
     * @param callable $callback
     * @return $this
     */
    public function on($eventName, callable $callback)
    {
        $this->events[$eventName] = $callback;
        return $this;
    }

    /**
     * Remove the callback for the event name given.
     *
     * @param string $eventName
     * @return $this
     */
    public function off($eventName)
    {
        unset($this->events[$eventName]);
        return $this;
    }

    /**
     * Shortcut for registering an ON_RETURN event.
     *
     * @param callable $callback
     * @return Mediator
     */
    public function onReturn(callable $callback)
    {
        return $this->on(self::ON_RETURN, $callback);
    }

    /**
     * Shortcut for registering an ON_DIED event.
     *
     * @param callable $callback
     * @return Mediator
     */
    public function onDied(callable $callback)
    {
        return $this->on(self::ON_DIED, $callback);
    }

    /**
     * Shortcut for registering an ON_TIMEOUT event.
     *
     * @param callable $callback
     * @return Mediator
     */
    public function onTimeout(callable $callback)
    {
        return $this->on(self::ON_TIMEOUT, $callback);
    }

    /**
     * Maintain worker processes
     */
    protected function fork()
    {
        $processes = $this->getTotalProcesses(); // # of forked processes already running
        $activeCalls = count($this->calls);      // # of active calls
        if ((!$activeCalls && $this->forkingStrategy != self::FORK_AGGRESSIVE) || $processes >= $this->maxProcesses) {
            return;
        }

        switch ($this->forkingStrategy) {
            case self::FORK_LAZY:
                $forks = $activeCalls > $processes ? 1 : 0;
                break;
            case self::FORK_MIXED:
                $forks = $activeCalls ? max($this->maxProcesses - $processes, 0) : 0;
                break;
            case self::FORK_AGGRESSIVE:
            default:
                $forks = max($this->maxProcesses - $processes, 0);
                break;
        }

        // in LAZY|MIXED situations with pending messages we must fork at least 1 worker
//        if ($forks == 0 && $this->ipc->getPendingMessages() > 0) {
//            $forks = 1;
//        }

        $daemon = Daemon::getInstance();
        $errors = 0;
        while ($forks > 0) {
            $proc = $daemon->task($this);
            if ($proc) {
                $this->debug(3, "Forked [PID=%d]", $proc->getPid());
                $forks--;
                $errors = 0;
                continue;
            }

            if ($errors++ < self::MAX_FORK_ERRORS) {
                continue;
            }

            $this->fatalError("Mediator: Fork Failed");
        }
    }

    public function teardown()
    {
        $this->debug(4, "Teardown");
    }

    /**
     * Remove the cached call from local storage
     *
     * @param Call|int $id Call or Call ID to remove from processing.
     */
    private function removeCall($id)
    {
        if ($id instanceof Call) {
            $id = $id->getId();
        }

        // track for stats; probably not really useful for anyone...
        if (isset($this->calls[$id])) {
            $call = $this->calls[$id];
            $times = $call->getTime();
            array_unshift($this->recent, [
                'id'       => $call->getId(),
                'pid'      => $call->getPid(),
                'method'   => $call->getMethod(),
                'start'    => reset($times),
                'end'      => end($times),
                'runtime'  => $call->runtime(),
                'size'     => $call->getSize(),
                'attempts' => $call->getAttempts(),
                'errors'   => $call->getErrors(),
            ]);
            $this->recent = array_slice($this->recent, 0, self::$MAX_RECENT_STATS);
        }

        unset($this->calls[$id]);
        unset($this->running[$id]);
    }

    protected function handleReapedChildren()
    {
        // If any children died they will be reaped by the ProcessManager and collected via the ON_REAPED event.
        if ($this->reaped) {
            foreach ($this->reaped as $pid) {
                // if there was a call for this process then keep our promise and reject it
                if (null !== $call = $this->findOriginalCall($pid)) {
                    $this->debug("Premature worker death was reaped [PID=%d] [Call=%d] [Status=%s]",
                        $pid, $call->getId(), Call::getStatusText($call)
                    );
                    $call->timeout()->setResult(new CallDiedException($call));
                    $this->doPromise($call, 'died');
                    $this->removeCall($call);
                }
            }
            $this->reaped = [];
            $this->fork();
        }
    }

    /**
     * Called on every daemon loop (parent process)
     */
    protected function onLoop()
    {
        try {
            if (empty($this->calls)) {
                return;
            }

            // process ACKs from RUNNING calls and update our local Call object
            while ($call = $this->ipc->get(self::MSG_RUNNING)) {
                if ($this->isCallStale($call)) {
                    continue;
                }

                $this->running[$call->getId()] = microtime(true);
                if ($proc = $this->getProcess($call->getPid())) {
                    $proc['call'] = $call->getId();
                }

                // update local cached call
                $orig = $this->getOriginalCall($call);
                if ($orig) {
                    $orig->merge($call);
                }
            }

            // process RETURNED results from calls and fulfill the promise
            while ($call = $this->ipc->get(self::MSG_RETURN)) {
                if ($this->isCallStale($call)) {
                    continue;
                }

                // update local cached call
                $orig = $this->getOriginalCall($call);
                if ($orig) {
                    $orig->merge($call);
                }

                $this->doPromise($call);
                $this->removeCall($call);
            }

            // Handle reaped children after calls are received to prevent race-condition
//            $this->handleReapedChildren();
        } catch (\Exception $e) {
            $this->error($e);
        }
    }

    private function doPromise(Call $call, $type = 'fulfill')
    {
        // Don't use the Call from the IPC message since it won't have the Promise reference
        $localCall = $this->getOriginalCall($call);
        if ($localCall) {
            $promise = $localCall->getPromise();
            switch ($type) {
                case 'fulfill':
                    if (!$promise->isState(Promise::FULFILLED)) {
                        $promise->fulfill($localCall->getResult());
                    }
                    break;
                case 'reject':
                case 'died':
                    if (!$promise->isState(Promise::REJECTED)) {
                        $promise->reject($localCall->getResult());
                    }
            }

            // add ON_RETURN callback if promise is empty. Note, this is not the best way to do this but I wanted to
            // give user-code an option either using the ON_RETURN or the Promise w/o causing duplicate callback.
            if ($promise->isEmpty()) {
                switch ($type) {
                    case 'fulfill':
                        if (!empty($this->events[self::ON_RETURN])) {
                            $promise->then($this->events[self::ON_RETURN]);
                        }
                        break;
                    case 'reject':
                        if (!empty($this->events[self::ON_TIMEOUT])) {
                            $promise->otherwise($this->events[self::ON_TIMEOUT]);
                        }
                        break;
                    case 'died':
                        if (!empty($this->events[self::ON_DIED])) {
                            $promise->otherwise($this->events[self::ON_DIED]);
                        }
                        break;
                }
            }
        }
    }

    private function isCallStale(Call $call)
    {
        // call ID is unknown. Probably existed from a previous run of the daemon
        if (!isset($this->calls[$call->getId()])) {
            $this->error("Warning: Stale call #%d [%s] found in message queue 0x%08x", $call->getId(), Call::getStatusText($call), $this->guid);
            $this->removeCall($call);
            return true;
        }
        return false;
    }

    /**
     * TaskInterface entry point. Called from forked child.
     */
    public function run()
    {
        $this->debug(5, 'start');
        $daemon = Daemon::getInstance();

        mt_srand(); // each child re-seeds the random number generator
        $entropy = abs(round((mt_rand(-1000, 1000)) / 100, 0));
        $start = microtime(true);
        $last = $start;

        $vary = function ($value) use ($entropy) {
            static $maxPct = 25; // entropy can vary by this percentage
            return $value ? round($value + $value * (min($entropy, $maxPct) / 100)) : 0;
        };

        $maxCalls = $vary($this->maxCalls);
        $maxRuntime = $vary($this->maxRuntime);
        $timeLeft = $maxRuntime;
        $echoWait = true;

        while (!$daemon->isShutdown()) {
            if ($this->autoRestart) {
                $rt = microtime(true) - $start;
                $isMaxCalls = $maxCalls && $this->callCount >= $maxCalls;
                $isMinRuntime = $rt >= $this->minRuntime;
                $isMaxRuntime = $maxRuntime && $rt >= $maxRuntime;
                if ($isMaxRuntime || $isMinRuntime && $isMaxCalls) {
                    break;
                }
            }

            try {
                if ($echoWait) {
                    $this->debug(4, "Waiting for call [Calls=%d/%s] [Time=%ds/%s]",
                        $this->callCount,
                        $maxCalls ?: 'Inf',
                        microtime(true) - $start,
                        $maxRuntime ? $maxRuntime . 's' : 'Inf'
                    );
                    $echoWait = false;
                }

                // block if autoRestart is false and maxRuntime is > 0
                $call = $this->ipc->get(self::MSG_CALL, !($this->autoRestart && $this->maxRuntime > 0));
                if (!$call) {
                    if ($this->autoRestart) {
                        usleep(20000); // yield cpu
                    }
                    continue;
                }

                $this->debug(4, "Call received [Call=%d] [Method=%s]", $call->getId(), $call->getMethod());
                $this->callCount++;
                $echoWait = true;
                if ($daemon->isDebugLevel(5)) {
                    $this->debug(5, "Received Call %d: METHOD=%s(%s)", $call->getId(),
                        $call->getMethod(), self::serializeArguments($call->getArguments()));
                }

                if ($call->is(Call::CANCELLED)) {
                    $this->log("Call %d cancelled", $call->getId());
                    continue;
                }

                // Send RUNNING ack to the parent
                $call->running();
                if (!$this->ipc->put($call)) {
                    $this->error("Could not send RUNNING ACK for Call %d", $call->getId());
                }

                // Do the actual callback (blocking). The MAX Runtime option is not checked here.
                $result = null;
                try {
                    $result = $this->callSubject($call);
                } catch (\Exception $e) {
                    $this->error("callSubject Exception: " . $e->getMessage());
                    $call->incErrors();
                }

                // Send RETURN ack to the parent
                $call->returned($result);
                if (!$this->ipc->put($call)) {
                    $this->error("Could not send RETURNED ACK for Call %d", $call->getId());
                }

                if ($this->allowWakeup) {
                    $daemon->wakeup();
                }

                if ($this->autoRestart) {
                    $timeLeft -= microtime(true) - $last;
                    if ($timeLeft <= 0) {
                        break;
                    }
                }
            } catch (\Exception $e) {
                $this->error($e->getMessage());
            }
        }

        $this->debug(5, 'end [Calls=%d/%s] [Time=%ds/%s]',
            $this->callCount,
            $maxCalls ?: 'Inf',
            microtime(true) - $start,
            $maxRuntime ? $maxRuntime . 's' : 'Inf'
        );
    }

    /**
     * Call the callback on the subject and return the result.
     *
     * @param Call $call
     * @return mixed
     */
    private function callSubject(Call $call)
    {
        if (method_exists($this->subject, $call->getMethod())) {
            return call_user_func_array([$this->subject, $call->getMethod()], $call->getArguments());
        }

        if (is_callable($this->subject)) {
            return call_user_func_array($this->subject, $call->getArguments());
        }

        return null;
    }

    public function getGroup()
    {
        return $this->alias;
    }

    /**
     * Return the matching Call from the local calls array. The only difference in the returned object to that of a
     * Call from SHM is it will contain the Promise object.
     *
     * @param Call|int $id
     * @return Call
     */
    public function getOriginalCall($id)
    {
        if ($id instanceof Call) {
            $id = $id->getId();
        }
        return isset($this->calls[$id]) ? $this->calls[$id] : null;
    }

    /**
     * Find a call that matches the PID or null if not found.
     *
     * @param int $pid
     * @return Call
     */
    public function findOriginalCall($pid)
    {
        foreach ($this->calls as $call) {
            if ($call->getPid() == $pid) {
                return $call;
            }
        }
        return null;
    }

    /**
     * Count the error and throw a fatal error if the threshold has been reached.
     *
     * @param $type
     */
    public function countError($type)
    {
        if (!isset($this->errorCounts[$type])) {
            $this->errorCounts[$type] = 0;
        }
        $this->errorCounts[$type]++;
        if ($this->errorCounts[$type] > self::$ERROR_THRESHOLDS[$type][Daemon::getInstance()->isParent() ? 1 : 0]) {
            $this->fatalError('IPC "%s" Error Threshold Reached [%d]', $type, $this->errorCounts[$type]);
        }
    }

    /**
     * @return bool
     */
    public function getAllowWakeup()
    {
        return $this->allowWakeup;
    }

    /**
     * If enabled, the mediator will attempt to wakeup the parent daemon every time a call is returned.
     * This allows the parent daemon to process the return value immediately w/o waiting on the
     * {@link Daemon::$loopInterval}.
     *
     * Caution: If many calls per second are answered by this mediator then the daemon will never get a chance to
     * rest and may cause the CPU to spike.
     *
     * <br/>
     * <b>This feature is experimental and in my tests it has been very unreliable with the SysV IPC class.</b>
     *
     * @param bool $allow
     * @return $this
     */
    public function setAllowWakeup($allow)
    {
        $this->allowWakeup = (bool)$allow;
        return $this;
    }

    protected function setLogArguments($args, $type = 'log')
    {
        $msg = &$args[is_int($args[0]) ? 1 : 0];
        $msg = sprintf("%s [%s]: %s%s", StringUtil::baseClassName($this), $this->alias, $type == 'error' ? 'Error: ' : '', $msg);
        return $args;
    }
}