<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

use HZEX\SimpleRpc\Exception\RpcExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcTerminal;
use think\Container;

/**
 * 远程类门面
 * Class RpcFacadeClass
 * @package HZEX\SimpleRpc
 */
abstract class RpcFacadeClass
{
    /**
     * @var RpcTerminal
     */
    private $terminal;

    /**
     * @var int|null
     */
    private $fd;

    /**
     * @var RpcClassCo
     */
    private $remoteObject;

    /**
     * @return string
     */
    abstract protected function getFacadeClass(): string;

    /**+
     * @param int|null $fd
     * @param mixed    ...$argv
     * @return static
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public static function new(?int $fd = null, ...$argv)
    {
        /** @var RpcTerminal $terminal */
        $terminal = Container::getInstance()->make(RpcTerminal::class);
        return (new static($terminal, $fd, $argv));
    }

    /**
     * RpcFacade2 constructor.
     * @param RpcTerminal $rpc
     * @param int|null    $fd
     * @param array       $argv
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function __construct(RpcTerminal $rpc, ?int $fd, array $argv)
    {
        $this->terminal = $rpc;
        $this->fd = $fd;

        $this->remoteObject = new RpcClassCo($this->terminal, $this->fd, $this->getFacadeClass());
        $this->remoteObject->instance($argv);

    }

    /**
     * 获取类实例Id
     * @return int
     */
    public function getObjectId(): int
    {
        return $this->remoteObject->getObjectId();
    }

    /**
     * @param string $name
     * @param        $arguments
     * @return mixed
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function __call(string $name, $arguments)
    {
        return $this->remoteObject->method($name, $arguments);
    }

    /**
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function __destruct()
    {
        $this->remoteObject->destroy();
    }
}
