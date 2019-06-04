<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

use Co;
use HZEX\SimpleRpc\Exception\RpcExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\TransferInterface;

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
    /** @var int 请求Id */
    private $requestId;
    /** @var int|null 连接Id */
    private $fd;
    /** @var string 方法名称 */
    private $methodName;
    /** @var array 执行参数 */
    private $methodArgv;
    /** @var bool 是否失败响应 */
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

    public function __construct(RpcTerminal $rpc, string $name, array $argv)
    {
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
     * 提交执行执行
     * @return mixed
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function exec()
    {
        // 发生执行请求
        $this->rpc->requestCo($this);

        // 让出控制权
        Co::yield();

        // 远程执行失败抛出异常
        if ($this->isFailure) {
            throw (new RpcExecuteException($this->result['code'], $this->result['message']))
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
