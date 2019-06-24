<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Exception;

use Throwable;

class RpcSendDataException extends RpcException
{
    public function __construct($message = "", $code = RPC_SEND_DATA_EXCEPTION, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
