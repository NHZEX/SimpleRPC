<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Exception;

use Throwable;

class RpcInvalidResponseException extends RpcException
{
    public function __construct($message = "", $code = RPC_INVALID_RESPONSE_EXCEPTION, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
