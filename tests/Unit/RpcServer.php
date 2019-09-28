<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests\Unit;

use Closure;
use HZEX\SimpleRpc\Observer\RpcHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcProvider;
use HZEX\SimpleRpc\Struct\Connection;

class RpcServer implements RpcHandleInterface
{
    public function start()
    {
        $rpcServer = new \HZEX\SimpleRpc\RpcServer($this);
        $provider = new RpcProvider();
        $provider->bind('TestProvider', TestProvider::class);
        $provider->bind('TestFun', Closure::fromCallable([$this, 'testFun']));
        $provider->bind('sleep', 'sleep');
        $rpcServer->start($provider, '127.0.0.1', 9981);
    }

    /**
     * @param int $a
     * @param int $b
     * @return int
     */
    public function testFun(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * @param \HZEX\SimpleRpc\RpcServer $server
     * @param int                       $workerId
     */
    public function onWorkerStart(\HZEX\SimpleRpc\RpcServer $server, int $workerId): void
    {
    }

    /**
     * @param \HZEX\SimpleRpc\RpcServer $server
     */
    public function onWorkerStop(\HZEX\SimpleRpc\RpcServer $server): void
    {
    }

    /**
     * @param int        $fd
     * @param Connection $connection
     * @return bool
     */
    public function auth(int $fd, Connection $connection)
    {
        return true;
    }

    /**
     * @param int        $fd
     * @param Connection $connection
     */
    public function onConnect(int $fd, Connection $connection): void
    {
        // TODO: Implement onConnect() method.
    }

    /**
     * @param int        $fd
     * @param Connection $connection
     */
    public function onClose(int $fd, Connection $connection): void
    {
        // TODO: Implement onClose() method.
    }

    /**
     * @param int        $fd
     * @param string     $data
     * @param Connection $connection
     * @return bool|null
     */
    public function onReceive(int $fd, string $data, Connection $connection): ?bool
    {
        return null;
    }

    /**
     * @param int           $fd
     * @param TransferFrame $frame
     * @return bool|null
     */
    public function onSend(int $fd, TransferFrame $frame): ?bool
    {
        return null;
    }
}
