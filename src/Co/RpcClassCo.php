<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

use Co;
use HZEX\SimpleRpc\Exception\RpcExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcTerminal;

class RpcClassCo
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
     * 连接Id
     * @var int|null
     */
    private $fd;
    /**
     * 类名
     * @var string
     */
    private $className;
    /**
     * 远程对象ID
     * @var int
     */
    private $objectId;

    /**
     * TransferClassCo constructor.
     * @param RpcTerminal $rpc
     * @param int|null    $fd
     * @param string      $className
     */
    public function __construct(RpcTerminal $rpc, ?int $fd, string $className)
    {
        $this->cid = Co::getCid();
        $this->rpc = $rpc;
        $this->fd = $fd;
        $this->className = $className;
    }

    /**
     * 实例远程对象
     * @param array $argv
     * @return mixed
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function instance(array $argv): void
    {
        // 调用远程对象
        $method = new TransferMethodCo($this->rpc, "{$this->className}\$__construct", $argv);
        $method->setFd($this->fd);
        $this->objectId = $method->exec();
    }

    /**
     * 调用远程方法
     * @param string $name
     * @param array  $argv
     * @return mixed
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function method(string $name, array $argv)
    {
        // 调用远程对象
        $method = new TransferMethodCo($this->rpc, "{$this->className}\${$name}", $argv);
        $method->setFd($this->fd);
        $method->setObjectId($this->objectId);
        return $method->exec();
    }

    /**
     * 销毁远程对象
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function destroy(): void
    {
        // 调用远程对象
        $method = new TransferMethodCo($this->rpc, "{$this->className}\$__destruct", []);
        $method->setFd($this->fd);
        $method->setObjectId($this->objectId);
        return $method->exec();
    }


}