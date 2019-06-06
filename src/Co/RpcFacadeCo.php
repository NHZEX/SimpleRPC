<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

use HZEX\SimpleRpc\Exception\RpcExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcTerminal;
use think\Container;

/**
 * 远程静态方法门面
 * Class RpcFacadeCo
 * @package HZEX\SimpleRpc\Co
 */
abstract class RpcFacadeCo
{
    /**
     * @var RpcTerminal
     */
    private $terminal;

    /**
     * @var int|null
     */
    private $fd;

    public static function make(?int $fd = null)
    {
        /** @var RpcTerminal $terminal */
        $terminal = Container::getInstance()->make(RpcTerminal::class);
        return (new static($terminal, $fd));
    }

    public function __construct(RpcTerminal $rpc, ?int $fd)
    {
        $this->terminal = $rpc;
        $this->fd = $fd;
    }

    abstract protected function getFacadeClass(): string;

    /**
     * @param string $name
     * @param        $arguments
     * @return mixed
     * @throws RpcExecuteException
     * @throws RpcSendDataException
     */
    public function __call(string $name, $arguments)
    {
        return $this
            ->terminal
            ->methodCo($this->fd, "{$this->getFacadeClass()}.{$name}", ...$arguments)
            ->exec();
    }
}
