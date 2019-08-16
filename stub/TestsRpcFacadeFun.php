<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Stub;

use HZEX\SimpleRpc\Transfer\FunAsync\RpcFacadeFun;
use HZEX\SimpleRpc\Transfer\FunAsync\TransferFunAsync;

/**
 * Class TestsRpcFacade
 * @package HZEX\SimpleRpc\Stub
 * @method TransferFunAsync runAutoUpdate(string $a, int $b, bool $c)
 */
class TestsRpcFacadeFun extends RpcFacadeFun
{
    protected function getFacadeClass(): string
    {
        return 'Tests1';
    }
}
