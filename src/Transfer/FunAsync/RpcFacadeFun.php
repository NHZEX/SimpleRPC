<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Transfer\FunAsync;

use HZEX\SimpleRpc\Container;
use HZEX\SimpleRpc\Contract\FacadeInterface;
use HZEX\SimpleRpc\RpcTerminal;

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
        $terminal = Container::getInstance()->rpcTerminal;
        return (new static($terminal, $fd));
    }

    public function __construct(RpcTerminal $rpc, ?int $fd)
    {
        $this->terminal = $rpc;
        $this->fd = $fd;
    }

    abstract protected function getFacadeClass(): string;


    public function __call(string $name, $arguments)
    {
        return $this->terminal->methodAsync($this->fd, "{$this->getFacadeClass()}.{$name}", ...$arguments);
    }
}
