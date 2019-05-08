<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

abstract class RpcFacade
{
    private $rpc;

    public static function make(int $id = 0)
    {
        return (new static(Rpc::getInstance($id)));
    }

    public function __construct(Rpc $rpc)
    {
        $this->rpc = $rpc;
    }

    abstract protected function getFacadeClass(): string;

    public function __call($name, $arguments)
    {
        return $this->rpc->method("{$this->getFacadeClass()}.{$name}", ...$arguments);
    }
}
