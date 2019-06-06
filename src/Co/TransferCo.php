<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

use Co;
use HZEX\SimpleRpc\Exception\RpcExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\TransferInterface;
use LengthException;

/**
 * 协程静态方法调用过程
 * Class TransferCo
 * @package HZEX\SimpleRpc\Co
 */
class TransferCo implements TransferInterface
{
    /**
     * @var int
     */
    private $cid;
    /**
     * 绑定的Rpc终端
     * @var RpcTerminal
     */
    private $rpc;
    /**
     * 请求Id
     * @var int
     */
    private $requestId;
    /**
     * 连接Id
     * @var int|null
     */
    private $fd;
    /**
     * 方法名称
     * @var string
     */
    private $methodName;
    /**
     * 执行参数
     * @var array
     */
    private $methodArgv;
    /**
     * 是否失败响应
     * @var bool
     */
    private $isFailure;
    /**
     * 原始响应内容
     * @var string
     */
    private $resultRaw;
    /**
     * 响应内容
     * @var mixed
     */
    private $result;
    /**
     * 超时限制
     * @var int
     */
    private $timeout = 60;
    /**
     * 方法启动时间
     * @var int
     */
    private $startTime = 0;
    /**
     * 方法启动时间
     * @var int
     */
    private $stopTime = 0;

    public function __construct(RpcTerminal $rpc, string $name, array $argv)
    {
        if (($namelen = strlen($name)) > 255) {
            throw new LengthException('方法名称长度超出支持范围: ' . $namelen);
        }
        $this->cid = Co::getCid();
        $this->rpc = $rpc;
        $this->methodName = $name;
        $this->methodArgv = $argv;
    }

    /**
     * @return int
     */
    public function getCid(): int
    {
        return $this->cid;
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
     * @return self
     */
    public function setFd(?int $fd): TransferInterface
    {
        $this->fd = $fd;
        return $this;
    }

    /**
     * @return int
     */
    public function getRequestId(): int
    {
        return $this->requestId;
    }

    /**
     * @param int $requestId
     * @return self
     */
    public function setRequestId(int $requestId): TransferInterface
    {
        $this->requestId = $requestId;
        return $this;
    }

    /**
     * 获取RPC实例
     * @return RpcTerminal
     */
    public function getRpcTerminal(): RpcTerminal
    {
        return $this->rpc;
    }

    /**
     * @return string
     */
    public function getMethodName(): string
    {
        return $this->methodName;
    }

    /**
     * @return array
     */
    public function getMethodArgv(): array
    {
        return $this->methodArgv;
    }

    /**
     * @return string
     */
    public function getArgvSerialize(): string
    {
        return serialize($this->methodArgv);
    }

    /**
     * @return int
     */
    public function getStopTime(): int
    {
        return $this->stopTime;
    }

    /**
     * 提交执行执行
     * @return mixed
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function exec()
    {
        // 记录启动时间
        $this->startTime = time();
        // 计算停止时间
        $this->stopTime = $this->startTime + $this->timeout;
        // 发生执行请求
        $this->rpc->request($this);

        // 让出控制权
        Co::yield();

        // 远程执行失败抛出异常
        if ($this->isFailure) {
            throw (new RpcExecuteException($this->result['message'], $this->result['code']))
                ->setRemoteTrace($this->result['trace']);
        }

        return $this->result;
    }

    /**
     * 设置响应参数
     * @param string $result
     * @param bool   $failure
     */
    public function response(string $result, bool $failure)
    {
        // 设置响应参数
        $this->resultRaw = $result;
        $this->result = unserialize($this->resultRaw);
        $this->isFailure = $failure;
        // 恢复协程
        Co::resume($this->cid);
    }
}
