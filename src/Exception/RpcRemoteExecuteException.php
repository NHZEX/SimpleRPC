<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Exception;

use Throwable;

class RpcRemoteExecuteException extends RpcException
{
    private $remoteTrace;

    public function __construct($message = "", $code = RPC_REMOTE_EXECUTE_EXCEPTION, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return mixed
     */
    public function getRemoteTrace()
    {
        return $this->remoteTrace;
    }

    /**
     * @param mixed $remoteTrace
     * @return self
     */
    public function setRemoteTrace($remoteTrace): self
    {
        $this->remoteTrace = $remoteTrace;
        return $this;
    }
}
