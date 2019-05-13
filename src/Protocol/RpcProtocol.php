<?php

namespace HZEX\SimpleRpc\Protocol;

class RpcProtocol
{
    /** @var string 包头 */
    public const HEAD = 'nrpc';
    /** @var int 包版本 */
    public const VERSION = 0x01;

    /** @var int 标志 压缩 */
    public const FLAG_COMPRESSION = 0x01;

    /** @var string 操作码 执行方法 */
    public const OPCODE_EXECUTE = 0x01;
    /** @var string 操作码 执行结果 */
    public const OPCODE_RESULT = 0x02;
    /** @var string 操作码 执行失败 */
    public const OPCODE_FAILURE = 0x03;

    private const HEAD_LEN = 4;

    private $buffer = '';

    public function write(string $data)
    {
        $this->buffer .= $data;
    }

    public function eof()
    {
        // 接收到的数据还不够4字节，无法得知包的长度，继续等待数据
        if (strlen($this->buffer) < self::HEAD_LEN) {
            return true;
        }
        // 将首部4字节转换成数字，得整个数据包长度
        ['len' => $length] = unpack('Nlen', $this->buffer);
        // 未满足包长，继续等待数据
        if (strlen($this->buffer) < $length) {
            return true;
        }

        return false;
    }

    public function read()
    {
        ['len' => $length] = unpack('Nlen', $this->buffer);

        if ($length > strlen($this->buffer)) {
            return false;
        }

        // 从缓冲区获取数据
        $payload = substr($this->buffer, self::HEAD_LEN, $length - self::HEAD_LEN);
        $this->buffer = substr($this->buffer, $length);

        return $payload;
    }
}
