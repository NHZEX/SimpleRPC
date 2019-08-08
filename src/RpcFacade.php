<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use HZEX\SimpleRpc\Contract\FacadeInterface;
use think\Container;

abstract class RpcFacade implements FacadeInterface
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


    public function __call(string $name, $arguments)
    {
        return $this->terminal->method($this->fd, "{$this->getFacadeClass()}.{$name}", ...$arguments);
    }
}
