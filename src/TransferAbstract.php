<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Co;
use HZEX\SimpleRpc\Contract\TransferInterface;
use HZEX\SimpleRpc\Exception\RpcException;
use ReflectionException;
use ReflectionMethod;

abstract class TransferAbstract implements TransferInterface
{
    /**
     * 绑定的Rpc终端
     * @var RpcTerminal
     */
    protected $rpc;
    /**
     * 获取关联协程Id
     * @var int
     */
    protected $cid = -1;
    /**
     * 请求Id
     * @var int
     */
    protected $requestId = 0;
    /**
     * 连接Id
     * @var int|null
     */
    protected $fd;
    /**
     * 方法名称
     * @var string
     */
    protected $methodName;
    /**
     * 执行参数
     * @var array
     */
    protected $methodArgv;
    /**
     * 响应内容
     * @var mixed
     */
    protected $result;
    /**
     * 原始响应内容
     * @var string
     */
    protected $resultRaw;
    /**
     * 是否失败响应
     * @var bool
     */
    protected $isFailure;
    /**
     * 请求超时
     * @var int
     */
    protected $timeout = 60;
    /**
     * 方法启动时间
     * @var int
     */
    protected $execTime = 0;
    /**
     * 方法停止时间
     * @var int
     */
    protected $stopTime = 0;

    /**
     * 获取关联协程Id
     * @return int
     */
    public function getCid(): int
    {
        return $this->cid;
    }

    /**
     * 获取关联RPC实例
     * @return RpcTerminal
     */
    public function getRpcTerminal(): RpcTerminal
    {
        return $this->rpc;
    }

    /**
     * 获取请求ID
     * @return int
     */
    public function getRequestId(): int
    {
        return $this->requestId;
    }

    /**
     * 设置请求ID
     * @param int $requestId
     * @return void
     */
    public function setRequestId(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * @return int|null
     */
    public function getFd(): ?int
    {
        return $this->fd;
    }

    /**
     * @param int|null $fd
     */
    public function setFd(?int $fd): void
    {
        $this->fd = $fd;
    }

    /**
     * 获取调用方法名称
     * @return string
     */
    public function getMethodName(): string
    {
        return $this->methodName;
    }

    /**
     * 获取方法调用参数
     * @return array
     */
    public function getMethodArgv(): array
    {
        return $this->methodArgv;
    }

    /**
     * 获取方法调用参数序列化值
     * @return string
     */
    public function getArgvSerialize(): string
    {
        return serialize($this->methodArgv);
    }

    /**
     * @param int $timeout
     * @return TransferAbstract
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * @return int
     */
    public function getStopTime(): int
    {
        return $this->stopTime;
    }

    /**
     * 分析协程调度目标
     * @param string $method
     * @return mixed
     * @throws RpcException
     */
    protected function analyzeDispatchInfo(string $method = 'exec')
    {
        static $dispatchInfos = [];
        $key = static::class . '::' . $method;

        if (!isset($dispatchInfos[$key])) {
            try {
                $ref = new ReflectionMethod($this, $method);
            } catch (ReflectionException $e) {
                throw new RpcException('Rpc调度初始化失败', RPC_TRANSFER_INIT_EXCEPTION, $e);
            }
            $dispatchInfos[$key]['target_file'] = $ref->getFileName();
            $dispatchInfos[$key]['start_line'] = $ref->getStartLine();
            $dispatchInfos[$key]['end_line'] = $ref->getEndLine();
        }
        return $dispatchInfos[$key];
    }

    /**
     * 检测是否与目标调度一致
     * @return bool
     * @throws RpcException
     */
    public function isTargetResume()
    {
        $dispatchInfo = $this->analyzeDispatchInfo();

        $trace = Co::getBackTrace($this->cid, DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        if (!$trace) {
            return false;
        }
        $trace = array_shift($trace);

        return $trace['file'] === $dispatchInfo['target_file']
            && $trace['line'] >= $dispatchInfo['start_line']
            && $trace['line'] <= $dispatchInfo['end_line'];
    }

    public function __toString()
    {
        return "rpc transfer: {$this->methodName}#{$this->requestId}#{$this->cid}";
    }
}
