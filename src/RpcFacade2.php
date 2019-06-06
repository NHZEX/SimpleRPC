<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use HZEX\SimpleRpc\Co\RpcClassCo;
use think\Container;

/**
 * Class RpcFacade2
 * @package HZEX\SimpleRpc
 */
abstract class RpcFacade2
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
     * @throws Exception\RpcExecuteException
     * @throws Exception\RpcSendDataException
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
     * @throws Exception\RpcExecuteException
     * @throws Exception\RpcSendDataException
     */
    public function __construct(RpcTerminal $rpc, ?int $fd, array $argv)
    {
        $this->terminal = $rpc;
        $this->fd = $fd;

        $this->remoteObject = new RpcClassCo($this->terminal, $this->fd, $this->getFacadeClass());
        $this->remoteObject->instance($argv);

    }


    /**
     * @param string $name
     * @param        $arguments
     * @return Transfer
     * @throws Exception\RpcExecuteException
     * @throws Exception\RpcSendDataException
     */
    public function __call(string $name, $arguments)
    {
        $this->remoteObject->method('', []);
        return $this->terminal->method($this->fd, "{$this->getFacadeClass()}.{$name}", ...$arguments);
    }

    /**
     * @throws Exception\RpcExecuteException
     * @throws Exception\RpcSendDataException
     */
    public function __destruct()
    {
        $this->remoteObject->destroy();
    }
}
