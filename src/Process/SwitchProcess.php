<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Process;

use Closure;
use Swoole\Coroutine;
use Swoole\Process;
use Swoole\Server;

class SwitchProcess
{
    /**
     * 全局服务对象
     * @var Server
     */
    protected $swoole;

    /**
     * 当前进程
     * @var Process
     */
    protected $process;

    /**
     * 退出码
     * @var int
     */
    protected $exitCode = 0;

    /**
     * 重启计数
     * @var int
     */
    protected $reStart = 0;

    public function __construct(Server $server)
    {
        $this->swoole = $server;
    }

    /**
     * @return Process
     */
    public function makeProcess(): Process
    {
        $this->reStart++;
        if (null === $this->process) {
            $this->process = new Process(
                Closure::fromCallable([$this, 'process']),
                false,
                SOCK_STREAM,
                false
            );
        }
        return $this->process;
    }

    /**
     * 子进程
     * @param Process $process
     */
    protected function process(Process $process)
    {
        // 初始化子进程
        $process->name('php-ps: SimpleRpc-SwitchProcess');

        // 监控主进程存活
        go(function () {
            while ($this->checkManagerProcess()) {
                Coroutine::sleep(0.1);
            }
        });

        // 执行进程业务
        go(function () use ($process) {
            $ref = $this->processBox($process);
            $this->exitCode = null === $ref ? 0 : $ref;
            $process->exit($this->exitCode);
        });

        return;
    }

    protected function onPipeMessage($data, ?string $form)
    {
    }

    /**
     * @param Process $process
     * @return int
     */
    private function processBox(Process $process): int
    {

        return 0;
    }

    /**
     * 检测主进程
     * @return true
     */
    private function checkManagerProcess()
    {
        $mpid = $this->swoole->master_pid;
        $process = $this->process;

        if (false == Process::kill($mpid, 0)) {
            echo "manager process [{$mpid}] exited, I [{$process->pid}] also quit\n";
            $process->exit();
        }

        return true;
    }
}
