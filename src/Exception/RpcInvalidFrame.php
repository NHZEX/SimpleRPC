<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Exception;

use Throwable;

class RpcInvalidFrame extends RpcException
{
    private $original;

    public function __construct(Throwable $previous = null, $message = 'invalid frame')
    {
        parent::__construct($message, RPC_INVALID_FRAME_EXCEPTION, $previous);
    }

    /**
     * @param string $original
     */
    public function setOriginal(string $original): void
    {
        $this->original = $original;
    }

    /**
     * @return string
     */
    public function getOriginal(): string
    {
        return $this->original;
    }
}
