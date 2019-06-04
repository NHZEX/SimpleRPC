<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Exception;

class RpcExecuteException extends RpcException
{
    private $remoteTrace;

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
