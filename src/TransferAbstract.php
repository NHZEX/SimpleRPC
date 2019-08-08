<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use HZEX\SimpleRpc\Contract\TransferInterface;

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
     * @return int
     */
    public function getStopTime(): int
    {
        return $this->stopTime;
    }
}
