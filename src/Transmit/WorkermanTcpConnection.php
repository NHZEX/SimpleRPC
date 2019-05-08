<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Transmit;

use Workerman\Connection\TcpConnection;

class WorkermanTcpConnection implements TransmitInterface
{
    private $con;

    public function __construct(TcpConnection $tcpConnection)
    {
        $this->con = $tcpConnection;
    }

    /**
     * 发送数据包
     * @param string $data
     * @return bool
     */
    public function __invoke(string $data): bool
    {
        /**
         * true 表示数据已经成功写入到该连接的操作系统层的socket发送缓冲区
         * null 表示数据已经写入到该连接的应用层发送缓冲区，等待向系统层socket发送缓冲区写入
         * false 表示发送失败，失败原因可能是客户端连接已经关闭，或者该连接的应用层发送缓冲区已满
         * 忽略 null 返回
         */
        return false !== $this->con->send($data);
    }
}
