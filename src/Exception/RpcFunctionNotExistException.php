<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Exception;

use Throwable;

class RpcFunctionNotExistException extends RpcException
{
    public function __construct($message = "", $code = RPC_FUNCTION_NOT_EXIST_EXCEPTION, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
