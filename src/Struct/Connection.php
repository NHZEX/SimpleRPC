<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Struct;

class Connection
{
    /**
     * 来自哪个Reactor线程
     * @var int
     */
    public $reactor_id;

    /**
     * 来自哪个监听端口socket
     * @var int
     */
    public $server_fd;

    /**
     * 来自哪个监听端口
     * @var int
     */
    public $server_port;

    /**
     * 客户端连接的端口
     * @var int
     */
    public $remote_port;

    /**
     * 客户端连接的IP地址
     * @var string
     */
    public $remote_ip;

    /**
     * 客户端连接到Server的时间
     * @var int
     */
    public $connect_time;

    /**
     * 最后一次收到数据的时间
     * @var int
     */
    public $last_time;

    /**
     * 连接关闭的错误码
     * @var int
     */
    public $close_errno;

    /**
     * 绑定了用户ID
     * @var int
     */
    public $uid;

    /**
     * @param iterable $data
     * @return Connection
     */
    public static function make(iterable $data = [])
    {
        return new self($data);
    }

    /**
     * Connection constructor.
     * @param iterable $data
     */
    public function __construct(iterable $data)
    {
        foreach ($data as $key => $val) {
            $this->$key = $val;
        }
    }
}
