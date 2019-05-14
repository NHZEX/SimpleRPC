<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Stub;

use HZEX\SimpleRpc\Transmit\TransmitInterface;

class VirtualSend implements TransmitInterface
{
    private static $tempData = '';

    /**
     * 清除暂存
     */
    public static function clear()
    {
        self::$tempData = '';
    }


    /**
     * 获取暂存数据
     * @return string
     */
    public static function look()
    {
        return self::$tempData;
    }

    /**
     * 获取暂存数据
     * @return string
     */
    public static function getData()
    {
        $data = self::$tempData;
        self::$tempData = '';
        return $data;
    }

    /**
     * 发送数据包
     * @param string $data
     * @return bool
     */
    public function __invoke(string $data): bool
    {
        self::$tempData = $data;
        return true;
    }
}
