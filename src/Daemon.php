<?php

namespace Lifo\Daemon;

use Exception;
use Lifo\Daemon\Event\DaemonEvent;
use Lifo\Daemon\Event\ErrorEvent;
use Lifo\Daemon\Event\LogEvent;
use Lifo\Daemon\Event\PidEvent;
use Lifo\Daemon\Event\SignalEvent;
use Lifo\Daemon\Event\StatsEvent;
use Lifo\Daemon\Exception\CleanErrorException;
use Lifo\Daemon\IPC\IPCInterface;
use Lifo\Daemon\Mediator\Mediator;
use Lifo\Daemon\Plugin\PluginInterface;
use Lifo\Daemon\Plugin\ProcessManagerInterface;
use Lifo\Daemon\Task\TaskInterface;
use LogicException;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Singleton Daemon class. Extend this class to create a new daemon. A basic daemon can be created by just implementing
 * 2 methods in your parent class: {@link initialize}, {@link execute}, optionally {@link teardown}.
 * Call the {@link run} method to start the daemon.
 *
 * <br/>
 * The Daemon uses a plugin architecture to make it easy for users to add their own functionality to the daemon
 * without having to resort to overriding various internal methods.
 *
 * <br/>
 * <em>
 * The basis for this library were inspired by the {@link https://github.com/shaneharter/PHP-Daemon PHP-Daemon} library
 * from Shane Harter on GitHub.
 * Unfortunately, his library was abandoned, was written in PHP5.3, had no namespacing, no package management and
 * auto-loader like Composer. I choose to create an entirely new library instead of forking and modifying his original
 * library for educational purposes. I do require some extra dependencies, but by using
 * {@link http://getcomposer.org/ Composer} for package management makes this a trivial issue.
 * </em>
 *
 * <br/>
 * <em>
 * The library has been rewritten to be PSR-4 compliant and requires PHP v7.4+. Certain features from the original
 * library have been modified, enhanced or removed. Some features are based on other libraries, such as:
 * the {@link http://symfony.com/doc/current/components/event_dispatcher.html Symfony EventDispatcher Component}.
 * </em>
 *
 * @see    https://github.com/lifo101
 * @see    https://github.com/shaneharter/PHP-Daemon
 * @author Jason Morriss <lifo101@gmail.com>
 * @since  1.0
 */
abstract class Daemon
{
    use ExceptionsTrait;

    const VERSION                     = '2.0';
    const PROCESS_MANAGER_PLUGIN_NAME = 'process_manager';

    private static ?Daemon $instance = null;

    /**
     * True if the current process is the parent process. Will be false in child tasks and workers.
     */
    private bool $parent = true;

    /**
     * The PID of the parent process when {@link $parent} is false
     */
    private ?int $parentPid;

    /**
     * If true extra debugging information is output when calling {@link debug}
     */
    private bool $debug = false;

    /**
     * Debug level. The higher the level the more debugging information is output. {@link $debug} must be enabled.
     */
    private int $debugLevel = 1;

    /**
     * If true, all calls to {@link log} and {@link debug} will output.
     */
    private bool $verbose = false;

    /**
     * Output when calling {@link log}. STDERR by default
     *
     * @var callable|resource|string|null
     */
    private $output = null;

    /**
     * Main event loop frequency in fractional seconds.
     */
    private float $loopInterval = 1.0;

    /**
     * Minimum sleep time for the event loop in microseconds.
     *
     * @see setLoopSleepMin()
     * @var int
     */
    private int $loopSleepMin = 10;

    /**
     * Total iterations of the main event loop.
     */
    private int $loopIterations = 0;

    /**
     * Idle probability between 0..1. Used when {@link $loopInterval} is 0.
     * The higher the number the more likely {@link DaemonEvent::ON_IDLE}.
     */
    private float $idleProbability = 0.5;

    /**
     * Application auto-restart frequency in seconds. Set to 0 to disable.
     * Setting this to any positive number will cause the daemon to restart itself gracefully at the interval specified
     * (in seconds).
     *
     * This can help prevent memory leaks from crashing the daemon. Ultimately you shouldn't rely on this feature
     * and should try and make sure your daemon doesn't leak memory, but in some cases, like when running your
     * application daemon in "debug" mode for long periods, you'll want it to restart occasionally.
     */
    private int $autoRestartInterval = 0;

    /**
     * Minimum restart threshold in seconds.
     * If the daemon has not been running at least this long it will not auto-restart due to a recoverable error.
     * Prevents the daemon from repeatedly restarting during an initial error.
     */
    private int $minRestartThreshold = 10;

    /**
     * If true the daemon will fork into the background before the main event loop starts.
     */
    private bool $daemonize = false;

    /**
     * True if the daemon is shutting down
     */
    private bool $shutdown = false;

    /**
     * True if the daemon should be restarted at the next shutdown. Mainly used from within signal handler.
     */
    private bool $restart = false;

    /**
     * Main daemon PID
     */
    private int $pid;

    /**
     * The micro timestamp the daemon loop started at
     */
    private float $loopStart = 0;

    /**
     * Event Dispatcher. A default dispatcher will be created unless the sub-class creates one.
     */
    private ?EventDispatcherInterface $dispatcher = null;

    /**
     * True if {@link doInitialize} has been called.
     */
    private bool $initialized = false;

    /**
     * Active plugins
     *
     * @var PluginInterface[]|array[]
     */
    private array $plugins = [];

    /**
     * Active workers
     *
     * @var Mediator[]
     */
    private array $workers = [];

    /**
     * Main daemon log filename
     */
    private ?string $logFile = null;

    /**
     * Main daemon log handle
     *
     * @var resource
     */
    private $logHandle;

    /**
     * the INODE of the currently opened log file
     */
    private ?int $logNode = null;

    /**
     * Are log file advanced checks enabled? Setting to true will allow better detection when a log file is
     * rotated via external processes.
     */
    private bool $advancedLogChecks = false;

    /**
     * Log a message when the event loop takes longer then {@link $loopInterval}?
     */
    private bool $logLoopWait = true;

    /**
     * Trigger shutdown on SIGINT. User application could set this to false and listen on the
     * {@link DaemonEvent::ON_SIGNAL} event to perform custom code instead.
     */
    private bool $shutdownOnInterrupt = true;

    /**
     * If true, the Daemon will intercept SIGUSR1 signals and dump all stats to the log file, will also echo to the
     * console if {@link Daemon::isVerbose} is true.
     */
    private bool $dumpOnSignal = true;

    /**
     * The initial size of the currently opened log file
     */
    private int $logSize = 0;

    /**
     * event object passed to dispatched events. A single copy is made for performance reasons.
     * Note: If $event->stopPropagation() is called on the event it must be recreated.
     *
     * Todo: Evaluate if keeping this object around saves any performance in the main {@link loop}
     */
    private ?DaemonEvent $event;

    /**
     * Miscellaneous global stats for the daemon. Merged with current stats in {@link dump}
     */
    protected array $stats = [
        // Tracks the amount of memory the script was using before the main loop starts. Allows an application to
        // determine if it's growing memory usage overtime.
        'initial_memory' => [
            'usage'      => 0,
            'usage_real' => 0,
            'peak'       => 0,
            'peak_real'  => 0,
        ]
    ];

    /**
     * Track total events fired
     *
     * @var int[]
     */
    private array $dispatched = [];

    /**
     * Command to use for daemon restarts. Set to null to automatically build a command in {@link buildCommand}
     */
    private ?string $command = null;

    /**
     * Allow ANSI colored stats output
     */
    private bool $ansi = true;

    /**
     * How many times the signal SIGINT has been caught
     *
     * @var int
     */
    private int $interrupt = 0;

    /**
     * String format for DEBUG messages. Messages being logged via a call to {@link debug} will be passed to
     * {@link sprintf} using this format string.
     */
    private string $debugFormat = 'DEBUG: %s';

    /**
     * This is a singleton class so the constructor is not meant to be publicly called.
     * Use {@link getInstance} instead.
     */
    protected function __construct()
    {
        $this->event = new DaemonEvent();
        $this->pid = getmypid() ?: null; // don't want to call event handler with setPid()
        $this->parentPid = $this->pid;
        $this->setOutput(null);
    }

    public function isDumpOnSignal(): bool
    {
        return $this->dumpOnSignal;
    }

    /**
     * If true, the Daemon will intercept the SIGUSR1 event and dump all stats to the log. Will echo to the console (if
     * isVerbose is true).
     *
     * @param bool $dumpOnSignal
     *
     * @return $this
     */
    public function setDumpOnSignal(bool $dumpOnSignal): self
    {
        $this->dumpOnSignal = $dumpOnSignal;
        return $this;
    }

    private function __clone()
    {
        // cloning is disabled to the public
    }

    public function __destruct()
    {
        if (!$this->parent) {
            return;
        }

        $this->teardown();
    }

    /**
     * Return the singleton instance for the Daemon.
     *
     * The daemon is not initialized until {@link run} is called.
     *
     * @return $this
     */
    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new static;
        }
        return self::$instance;
    }

    /**
     * Helper function that runs the callback given while blocking the signals provided.
     *
     * @param int|int[] $signals  List of signals to block
     * @param callable  $callback function to call
     *
     * @return mixed Return from callback
     */
    public static function blockSignals($signals, callable $callback)
    {
        pcntl_sigprocmask(SIG_BLOCK, (array)$signals);
        $return = call_user_func($callback);
        pcntl_sigprocmask(SIG_UNBLOCK, (array)$signals);
        return $return;
    }

    protected function teardown()
    {
        try {
            $this->shutdown();

            // shutdown plugins
            foreach ($this->plugins as $plugin) {
                // some plugins may not be loaded yet; so check for object instance first
                if ($plugin instanceof PluginInterface) {
                    $plugin->teardown();
                }
            }

            // shutdown workers
//            foreach ($this->workers as $worker) {
//                if ($worker instanceof Mediator) {
//                    $worker->teardown();
//                }
//            }

            // close log last; since plugins and workers may be using it
            $this->closeLog();
        } catch (Exception $e) {
            $this->fatalError($e);
        }
    }

    /**
     * One-time daemon initialization.
     * Called after plugins and signals are installed. Daemon will already be forked, if configured to do so.
     *
     * You should setup any workers your daemon application requires here.
     */
    protected function initialize()
    {
        // no-op
    }

    /**
     * Main application logic goes here. Called every loop cycle.
     */
    abstract protected function execute();

    /**
     * Main entry point for application loop.
     *   * Call this after configuring the daemon.
     *   * Loops until daemon is shutdown.
     *   * The daemon is not forked into the background until this method is called (if {@link $daemonize} is true)
     */
    public function run()
    {
        if (!$this->doInitialize()) {
            return;
        }

        $this->debug("Daemon::run loop started");

        $this->stats['initial_memory']['usage'] = memory_get_usage();
        $this->stats['initial_memory']['usage_real'] = memory_get_usage(true);
        $this->stats['initial_memory']['peak'] = memory_get_peak_usage();
        $this->stats['initial_memory']['peak_real'] = memory_get_peak_usage(true);

        try {
            $this->loop();
        } catch (Exception $e) {
            $this->fatalError($e);
        }
    }

    protected function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getLoopIterations(): int
    {
        return $this->loopIterations;
    }

    public function getLoopInterval(): float
    {
        return $this->loopInterval;
    }

    /**
     * @param float|null $interval
     *
     * @return $this
     */
    public function setLoopInterval(?float $interval): self
    {
        if ($interval !== null && $interval < 0.0) {
            throw self::createOutOfBoundsException($interval, sprintf("loopInterval must be NULL or a float >= 0; \"%s\" (%s) was given", $interval, gettype($interval)));
        }
        $this->loopInterval = $interval;
        return $this;
    }

    /**
     * Minimum sleep time for the event loop in microseconds.
     */
    public function getLoopSleepMin(): int
    {
        return $this->loopSleepMin;
    }

    /**
     * Minimum sleep time for the event loop in microseconds. For very fast loops it's important to yield a little bit
     * of CPU otherwise your Daemon will take up 100% CPU all the time.
     *
     * This takes effect under two circumstances:
     *
     * If {@link $loopInterval} is 0 or if {@link execute} took longer than {@link $loopInterval}.
     *
     * @param int $loopSleepMin
     *
     * @return $this
     */
    public function setLoopSleepMin(int $loopSleepMin): Daemon
    {
        $this->loopSleepMin = $loopSleepMin;
        return $this;
    }

    public function getIdleProbability(): float
    {
        return $this->idleProbability;
    }

    /**
     * Set idle probability between 0..1. Used when {@link $loopInterval} is 0.
     * The higher the number the more likely {@link DaemonEvent::ON_IDLE}.
     *
     * @param float $probability
     *
     * @return $this
     */
    public function setIdleProbability(float $probability): self
    {
        if ($probability < 0.0 || $probability > 1.0) {
            throw self::createOutOfBoundsException($probability, sprintf("idleProbability must be a float between 0..1; \"%s\" (%s) was given", $probability, gettype($probability)));
        }
        $this->idleProbability = $probability;
        return $this;
    }

    public function getAutoRestartInterval(): int
    {
        return $this->autoRestartInterval;
    }

    /**
     * Set Daemon auto restart interval. The daemon will restart once it's been running for at least this many seconds.
     * Auto-restart will only occur when {@link $daemonize} is true.
     *
     * @param int $interval
     *
     * @return $this
     */
    public function setAutoRestartInterval(int $interval): self
    {
        if ($interval < 0) {
            throw self::createOutOfBoundsException($interval, sprintf("autoRestartInterval must be an integer >= 0; \"%s\" (%s) was given", $interval, gettype($interval)));
        }
        $this->autoRestartInterval = $interval;
        return $this;
    }

    /**
     * Set the minimum restart interval in seconds. Must be less than {@link $autoRestartInterval}.
     *
     * Prevents the daemon from restarting if it crashes within this time interval.
     *
     * @param int $interval
     *
     * @return $this
     */
    public function setMinRestartThreshold(int $interval): self
    {
        if ($interval < 0) {
            throw self::createOutOfBoundsException($interval, sprintf("minRestartInterval must be an integer >= 0; \"%s\" (%s) was given", $interval, gettype($interval)));
        }
        $this->minRestartThreshold = $interval;
        return $this;
    }

    public function getMinRestartThreshold(): int
    {
        return $this->minRestartThreshold;
    }

    /**
     * Set the Event Dispatcher.
     *
     * The dispatcher cannot be changed once the daemon is initialized since some daemon wiring and plugins will
     * already have added listeners to it. Changing it would break the daemon.
     *
     * @param EventDispatcherInterface|null $dispatcher
     *
     * @return $this
     */
    public function setEventDispatcher(?EventDispatcherInterface $dispatcher): self
    {
        if ($this->initialized) {
            throw new LogicException("Event Dispatcher can not be changed once the Daemon is initialized");
        }
        $this->dispatcher = $dispatcher;
        return $this;
    }

    /**
     * Return the current event dispatcher. If none is defined a new object is created first.
     */
    public function getEventDispatcher(): EventDispatcher
    {
        return $this->dispatcher ??= new EventDispatcher();
    }

    /**
     * Dispatch an event to the Event bus.
     *
     * @param string           $eventName
     * @param DaemonEvent|null $event
     *
     * @return Event|DaemonEvent
     */
    public function dispatch(string $eventName, DaemonEvent $event = null)
    {
        $event ??= $this->event;
        if (!isset($this->dispatched[$eventName])) {
            $this->dispatched[$eventName] = 1;
        } else {
            $this->dispatched[$eventName]++;
        }
        return $this->getEventDispatcher()->dispatch($event, $eventName);
    }

    /**
     * Add an listener to the event dispatcher.
     *
     * @param string   $eventName Event to listen to.
     * @param callable $callback  Callback.
     * @param int      $priority  The higher this value, the earlier the listener is called within the chain.
     *
     * @return $this
     */
    public function on(string $eventName, callable $callback, int $priority = 0): self
    {
        if (!is_callable($callback)) {
            throw self::createInvalidArgumentException(func_get_args(), 'Invalid callable argument #2');
        }

        $this->getEventDispatcher()->addListener($eventName, $callback, $priority);
        return $this;
    }

    /**
     * Remove an event handler to the dispatcher.
     *
     * @param string   $eventName Event name to remove from.
     * @param callable $callback  Callback must match the callable that was previously set.
     *
     * @return $this
     */
    public function off(string $eventName, callable $callback): self
    {
        $this->getEventDispatcher()->removeListener($eventName, $callback);
        return $this;
    }

    /**
     * Daemonize flag to fork the main process into the background.
     *
     * @param bool $daemonize
     *
     * @return Daemon
     */
    public function setDaemonize(bool $daemonize): self
    {
        if ($this->initialized && $this->daemonize != $daemonize) {
            throw new LogicException("Daemonize option can not be changed once the Daemon is initialized");
        }
        $this->daemonize = $daemonize;
        return $this;
    }

    public function isDaemonize(): bool
    {
        return $this->daemonize;
    }

    /**
     * Return the total microseconds the daemon has been running. Will be 0 until the {@link run} method is called.
     *
     * @return float Total time the daemon loop has been running.
     */
    public function getRuntime(): float
    {
        if (!$this->loopStart) {
            return 0.0;
        }

        return microtime(true) - $this->loopStart;
    }

    /**
     * Set the shutdown flag so the daemon will shutdown gracefully at the first opportunity.
     * Once enabled, you cannot disable it.
     *
     * @param bool $flag
     *
     * @return $this
     */
    public function setShutdown(bool $flag): self
    {
        if ($this->shutdown && !$flag) {
            $this->log("Cannot disable shutdown flag once shutdown has started");
            return $this;
        }
        $this->shutdown = $flag;
        return $this;
    }

    /**
     * Start shutdown process.
     *
     * @return $this
     */
    public function shutdown(): self
    {
        if (!$this->shutdown) {
            $this->shutdown = true;
            if ($this->parent) {
                $this->dispatch(DaemonEvent::ON_SHUTDOWN);
            }
        }
        return $this;
    }

    /**
     * Output a debug message if {@link $debug} is true and optionally if {@link $debugLevel} is higher than
     * the specified $level.
     *
     * @param int|string $level   Optional debug level (int) or message (string)
     * @param mixed|null $msg     Message string if $level is not specified
     * @param mixed      $varargs Extra arguments (arg1, arg2, etc) to pass to {@link sprintf}
     */
    public function debug($level, $msg = null, $varargs = null)
    {
        if (!$this->debug) {
            return;
        }

        $args = func_get_args();
        if (is_int($level)) {
            $args = array_slice($args, 1); // ignore $level
        } else {
            $level = 1;
        }

        if ($level <= $this->debugLevel) {
//            $args[0] = sprintf('DEBUG%s: %s', $level == 1 ? ' ' : $level, $args[0]);
            $args[0] = sprintf($this->debugFormat, $args[0]);
            call_user_func_array([$this, 'log'], $args);
        }
    }

    /**
     * Write a message to the log. Will also output to STDERR if {@link verbose} is true. Intelligently determines
     * if the current log file has been rotated and will reopen the logfile, as needed.
     *
     * The sub-class may override this to provide its own functionality or plugins may intercept this behavior by
     * listening on the {@link DaemonEvent::ON_LOG} event. If propagation of the event is stopped then the log message
     * will not be handled directly by this method.
     *
     * @param mixed $msg     Message string, array or object to log
     * @param mixed $varargs Extra arguments (arg1, arg2, etc) to pass to {@link sprintf}
     *
     */
    public function log($msg, $varargs = null)
    {
        // prevent potential infinite loop when recursively calling log()
        static $inside = false;

        if (is_scalar($msg)) {
            $args = array_slice(func_get_args(), 1);
            if ($args) {
                $msg = vsprintf($msg, $args);
            }
        } else {
            $msg = StringUtil::dump($msg, false);
        }

        $event = $this->dispatch(DaemonEvent::ON_LOG, new LogEvent($msg));
        if ($event->isPropagationStopped()) {
            return;
        }

        // reopen logfile if necessary
        if ($this->isLogFileChanged()) {
            $isOpened = is_resource($this->logHandle);
            if (!$this->reopenLog()) {
                trigger_error(sprintf('Warning: Could not open logfile %s', $this->logFile), E_USER_WARNING);
            }
            if ($isOpened && !$inside) {
                $inside = true;
                $this->log(sprintf('Log file "%s" reopened', $this->logFile));
                $inside = false;
            }
        }

        $msg = $this->getLogPrefix() . rtrim($msg, PHP_EOL) . PHP_EOL;
        if ($this->logHandle) {
            @fwrite($this->logHandle, $msg);
        }

        $this->output($msg);
    }

    /**
     * Log runtime error and dispatch event.
     *
     * @param string|Exception $err
     * @param mixed            $varargs Extra arguments (arg1, arg2, etc) to pass to {@link sprintf}
     */
    public function error($err, $varargs = null)
    {
        if ($err instanceof Exception) {
            $msg = sprintf('Uncaught Daemon Exception: %s in file: %s on line: %s%sPlain Stack Trace:%s%s',
                $err->getMessage(), $err->getFile(), $err->getLine(), PHP_EOL, PHP_EOL, $err->getTraceAsString());
        } elseif (is_scalar($err)) {
            $args = array_slice(func_get_args(), 1);
            $msg = $args ? vsprintf($err, $args) : $err;
        } else {
            $msg = $err;
        }

        $event = $this->dispatch(DaemonEvent::ON_ERROR, new ErrorEvent($msg));
        if (!$event->isPropagationStopped()) {
            $this->log($msg);
        }
    }

    /**
     * Log fatal error and if possible restart the daemon. Current daemon process is halted after calling this method.
     *
     * @param string|Exception $err
     * @param mixed            $varargs Extra arguments (arg1, arg2, etc) to pass to {@link sprintf}
     */
    public function fatalError($err, $varargs = null)
    {
        if ($err instanceof CleanErrorException) {
            // don't want stack trace for clean errors
            $msg = 'Fatal Error: ' . $err->getMessage();
        } else if ($err instanceof Exception) {
            $msg = sprintf('Fatal Error: %s in file: %s on line: %s%sPlain Stack Trace:%s%s',
                $err->getMessage(), $err->getFile(), $err->getLine(), PHP_EOL, PHP_EOL, $err->getTraceAsString());
        } elseif (is_scalar($err)) {
            $msg = vsprintf($err, array_slice(func_get_args(), 1));
        } else {
            $msg = $err;
        }

        $this->error($msg);

        if ($this->parent) {
            $this->log(StringUtil::baseClassName($this) . " Shutdown");

            // only restart if we were fully initialized and if we've been running longer than the restart threshold
            if ($this->initialized
                && $this->daemonize
                && !$this->shutdown
                && !($err instanceof CleanErrorException)
                && $this->getRuntime() > $this->minRestartThreshold
            ) {
                $this->restart();
            }
        }

        exit(1);
    }

    /**
     * Output a message to the current output destination (stream, callable)
     *
     * @param string $msg
     */
    public function output(string $msg)
    {
        if (!$this->isVerbose()) {
            return;
        }

        switch (true) {
            case is_resource($this->output):
                @fwrite($this->output, $msg);
                break;
            case is_callable($this->output):
                call_user_func($this->output, $msg);
                break;
        }
    }

    /**
     * Return the log prefix. By default this is a timestamp and the PID of the current process.
     *
     * @return string The log prefix
     */
    protected function getLogPrefix(): string
    {
        $time = explode(' ', microtime(), 2); // array (msec, sec)
        return sprintf("%s.%04d: %-6d %6d: ",
            date('Y-m-d H:i:s', $time[1]),
            str_pad(substr(round($time[0], 4), 2), 4, '0'),
            $this->parentPid,
            getmypid()
        );
    }

    /**
     * Dump stats to the log and possibly the console (if {@link $debug} is true)
     *
     * @param array|null $stats Optional array of stats to dump. Defaults to all stats.
     */
    public function dump(array $stats = null)
    {
        static $dumper = null, $clone = null;

        if ($stats === null) {
            $stats = $this->stats();
        }

        if ($dumper || class_exists('\Symfony\Component\VarDumper\Dumper\CliDumper')) {
            if (null === $dumper) {
                $dumper = new CliDumper();
                $clone = new VarCloner();
            }

            // output colorized stats to console
            if ($this->debug && $this->isVerbose()) {
                $dumper->setColors($this->ansi);
                $dumper->dump($clone->cloneVar($stats), STDERR);
            }

            // write stats to memory; this is done separately so the CLI can be colorized and the log is not.
            $output = fopen('php://memory', 'r+b');
            $dumper->setColors(false);
            $dumper->dump($clone->cloneVar($stats), $output);

            // don't echo the log to the console since we did it above already (in color)
            $v = $this->verbose;
            $this->verbose = false;
            $this->log(stream_get_contents($output, -1, 0));
            $this->verbose = $v;

            fclose($output);
        } else {
            $json = json_encode($stats, JSON_PRETTY_PRINT) . PHP_EOL;
            $this->log($json);
        }
    }

    /**
     * Collect some statistics including memory, cpu, etc. Plugins can add their own stats by listening to the
     * {@link DaemonEvent::ON_STATS} event.
     *
     * @return array raw stats array
     */
    public function stats(): array
    {
        $stats = [
            'pid'      => $this->pid,
            'user'     => [
                'uid'   => posix_geteuid(),
                'gid'   => posix_getegid(),
                'name'  => posix_getpwuid(posix_geteuid())['name'],
                'group' => posix_getgrgid(posix_getegid())['name'],
//                'groups' => array_map(function ($id) { return posix_getgrgid($id)['name']; }, posix_getgroups()),
            ],
            'parent'   => $this->parent,
            'shutdown' => $this->shutdown,
            'logFile'  => $this->logFile,
            'memory'   => [
                'initial'      => $this->stats['initial_memory']['usage'],
                'initial_kb'   => StringUtil::kbytes($this->stats['initial_memory']['usage']),
                'current'      => memory_get_usage(),
                'current_kb'   => StringUtil::kbytes(memory_get_usage()),
                'growth'       => $bytes = memory_get_usage() - $this->stats['initial_memory']['usage'],
                'growth_kb'    => StringUtil::kbytes($bytes),
                'real'         => memory_get_usage(true),
                'real_kb'      => StringUtil::kbytes(memory_get_usage(true)),
                'peak'         => memory_get_peak_usage(),
                'peak_kb'      => StringUtil::kbytes(memory_get_peak_usage()),
                'peak_real'    => memory_get_peak_usage(true),
                'peak_real_kb' => StringUtil::kbytes(memory_get_peak_usage(true)),
                'max'          => ini_get('memory_limit'),
            ],
            'load'     => sys_getloadavg(),
            'times'    => posix_times(),
            'runtime'  => $this->getRuntime(),
            'loop'     => [
                'start'      => date('Y-m-d H:i:s', floor($this->loopStart)),
                'interval'   => $this->loopInterval,
                'iterations' => $this->loopIterations,
            ],
            'events'   => $this->dispatched,
        ];

        /** @var StatsEvent $e */
        $e = $this->dispatch(DaemonEvent::ON_STATS, new StatsEvent($stats));
        $stats = $e->getStats();

        // track each plugin that is NOT loaded
        foreach ($this->plugins as $alias => $plugin) {
            if (!($plugin instanceof PluginInterface) && !isset($stats['plugins'][$alias])) {
                $stats['plugins'][$alias] = array_merge($plugin, ['loaded' => false]);
            }
        }
        return $stats;
    }

    /**
     * Returns true if {@link verbose} is true and {@link daemonize} is false.
     * You don't want to try and echo anything when daemonized, as it will cause the daemon to stall and/or crash.
     */
    public function isVerbose(): bool
    {
        return $this->verbose && !$this->daemonize;
    }

    /**
     * Returns the {@link verbose} flag
     *
     * @return bool
     */
    public function getVerbose(): bool
    {
        return $this->verbose;
    }

    /**
     *
     * @param bool $verbose
     *
     * @return $this
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * Return the current DEBUG status
     */
    public function getDebug(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * Set debug level. Increase level to get more debugging output during application runtime.
     * The core {@link Daemon} routines uses levels up to about 5. User-code can use any levels desired.
     *
     * @param int $level
     *
     * @return $this
     */
    public function setDebugLevel(int $level): self
    {
        $this->debugLevel = $level;
        return $this;
    }

    public function isDebugLevel(int $level): bool
    {
        return $level <= $this->debugLevel;
    }

    /**
     * Get current debug level.
     *
     * @return int
     */
    public function getDebugLevel(): int
    {
        return $this->debugLevel;
    }

    /**
     * Set {@link $debugFormat} string format for DEBUG messages. Messages being logged via a call to
     * {@link Daemon::debug} will be passed to {@link sprintf} using this format string
     * (eg: <code>sprintf($debugFormat, $msg)</code>).
     *
     * <br/>
     * At a minimum the format string must contain the string "%s" or be empty ""
     *
     * @param string|null $format
     *
     * @return $this
     */
    public function setDebugFormat(?string $format): self
    {
        // allow special case for empty string
        if ($format === null || $format === '') {
            $format = '%s';
        }

        if (strpos($format, '%s') === false) {
            throw self::createInvalidArgumentException(func_get_args(), 'Invalid format string in argument #1. The string "%s" must be present');
        }
        $this->debugFormat = $format;
        return $this;
    }

    /**
     * @return string
     */
    public function getDebugFormat(): string
    {
        return $this->debugFormat;
    }

    /**
     * Get the current log filename
     */
    public function getLogFile(): ?string
    {
        return $this->logFile;
    }

    /**
     * Set's the logfile. If an existing log file was already opened it'll be closed.
     *
     * @param string $logFile
     *
     * @return $this
     */
    public function setLogFile(string $logFile): self
    {
        $this->closeLog();
        $this->logFile = $logFile;
        return $this;
    }

    public function getLogLoopWait(): bool
    {
        return $this->logLoopWait;
    }

    /**
     * Toggle the logging of a message when the event loop takes longer then {@link $loopInterval}
     *
     * @param bool $logLoopWait
     *
     * @return $this
     */
    public function setLogLoopWait(bool $logLoopWait): self
    {
        $this->logLoopWait = $logLoopWait;
        return $this;
    }

    /**
     * Toggle capturing SIGINT to trigger shutdown.
     *
     * @param bool $flag
     *
     * @return $this
     */
    public function setShutdownOnInterrupt(bool $flag): self
    {
        $this->shutdownOnInterrupt = $flag;
        return $this;
    }

    /**
     * If true, the Daemon will automatically shutdown when the SIGINT signal is caught.
     */
    public function isShutdownOnInterrupt(): bool
    {
        return $this->shutdownOnInterrupt;
    }

    /**
     * Open the current log file for writing. If the log file is already opened nothing happens.
     *
     * @return bool True if the log file was opened.
     */
    protected function openLog(): bool
    {
        if (!is_resource($this->logHandle)) {
            $this->logHandle = @fopen($this->logFile, 'a+');
            if ($this->logHandle) {
                // track the file info for later
                clearstatcache();
                $stat = fstat($this->logHandle);
                $this->logNode = $stat['ino'];  // will always be 0 on Windows
                $this->logSize = $stat['size'];
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * Reopen the log file. Closes it first, if necessary
     *
     * @return bool True if the log file was opened.
     */
    protected function reopenLog(): bool
    {
        return $this->closeLog()->openLog();
    }

    /**
     * Close the current log handle; if any.
     *
     * @return $this
     */
    protected function closeLog(): self
    {
        if (is_resource($this->logHandle)) {
            @fclose($this->logHandle);
            $this->logSize = 0;
            $this->logNode = null;
        }
        $this->logHandle = null;
        return $this;
    }

    public function isAdvancedLogChecks(): bool
    {
        return $this->advancedLogChecks;
    }

    /**
     * Should log file advanced checks be enabled? Setting to true will allow better detection when a log file is
     * rotated via external processes but will be slightly slower due to the filesystem accesses required.
     *
     * @param bool $flag
     *
     * @return $this
     */
    public function setAdvancedLogChecks(bool $flag): self
    {
        $this->advancedLogChecks = $flag;
        return $this;
    }

    /**
     * Determine if the logfile has changed (ie: renamed from log rotate). If advanced log checks are enabled log
     * rotations will be detected but at a cost of more filesystem accesses. Since logging is generally done sparsely
     * in production apps this shouldn't be too much of a problem.
     */
    protected function isLogFileChanged(): bool
    {
        if (!$this->logFile) {
            return false;
        }

        // log file isn't opened yet
        if (!is_resource($this->logHandle)) {
            return true;
        }

        // advanced checks are slower since it has to stat the filesystem on every call.
        // todo maybe use a frequency to only do this once in X calls
        if ($this->advancedLogChecks) {
            clearstatcache(true, $this->logFile);

            // if it doesn't exist it was deleted/renamed since our last call but wasn't rotated yet.
            if (!file_exists($this->logFile)) {
                return true;
            }

            $stat = @stat($this->logFile);
            if ($stat) {
                if ($this->logNode !== 0) {
                    // iNode was changed so the filename was most likely renamed/rotated
                    if ($stat['ino'] != $this->logNode) {
                        return true;
                    }
                }

                // checking file size (mainly for windows); If the current log file size when it was opened is now
                // todo; this only seems to work once on the same file ... need more testing
                if ($stat['size'] < $this->logSize) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Install a plugin into the daemon.
     *
     * The plugin system uses a simple Dependency Injection Container to install and run plugins within the daemon.
     * Plugins can be lazily loaded.
     *
     * @param string|object $class   Class name or instance of plugin. Must implement {@link PluginInterface}
     * @param string|array  $alias   Short alias name for plugin; defaults to snake_case of $class. If an array, use array as options.
     * @param array         $options Options to pass to the plugin.
     * @param bool          $lazy    If true the plugin is not instantiated until its first used.
     *
     * @return $this
     * @throws RuntimeException
     */
    public function addPlugin($class, $alias = null, array $options = [], bool $lazy = false): self
    {
        if (is_array($alias)) {
            $options = $alias;
            $alias = null;
        }

        if (!$alias && !($alias = StringUtil::fqcnToSnake($class, 'plugin'))) {
            throw self::createInvalidArgumentException(func_get_args(), 'Invalid plugin class argument #1. A valid plugin alias must be specified in argument #2');
        }

        try {
            if (!is_object($class) and !$lazy) {
                // instantiate the class if it's not lazy
                $plugin = new $class();
                if (!$plugin instanceof PluginInterface) {
                    throw self::createInvalidArgumentException(func_get_args(), sprintf('Invalid plugin class "%s"; Must implement %s', $class, PluginInterface::class));
                }
                $plugin->setup($options);
                $this->plugins[$alias] = $plugin;
            } else {
                // save the class name for now
                $this->plugins[$alias] = [
                    'class'   => $class,
                    'options' => $options
                ];

            }
        } catch (Exception $e) {
            $_class = get_class($e);
            $this->fatalError(new $_class(sprintf('Plugin "%s" (class: "%s") failed to load: %s', $alias, $class, $e->getMessage())));
        }

        return $this;
    }

    /**
     * Return the named worker instance. The worker will be setup the first time it's called.
     *
     * @param string $name
     *
     * @return Mediator|object
     */
    public function worker(string $name)
    {
        if (!isset($this->workers[$name])) {
            throw self::createInvalidArgumentException(func_get_args(), "Unknown worker requested \"$name\"");
        }

        $worker = $this->workers[$name];
        $worker->setup();

        return $worker;
    }

    /**
     * Return the named plugin instance. Lazy plugins will be initialized automatically.
     *
     * @param string $name
     *
     * @return PluginInterface The Plugin instance
     */
    public function plugin(string $name): PluginInterface
    {
        if (!isset($this->plugins[$name])) {
            throw self::createInvalidArgumentException(func_get_args(), "Unknown plugin requested");
        }

        $plugin = $this->plugins[$name];

        // lazy-load the plugin
        if (is_array($plugin)) {
            $class = $plugin['class'];
            $options = $plugin['options'];
            $plugin = new $class();
            if (!$plugin instanceof PluginInterface) {
                throw self::createInvalidArgumentException(func_get_args(), sprintf('Invalid plugin class "%s"; Must implement %s', $class, PluginInterface::class));
            }
            $plugin->setup($options);
            $this->plugins[$name] = $plugin;
        }

        return $plugin;
    }

    /**
     * Get the alias for a plugin based off its class name. If the plugin class isn't known null is returned.
     *
     * todo: maybe just have an array that maps the class to aliases? But then I'd have to maintain 2 arrays.
     *
     * @param string|object $class Class name to lookup
     *
     * @return string|null
     */
    public function getPluginAlias($class): ?string
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        foreach ($this->plugins as $alias => $plugin) {
            switch (true) {
                case $plugin instanceof PluginInterface:
                    if (get_class($plugin) == $class) {
                        return $alias;
                    }
                    break;
                case is_array($plugin):
                    if ($plugin['class'] == $class) {
                        return $alias;
                    }
                    break;
            }
        }
        return null;
    }

    /**
     * Create a background worker process.
     *
     * <br/>
     * This is where the most work of your application will be performed, inside a worker sub-process. The returned
     * {@link Mediator} object will not be initialized until you call a method on it. If you plan on using it
     * immediately you must call {@link Mediator::setup} on the object. If you fetch a worker using
     * {@link Daemon::worker} then it will be initialized automatically on first use.
     *
     * <br/>
     * $worker can be one of the following:
     * * A Closure: function(){}
     * * A callable: [$this, 'methodName']
     * * An object instance: new SimpleWorker()
     * * A class name string: 'Lifo\Daemon\Worker\SimpleWorker' // will be instantiated in forked process
     *
     * @param callable|object|string $worker
     * @param string|null            $alias Short alias name for worker. Defaults to snake_case of $worker.
     * @param IPCInterface|null      $ipc   IPC for parent/worker
     *
     * @return Mediator A new Mediator instance for the worker queue.
     */
    public function addWorker($worker, string $alias = null, IPCInterface $ipc = null): ?Mediator
    {
        if ($this->shutdown || !$this->parent) {
            $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            $t = end($t);
            $this->error(sprintf("%s: Unable to start worker in function %s%s%s(%s) in file %s on line %s",
                $this->shutdown ? 'Daemon in shutdown mode' : 'Background workers cannot start new workers',
                $t['class'],
                $t['type'],
                $t['function'],
                self::serializeArguments(array_slice(func_get_args(), 3)),
                $t['file'],
                $t['line']
            ));
            return null;
        }

        // If the worker is an object then default alias to the worker class name
        if (!$alias && !($alias = StringUtil::fqcnToSnake($worker, 'worker'))) {
            throw self::createInvalidArgumentException(func_get_args(), 'Invalid worker alias argument #2');
        }

        // there should be no need to overwrite a worker during the life of a daemon
        if (isset($this->workers[$alias])) {
            throw self::createInvalidArgumentException(func_get_args(), "Worker \"$alias\" already exists");
        }

        // instantiate the mediator for this worker
        $mediator = new Mediator($worker, $alias, $ipc);
        $this->workers[$alias] = $mediator;
        return $mediator;
    }

    /**
     * Run a task in the background.
     *
     * $task can be one of the following:
     * * A Closure: function(){...}
     * * A callable: ['myObject', 'methodName']
     * * An object instance of TaskInterface: new myTask()
     * * A class name string: 'Lifo\Daemon\Task\SimpleTask' // will be instantiated after the process is forked
     *
     * @param callable|TaskInterface|string $task
     * @param mixed                         $varargs Extra arguments (arg1, arg2, etc) to pass to the task
     *
     * @return Process|null A simple object representing the state of the process
     * @throws Exception
     */
    public function task($task, $varargs = null): ?Process
    {
        if ($this->shutdown || !$this->parent) {
            $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            $t = end($t);
            $this->error(sprintf("%s: Unable to start task in function %s%s%s(%s) in file %s on line %s",
                $this->shutdown ? 'Daemon in shutdown mode' : 'Background tasks cannot start new tasks',
                $t['class'],
                $t['type'],
                $t['function'],
                self::serializeArguments(array_slice(func_get_args(), 1)),
                $t['file'],
                $t['line']
            ));
            return null;
        }

        $group = null;
        $args = array_slice(func_get_args(), 1);
        if ($task instanceof TaskInterface) {
            $group = $task->getGroup();
            $callback = function () use ($task, $args) {
                $task->setup();
                call_user_func_array([$task, 'run'], $args);
                $task->teardown();
            };
        } elseif (is_string($task)) {
            if (!class_exists($task)) {
                throw new Exception('Invalid task: Class "' . $task . '" does not exist');
            }

            // Treat $task as a class name. Instantiate the class inside the callback
            $callback = function () use ($task, $args) {
                $obj = new $task();
                if ($obj instanceof TaskInterface) {
                    $obj->setup();
                    call_user_func_array([$obj, 'run'], $args);
                    $obj->teardown();
                } else {
                    throw new Exception('Invalid task: Must implement Lifo\Daemon\Task\TaskInterface');
                }
            };
        } else {
            $callback = function () use ($task, $args) {
                call_user_func_array($task, $args);
            };
        }

        $group ??= 'task';

        try {
            /** @var ProcessManagerInterface $pm */
            $pm = $this->plugin(self::PROCESS_MANAGER_PLUGIN_NAME);
            return $pm->fork($group, $callback);
        } catch (Exception $e) {
            $this->error($e);
        }

        return null;
    }

    /**
     * Fork the main process into the background right before the main loop starts.
     */
    private function fork()
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException("Could not fork daemon");
        } else if ($pid > 0) {
            // exit original parent process
            $this->debug("Daemon forked into child process. [PID=%d]", $pid);
            exit(0);
        } else {
            // the child has now become the master...
            $this->setParent(true)->setPid(getmypid());
            $this->parentPid = getmypid() ?: null;
            $this->onDaemonFork();
        }
    }

    /**
     * One time initialization of the daemon.
     */
    private function doInitialize(): bool
    {
        if ($this->initialized) {
            return true;
        }

        try {
            $this->debug("Daemon startup [Interval=%0.1fs] [Restart=%s]",
                $this->loopInterval,
                $this->autoRestartInterval ? StringUtil::elapsedFromSeconds($this->autoRestartInterval) : 'Never'
            );

            // pre-check
            $this->validateEnvironment();

            if ($this->daemonize) {
                $this->fork();
            }

            $this->setupSignals();

            // Lazily add ProcessManager plugin. The Subclass or other plugins can override this by adding their
            // own plugin matching this name.
            $this->addPlugin('Lifo\Daemon\Plugin\ProcessManager', self::PROCESS_MANAGER_PLUGIN_NAME, [], true);

            $this->on(DaemonEvent::ON_FORK, function () {
                $this->setParent(false)->setPid(getmypid());
                $this->onFork();
            });

            $this->on(DaemonEvent::ON_PARENT_FORK, function () {
                $this->onParentFork();
            });

            // allow sub-class to initialize
            $this->initialize();
            $this->dispatch(DaemonEvent::ON_INIT);

            // post-check (after plugins, etc are initialized)
            $this->validateEnvironment();

            $this->initialized = true;
        } catch (Exception $e) {
            $this->fatalError($e);
        }

        return true;
    }

    /**
     * Listen for any child forks.
     * This is called in the CHILD process anytime a child process is forked.
     */
    protected function onFork()
    {
    }

    /**
     * Listen for any child forks.
     * This is called in the PARENT process anytime a child process is forked.
     */
    protected function onParentFork()
    {
        // noop
    }

    /**
     * Called after the main daemon process forks into the background, right before {@link Daemon::initialize} is
     * called. The parent process can do special initialization here (eg: re-connect to DB resource).
     *
     * By default, this closes all standard I/O handles, STDIN, STDOUT, STDERR
     */
    protected function onDaemonFork()
    {
        // close standard I/O handles
        foreach ([STDIN, STDOUT, STDERR] as $h) {
            if (is_resource($h)) {
                fclose($h);
            }
        }
    }

    /**
     * Internal daemon loop. Loops until daemon is shutdown.
     *
     * Error handling is handled in {@link run}.
     */
    private function loop()
    {
        $this->loopStart = microtime(true);
        $this->loopIterations = 0;
        while ($this->parent && !$this->shutdown) {
            $this->loopIterations++;
            $start = microtime(true);

            $this->autoRestart();

            // allow the PRE_EXECUTE event to override the calling of execute()
            $event = $this->dispatch(DaemonEvent::ON_PRE_EXECUTE);
            if ($event->isPropagationStopped()) {
                $event->startPropagation();
            } else {
                $this->execute();
            }

            $event = $this->dispatch(DaemonEvent::ON_POST_EXECUTE);
            if ($event->isPropagationStopped()) {
                $event->startPropagation();
            }

            $this->wait($start);
        }

        if ($this->parent) {
            $this->dispatch(DaemonEvent::ON_SHUTDOWN);
            if ($this->restart) {
                $this->restart();
            } else {
                $this->debug("Shutdown");
            }
        }
    }

    /**
     * Wait at the end of each execute() call and determine if ON_IDLE events should be called.
     *
     * @param float $start The starting time of the loop iteration
     */
    private function wait(float $start)
    {
        if ($this->shutdown) {
            return;
        }
        pcntl_signal_dispatch();

        if ($this->isIdle($start)) {
            $this->dispatch(DaemonEvent::ON_IDLE);
        }

        $duration = microtime(true) - $start;
        $delta = $duration - $this->loopInterval;
        if (!$this->shutdown) {
            if ($delta < 0) {
                // we have excess time to wait
                $delta = abs($delta);
                // suppress child signals so children that exit during our sleep won't interrupt the timer.
                // any other signals sent will break the sleep and the loop will immediately execute again.
                $s1 = microtime(true);
                pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD]);
                usleep((int)($delta * 1000000)); # (int) cast to avoid "Deprecated: Implicit conversion from float messages"
                pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD]);

                // if elapsed is less than delta, then the sleep was interrupted by a signal (eg: SIGINT, SIGALRM)
                $elapsed = microtime(true) - $s1;
                if ($elapsed < $delta) {
                    $this->debug(5, "Daemon::wait: Sleep was interrupted. [Wait=%f] [Elapsed=%f] [Missed=%f]",
                        $delta, $elapsed, $delta - $elapsed
                    );
                }
            } else if ($delta > 0) {
                // loop took too long
                if ($this->loopInterval > 0 && $this->logLoopWait) {
                    $this->debug(2, sprintf("Daemon::wait: Loop took too long. [Interval=%f] [Duration=%f] [Extra=%f]",
                        $this->loopInterval, $duration, $delta
                    ));
                }

                // yield CPU a tiny bit
                if ($this->loopSleepMin > 0) {
                    usleep($this->loopSleepMin);
                }
            }
        }
    }

    /**
     * Returns true if we're idle based on the time and probability given
     *
     * @param float $start Start time of execute loop.
     *
     * @return bool
     */
    private function isIdle(float $start): bool
    {
        if ($this->loopInterval) {
            $end = $start + $this->loopInterval - 0.01;
            return $end && microtime(true) < $end;
        }

        return $this->idleProbability && mt_rand(1, 100) <= $this->idleProbability * 100;
    }

    /**
     * Auto restart the daemon if it's been forked into the background.
     * This allows for long-running daemons to be self-cleaning by releasing all memory and resources and starting
     * over gracefully w/o interrupting the normal application flow.
     * Event listeners can prevent the auto-restart by calling {@link DaemonEvent::stopPropagation()}.
     */
    private function autoRestart()
    {
        if (!$this->parent || !$this->daemonize) {
            return;
        }

        if ($this->autoRestartInterval && $this->getRuntime() >= $this->autoRestartInterval) {
            if (!$this->dispatch(DaemonEvent::ON_AUTO_RESTART)->isPropagationStopped()) {
                $this->restart();
            }
        }
    }

    /**
     * Restart the daemon as a new process.
     *
     * Gracefully shuts down and then executes the daemon as a new process.
     */
    protected function restart()
    {
        if (!$this->initialized || !$this->parent || !$this->daemonize) {
            $this->debug(3, "Restart request ignored. INIT=%s, PARENT=%s, DAEMON=%s",
                $this->initialized ? 'YES' : 'NO',
                $this->parent ? 'YES' : 'NO',
                $this->daemonize ? 'YES' : 'NO'
            );
            return;
        }

        $command = $this->buildCommand();
        $this->debug(3, "Restarting daemon with command: %s", $command);
        $this->teardown();

        // close resources to prevent exec() from hanging
        $handles = [STDIN, STDOUT, STDERR];
        foreach ($handles as $h) {
            if (is_resource($h)) {
                @fclose($h);
            }
        }

        exec($command);

        $this->debug(3, "Restart completed. Exiting original daemon process");
        exit(0);
    }

    /**
     * Manually set the restart command. Normally not needed, but allows User Code to define how the command is
     * restart if it can't build the command itself.
     *
     * @param string|null $command
     *
     * @return $this
     */
    public function setCommand(?string $command): self
    {
        $this->command = $command;
        return $this;
    }

    /**
     * Build the command that will restart the daemon. Should take into account any command line options that
     * were set when the daemon was originally started. E.G.: -d for daemonize, etc...
     *
     * By default this will use the same command found in the global {@link $argv} array and will add "-d" if
     * {@link $daemonize} is true.
     *
     * If {@link $command} is set it'll be used verbatim instead. See {@link setCommand}
     *
     * Override this if your daemon has the potential of being started with arguments that should not be used a second
     * invocation of the daemon. Or define a command using {@link setCommand}
     */
    protected function buildCommand(): string
    {
        global $argv;

        if (!empty($this->command)) {
            return $this->command;
        }

        $args = array_slice($argv, 1);
        if ($this->daemonize and !in_array('-d', $args)) {
            $args[] = '-d';
        }
        $exec = $this->getScriptPath();
        $command = implode(' ', array_merge([self::findPhp(), escapeshellcmd($exec)], array_map('escapeshellarg', $args)));

        // redirect so exec() will not block on output
        if ('/' == DIRECTORY_SEPARATOR) {
            $command .= " > /dev/null";
        }

        return $command;
    }

    /**
     * Return the absolute filename of the script executable. This is used when the daemon tries to restart. It must
     * be the full path name of the script to run.
     *
     * @return string
     */
    protected function getScriptPath(): string
    {
        return realpath($_SERVER['SCRIPT_FILENAME']);
    }


    /**
     * Return the PHP executable
     *
     * @param bool $includeArgs Whether or not include command arguments
     *
     * @throws RuntimeException if PHP executable cannot be found.
     */
    public static function findPhp(bool $includeArgs = true): string
    {
        $phpFinder = new PhpExecutableFinder();
        if (!$phpPath = $phpFinder->find($includeArgs)) {
            throw new RuntimeException('The php executable could not be found in PATH');
        }
        return $phpPath;
    }

    /**
     * Return available signals (IPC)
     *
     * @return array
     */
    protected function getSignals(): array
    {
        $signals = [
            // primary signals handled by the daemon
            'SIGTERM', 'SIGINT', 'SIGUSR1', 'SIGHUP', 'SIGCHLD',

            // other signals that can be caught by setting an event handler
            'SIGUSR2', 'SIGQUIT', 'SIGILL', 'SIGTRAP', 'SIGABRT', 'SIGIOT',
            'SIGBUS', 'SIGFPE', 'SIGSEGV', 'SIGPIPE', 'SIGALRM', 'SIGCONT',
            'SIGTSTP', 'SIGTTIN', 'SIGTTOU', 'SIGURG', 'SIGXCPU', 'SIGXFSZ',
            'SIGVTALRM', 'SIGPROF', 'SIGWINCH', 'SIGIO', 'SIGPOLL', 'SIGSYS',
            'SIGBABY', 'SIGPWR', 'SIGEMT', 'SIGINFO', 'SIGPWR', 'SIGLOST',
            'SIGWINCH', 'SIGSTKFLT', 'SIGUNUSED', 'SIGCLD', 'SIGLWP',
        ];

        $availableSignals = [];
        foreach ($signals as $signal) {
            if (defined($signal)) {
                $availableSignals[$signal] = constant($signal);
            }
        }
        return $availableSignals;
    }

    public function setupSignals($handler = null)
    {
        $signals = $this->getSignals();
        foreach ($signals as $signal) {
            pcntl_signal($signal, $handler ?: function ($signal) {
                $this->signalHandler($signal);
            });
        }
    }

    /**
     * Signal handler.
     *
     * Try not to do too much from within a signal. Ideally, you should set a flag and then on the next iteration of
     * the event loop you act on it from outside the signal. This is because signal handlers are non-reentrant.
     *
     * @param integer $signal Signal number to handle
     *
     * @internal Internal method only used for {@link pcntl_signal} callback. Use the {@link DaemonEvent::ON_SIGNAL}
     *           event to add custom signal handling to your application.
     *
     */
    private function signalHandler(int $signal)
    {
        // fudge the signal count per signal (for stats purposes only)
        $key = DaemonEvent::ON_SIGNAL . '.' . $signal;
        if (!isset($this->dispatched[$key])) $this->dispatched[$key] = 0;
        $this->dispatched[$key]++;

        $doShutdown = function () {
            if (!$this->shutdown) {
                if ($this->parent) {
                    $this->debug(3, "Shutdown Signal Received");
                }
                $this->setShutdown(true);
            } else {
                if ($this->parent) {
                    $this->debug(3, "Shutdown already in progress");
                }
            }
        };

        switch ($signal) {
            case SIGUSR1:
                if ($this->parent && $this->dumpOnSignal) {
                    $this->dump();
                }
                break;
            case SIGHUP:
                if ($this->parent) {
                    if ($this->daemonize) {
                        $this->debug(3, "Restart Signal Received");
                        $this->restart = true;
                        $this->setShutdown(true);
                    } else {
                        $this->debug(3, "Restart Signal Ignored; Not daemonized");
                    }
                }
                break;
            case SIGINT:
                if ($this->parent) {
                    $this->interrupt++;
                    $this->debug(5, "Interrupt Signal Received");
                }
                if ($this->isShutdownOnInterrupt()) {
                    $doShutdown();
                }
                break;
            case SIGTERM:
                if ($this->parent) {
                    if (!$this->daemonize && is_resource(STDERR)) {
                        @fwrite(STDERR, PHP_EOL);
                    }
                }
                $doShutdown();
                break;
        }
        $this->dispatch(DaemonEvent::ON_SIGNAL, new SignalEvent($signal));
    }

    /**
     * Validate the runtime environment to ensure the daemon can run properly.
     *
     * @throws RuntimeException If all conditions are not met
     */
    protected function validateEnvironment()
    {
        $errors = [];

        if (!function_exists('pcntl_fork')) {
            $errors[] = "The PCNTL extension is not installed";
        }

        if (isset($this->plugins[self::PROCESS_MANAGER_PLUGIN_NAME])) {
            if (!$this->plugin(self::PROCESS_MANAGER_PLUGIN_NAME) instanceof ProcessManagerInterface) {
                $errors[] = sprintf('ProcessManager plugin "%s" must implement "%s"', self::PROCESS_MANAGER_PLUGIN_NAME, ProcessManagerInterface::class);
            }
        }

        if ($errors) {
            throw new RuntimeException(implode("\n", $errors));
        }
    }

    /**
     * Set the PID of the current process
     *
     * @param int|false|null $pid
     *
     * @return $this
     */
    public function setPid($pid): self
    {
        if (!ctype_digit((string)$pid) || $pid < 1) {
            throw self::createInvalidArgumentException(func_get_args(), 'Invalid PID argument #1');
        }

        $initial = empty($this->pid);
        $old = $this->pid;
        $this->pid = $pid ?: null;

        // only dispatch event when the pid actually changes (initial set is not dispatched)
        if ($this->parent && $initial && $pid != $this->pid) {
            $this->dispatch(DaemonEvent::ON_PID_CHANGE, new PidEvent($pid, $old));
        }
        return $this;
    }

    /**
     * Return how many times SIGINT has been caught
     */
    public function getInterrupt(): int
    {
        return $this->interrupt;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function getParentPid(): ?int
    {
        return $this->parentPid;
    }

    /**
     * Is the current process the original parent process? Will be false in any forked processes.
     */
    public function isParent(): bool
    {
        return $this->parent;
    }

    /**
     * Set parent flag. Generally only called from child processes.
     *
     * @param bool $flag
     *
     * @return $this
     */
    public function setParent(bool $flag): self
    {
        $this->parent = $flag;
        return $this;
    }

    /**
     * Return true if the daemon is in restart mode
     */
    public function isRestart(): bool
    {
        return $this->restart;
    }

    /**
     * Return true if the daemon is in shutdown mode
     */
    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    /**
     * Allow ANSI colored for statistics?
     *
     * @param bool $ansi
     *
     * @return $this
     */
    public function setAnsi(bool $ansi): self
    {
        $this->ansi = $ansi;
        return $this;
    }

    public function isAnsi(): bool
    {
        return $this->ansi;
    }

    /**
     * Sets the output destination for console output. Setting to null will default back to STDERR.
     *
     * @param callable|resource|string|null $output A callable, an opened stream or an output path
     *
     * @return $this
     */
    public function setOutput($output): self
    {
        switch (true) {
            case $output === null:
                $this->output = fopen('php://stderr', 'w');
                break;
            case is_callable($output):
            case get_resource_type($output) == 'stream':
                $this->output = $output;
                break;
            case is_string($output):
                $this->output = @fopen($output, 'wb');
                break;
            default:
                throw self::createInvalidArgumentException(func_get_args(), "Invalid output argument. Must be a callable|resource|string");
        }

        return $this;
    }

    /**
     * Attempt to wakeup the daemon by sending an alarm signal. Should only be called from forked processes.
     * This will cause the daemon to break out of sleep.
     */
    public function wakeup(): bool
    {
        if ($this->parent || $this->shutdown) {
            return false;
        }

        return posix_kill($this->parentPid, SIGALRM);
    }
}
