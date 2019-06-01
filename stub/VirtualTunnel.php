<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Stub;

use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\Tunnel\TunnelInterface;

class VirtualTunnel implements TunnelInterface
{
    /**
     * @var TransferFrame
     */
    private static $tempData;

    /**
     * 清除暂存
     */
    public static function clear()
    {
        self::$tempData = null;
    }

    /**
     * 获取暂存数据
     * @return TransferFrame
     */
    public static function look()
    {
        return self::$tempData;
    }

    /**
     * 获取暂存数据
     * @return TransferFrame
     */
    public static function getData()
    {
        $data = self::$tempData;
        self::$tempData = null;
        return $data;
    }

    public function setRpcTerminal(RpcTerminal $terminal): TunnelInterface
    {
        return $this;
    }

    public function getWorkerId(): int
    {
        return 254;
    }

    /**
     * 发送数据
     * @param TransferFrame $frame
     * @return bool
     */
    public function send(TransferFrame $frame): bool
    {
        self::$tempData = $frame;
        return true;
    }
}
