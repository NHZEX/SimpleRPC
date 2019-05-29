<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Observer;

use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Struct\Connection;

interface RpcHandleInterface
{
    /**
     * @param int        $fd
     * @param Connection $connection
     * @return bool
     */
    public function auth(int $fd, Connection $connection): bool;

    /**
     * @param int        $fd
     * @param Connection $connection
     */
    public function onConnect(int $fd, Connection $connection): void;

    /**
     * @param int        $fd
     * @param Connection $connection
     */
    public function onClose(int $fd, Connection $connection): void;

    /**
     * @param int        $fd
     * @param string     $data
     * @param Connection $connection
     * @return bool|null
     */
    public function onReceive(int $fd, string $data, Connection $connection): ?bool;

    /**
     * @param int           $fd
     * @param TransferFrame $frame
     * @return bool|null
     */
    public function onSend(int $fd, TransferFrame $frame): ?bool;
}
