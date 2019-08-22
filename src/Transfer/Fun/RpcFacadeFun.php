<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Transfer\Fun;

use HZEX\SimpleRpc\Contract\FacadeInterface;
use HZEX\SimpleRpc\Exception\RpcRemoteExecuteException;
use HZEX\SimpleRpc\RpcTerminal;
use think\Container;

/**
 * Class RpcFacadeFun
 * @package HZEX\SimpleRpc\Transfer\Fun
 */
abstract class RpcFacadeFun implements FacadeInterface
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
     * @throws RpcRemoteExecuteException
     */
    public function __call(string $name, $arguments)
    {
        return $this->terminal
            ->methodCo($this->fd, "{$this->getFacadeClass()}.{$name}", ...$arguments)
            ->exec();
    }
}
