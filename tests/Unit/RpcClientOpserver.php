<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests\Unit;

use HZEX\SimpleRpc\Observer\ClientHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;

class RpcClientOpserver implements ClientHandleInterface
{
    protected $connect = false;

    /**
     */
    public function onConnect(): void
    {
        $this->connect = true;
    }

    /**
     */
    public function onClose(): void
    {
        $this->connect = false;
    }

    /**
     * @return bool
     */
    public function isConnect(): bool
    {
        return $this->connect;
    }

    /**
     * @param string $data
     * @return bool|null
     */
    public function onReceive(string $data): ?bool
    {
        return null;
    }

    /**
     * @param TransferFrame $frame
     * @return bool|null
     */
    public function onSend(TransferFrame $frame): ?bool
    {
        return null;
    }
}
