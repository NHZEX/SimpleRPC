<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Stub;

use HZEX\SimpleRpc\Transfer\Fun\RpcFacadeFun;
use HZEX\SimpleRpc\Transfer\Fun\TransferFun;

/**
 * Class TestsRpcFacade
 * @package HZEX\SimpleRpc\Stub
 * @method TransferFun runAutoUpdate(string $a, int $b, bool $c)
 */
class TestsRpcFacadeFun extends RpcFacadeFun
{
    protected function getFacadeClass(): string
    {
        return 'Tests1';
    }
}
