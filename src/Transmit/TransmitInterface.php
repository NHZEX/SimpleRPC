<?php
declare(strict_types=1);

namespace app\Server\Rpc\Transmit;

interface TransmitInterface
{
    /**
     * 发送数据包
     * @param string $data
     * @return bool
     */
    public function __invoke(string $data): bool;
}
