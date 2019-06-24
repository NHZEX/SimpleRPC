<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Exception;

use Throwable;

class RpcFunctionInvokeException extends RpcException
{
    public function __construct($message = "", $code = RPC_FUNCTION_INVOKE_EXCEPTION, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
