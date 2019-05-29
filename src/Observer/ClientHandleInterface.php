<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Observer;

use HZEX\SimpleRpc\Protocol\TransferFrame;

interface ClientHandleInterface
{
    /**
     */
    public function onConnect(): void;

    /**
     */
    public function onClose(): void;

    /**
     * @param string $data
     * @return bool|null
     */
    public function onReceive(string $data): ?bool;

    /**
     * @param TransferFrame $frame
     * @return bool|null
     */
    public function onSend(TransferFrame $frame): ?bool;
}
