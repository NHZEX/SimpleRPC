<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Protocol;

class TransferFrame
{
    /** @var int 包版本 0-255 */
    public const VERSION = 0x01;

    /** @var int 操作码 执行方法 */
    public const OPCODE_EXECUTE = 0x01;
    /** @var int 操作码 执行结果 */
    public const OPCODE_RESULT = 0x02;
    /** @var int 操作码 执行失败 */
    public const OPCODE_FAILURE = 0x03;
    /** @var int 操作码 PING */
    public const OPCODE_PING = 0x04;
    /** @var int 操作码 PONG */
    public const OPCODE_PONG = 0x05;

    /** @var int 标志 压缩 */
    public const FLAG_COMPRESSION = 0x01;
    /** @var int 标志 预留 */
    public const FLAG_RSV1 = 0x02;
    /** @var int 标志 预留 */
    public const FLAG_RSV2 = 0x04;
    /** @var int 标志 预留 */
    public const FLAG_RSV3 = 0x08;
    /** @var int 标志 预留 */
    public const FLAG_RSV4 = 0x10;
    /** @var int 标志 预留 */
    public const FLAG_RSV5 = 0x20;
    /** @var int 标志 预留 */
    public const FLAG_RSV6 = 0x40;
    /** @var int 标志 预留 */
    public const FLAG_RSV7 = 0x80;

    /** @var string */
    protected $body = '';
    /** @var int */
    protected $opcode = 0;
    /** @var int */
    protected $flags = 0;
    /** @var null|int */
    protected $fd = null;

    /**
     * @param string|null $package
     * @param int|null    $fd
     * @return self|null
     */
    public static function make(string $package, ?int $fd = null): ?self
    {
        $that = new self();
        if (false === $that->unpack($package) instanceof self) {
            return null;
        }
        $that->fd = $fd;
        return $that;
    }

    /**
     * @return string
     */
    public static function ping(): string
    {
        static $cache;

        if (empty($cache)) {
            $ping = new self();
            $ping->opcode = self::OPCODE_PING;
            $ping->flags = 0;
            $cache = $ping->packet();
        }
        return $cache;
    }

    /**
     * @return string
     */
    public static function pong(): string
    {
        static $cache;

        if (empty($cache)) {
            $ping = new self();
            $ping->opcode = self::OPCODE_PONG;
            $ping->flags = 0;
            $cache = $ping->packet();
        }
        return $cache;
    }

    /**
     * @return string
     */
    public function packet(): string
    {
        if ($this->flags & self::FLAG_COMPRESSION) {
            $data = gzdeflate($this->body);
        } else {
            $data = $this->body;
        }
        $hash = hash('adler32', $data);
        $length = strlen($data);

        // NCCCH8 = 11
        $package = pack(
            'NCCCH8',
            $length + 11,
            self::VERSION,
            $this->flags,
            $this->opcode,
            $hash
        );

        $package .= $data;

        return $package;
    }

    /**
     * @param string $data
     * @return TransferFrame
     */
    public function unpack(string $data): self
    {
        [
            'length' => $length, 'version' => $version, 'flags' => $flags, 'opcode' => $opcode, 'hash' => $hash,
        ] = unpack('Nlength/Cversion/Cflags/Copcode/H8hash', $data); // 4+1+1+1+4

        if (self::VERSION !== $version) {
            return null;
        }

        // 获取Body
        $data = substr($data, 11, $length) ?: '';

        // 校验数据
        if ($hash !== hash('adler32', $data)) {
            return null;
        }

        // 设置操作码
        $this->opcode = $opcode;
        $this->flags = $flags;

        // 解压Body
        if ($this->isCompression()) {
            $data = gzinflate($data);
        }

        // 设置Body数据
        $this->body = $data;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getFd(): ?int
    {
        return $this->fd;
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @param string $body
     * @return $this
     */
    public function setBody(string $body)
    {
        $this->body = $body;
        return $this;
    }

    /**
     * @return int
     */
    public function getOpcode(): int
    {
        return $this->opcode;
    }

    /**
     * @param int $opcode
     * @return $this
     */
    public function setOpcode(int $opcode)
    {
        $this->opcode = $opcode;
        return $this;
    }

    /**
     * @return int
     */
    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * @param int $flags
     * @return $this
     */
    public function setFlags(int $flags)
    {
        $this->flags = $flags;
        return $this;
    }

    /**
     * @return bool
     */
    public function isCompression(): bool
    {
        return self::FLAG_COMPRESSION === ($this->flags & self::FLAG_COMPRESSION);
    }
}
