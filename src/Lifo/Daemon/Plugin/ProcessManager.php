<?php

namespace Lifo\Daemon\Plugin;


use Lifo\Daemon\Daemon;
use Lifo\Daemon\Event\DaemonEvent;
use Lifo\Daemon\Event\ReapedEvent;
use Lifo\Daemon\Event\SignalEvent;
use Lifo\Daemon\ExceptionsTrait;
use Lifo\Daemon\LogTrait;
use Lifo\Daemon\Process;
use Lifo\Daemon\StringUtil;

class ProcessManager extends AbstractPlugin implements ProcessManagerInterface
{
    use LogTrait;
    use ExceptionsTrait;

    /**
     * Forked processes; by group
     *
     * @var Process[][]
     */
    private $groups = [];

    /**
     * Flat list of forked processes
     *
     * @var Process[]
     */
    private $processes = [];

    /**
     * A list of recent processes; for stats purposes only
     *
     * @var Process[]
     */
    private $recent = [];

    /**
     * Total number of recent processes to keep for statistics
     *
     * @var int
     */
    private $maxRecent = 10;

    /**
     * children that died prematurely before the signal handler can reap them
     *
     * @var array
     */
    private $caught = [];

    /**
     * Total processes forked
     */
    private $count = 0;

    /**
     * PID's that have been reaped in the current loop cycle
     *
     * @var int[]
     */
    private $reaped = [];

    public function setup($options = [])
    {
        parent::setup($options);

        $daemon = Daemon::getInstance();
        $daemon->on(DaemonEvent::ON_SIGNAL, function (SignalEvent $e) use ($daemon) {
            if ($daemon->isParent() && $e->getSignal() == SIGCHLD) {
                $this->reaper();
            }
        });

        $daemon->on(DaemonEvent::ON_PRE_EXECUTE, function () use ($daemon) {
            if ($daemon->isParent()) {
                if (!$this->reaped) {
                    return;
                }

                $daemon->dispatch(DaemonEvent::ON_REAPED, new ReapedEvent($daemon, $this->reaped));
                $this->reaped = [];
            }
        });
    }

    protected function onStats(array $stats, $alias)
    {
        $map = function (Process $p) {
            return [
                'pid'     => $p->getPid(),
                'group'   => $p->getGroup(),
                'start'   => $p->getStart(),
                'stop'    => $p->getStop(),
                'runtime' => $p->getRuntime(),
                'timeout' => $p->getTimeout(),
            ];
        };

        $stats = parent::onStats($stats, $alias);
        $stats['plugins'][$alias]['count'] = $this->count;
        $stats['plugins'][$alias]['processes'] = array_values(array_map($map, $this->processes));
        $stats['plugins'][$alias]['recent'] = array_map($map, $this->recent);
        return $stats;
    }

    public function teardown()
    {
        if (!Daemon::getInstance()->isParent()) {
            return;
        }

        // stop all children
        $last = microtime(true);
        while ($this->processes) {
            if (microtime(true) - $last >= 5) {
                $last = microtime(true);
                $this->debug(3, "Waiting for %d process%s to exit", count($this->processes), count($this->processes) == 1 ? '' : 'es');
            }

            foreach ($this->processes as $proc) {
                $proc->stop();
            }

            usleep(50000);
            $this->reaper();
        }
    }

    public function fork($group = null, $callable = null, $timeout = null)
    {
        if ($callable && !is_callable($callable)) {
            throw self::createInvalidArgumentException(func_get_args(), "Invalid fork callable provided");
        }

        $pid = pcntl_fork();
        switch ($pid) {
            case -1: # failed; still in parent
                $this->error("Fork failed");
                return false;
            case 0: # child
                Daemon::getInstance()->dispatch(DaemonEvent::ON_FORK);
                if ($callable) {
                    try {
                        call_user_func_array($callable, array_slice(func_get_args(), 3));
                    } catch (\Exception $e) {
                        $this->error(sprintf('Forking Exception: %s in file: %s on line: %s%sPlain Stack Trace:%s%s',
                            $e->getMessage(), $e->getFile(), $e->getLine(), PHP_EOL, PHP_EOL, $e->getTraceAsString())
                        );
                    }
                    exit(0);
                }
                return true;
            default: # parent
                $proc = $this->addProcess($pid, $group, $timeout);

                // If a SIGCHLD was already caught we need to manually handle it to avoid a defunct process
                if (isset($this->caught[$pid])) {
                    $this->reaper($pid, $this->caught[$pid]);
                    unset($this->caught[$pid]);
                    return false;
                }

                Daemon::getInstance()->dispatch(DaemonEvent::ON_PARENT_FORK);
                return $proc;
        }
    }

    private function addProcess($pid, $group, $timeout)
    {
        $proc = new Process($pid, $group, $timeout);
        $this->processes[$pid] = $proc;
        $this->groups[$group][$pid] = $proc;
        $this->recent = array_slice($this->recent, 0, $this->maxRecent);
        $this->count++;
        return $proc;
    }

    private function hasProcess($pid, $group = null)
    {
        if ($group === null) {
            return isset($this->processes[$pid]);
        }
        return isset($this->groups[$group][$pid]);
    }

    public function getProcess($pid, $group = null)
    {
        if ($group === null) {
            return $this->hasProcess($pid) ? $this->processes[$pid] : null;
        }

        return isset($this->groups[$group][$pid]) ? $this->groups[$group][$pid] : null;
    }

    /**
     * The process no longer needs to be tracked
     *
     * @param int|Process $pid
     */
    private function removeProcess($pid)
    {
        if ($pid instanceof Process) {
            $pid = $pid->getPid();
        }

        if (isset($this->processes[$pid])) {
            $proc = $this->processes[$pid];
            array_unshift($this->recent, $proc);
            if (null !== $group = $proc->getGroup()) {
                unset($this->groups[$group][$pid]);
            }
            unset($this->processes[$pid]);
        }
    }

    private function reaper($pid = null, $status = null)
    {
        if ($pid === null) {
            $pid = pcntl_wait($status, WNOHANG);
        }

        while ($pid > 0) {
            $this->reaped[] = $pid;
            if ($this->hasProcess($pid)) {
                $this->processes[$pid]->setStop(microtime(true));
                $this->debug(3, "Reaping child [PID=%d]", $pid);
                $this->removeProcess($pid);
            } else {
                // The child died before the parent could track the process. It will be reaped in self::fork()
                $this->caught[$pid] = $status;
            }
            $pid = pcntl_wait($status, WNOHANG);
        }
    }

    /**
     * @return int
     */
    public function getMaxRecent()
    {
        return $this->maxRecent;
    }

    /**
     * @param int $maxRecent
     */
    public function setMaxRecent($maxRecent)
    {
        $this->maxRecent = $maxRecent;
    }

    /**
     * Return the total active processes. Optionally filtered on the group name
     *
     * @param string $group
     * @return int
     */
    public function count($group = null)
    {
        if ($group === null) {
            return count($this->processes);
        }

        return isset($this->groups[$group]) ? count($this->groups[$group]) : 0;
    }

    protected function setLogArguments($args, $type = 'log')
    {
        $msg = &$args[is_int($args[0]) ? 1 : 0];
        $msg = sprintf("%s: %s%s", StringUtil::baseClassName($this), $type == 'error' ? 'Error: ' : '', $msg);
        return $args;
    }
}