<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Protocol;

use ReflectionClass;
use ReflectionException;

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
    /** @var int */
    protected $workerId = 0;

    private static $cache;

    /**
     * TransferFrame constructor.
     * @param int|null $fd
     * @param int      $workerId
     */
    public function __construct(?int $fd = null, $workerId = 0)
    {
        $this->fd = $fd;
        $this->workerId = $workerId;
        $this->flags = self::FLAG_COMPRESSION;
        $this->analyze();
    }

    private function analyze()
    {
        if (null !== self::$cache) {
            return;
        }
        try {
            $ref = new ReflectionClass($this);
        } catch (ReflectionException $e) {
            return;
        }
        self::$cache = [];
        foreach ($ref->getConstants() as $name => $value) {
            $pos = strpos($name, '_');
            if (false === $pos) {
                $prefix = 'ROOT';
            } else {
                $prefix = substr($name, 0, $pos);
                $name = substr($name, $pos + 1);
            }
            self::$cache[$prefix][$value] = $name;
        }
    }

    /**
     * @param string|null $package
     * @param int|null    $fd
     * @return self|null
     */
    public static function make(string $package, ?int $fd = null): ?self
    {
        $that = new self($fd);
        if (false === $that->unpack($package) instanceof self) {
            return null;
        }
        return $that;
    }

    /**
     * @param int|null $fd
     * @param int      $workerId
     * @return self
     */
    public static function ping(?int $fd = null, $workerId = 0): self
    {
        $ping = new self($fd, $workerId);
        $ping->opcode = self::OPCODE_PING;
        $ping->flags = 0;
        return $ping;
    }

    /**
     * @param int|null $fd
     * @param int      $workerId
     * @return self
     */
    public static function pong(?int $fd = null, $workerId = 0): self
    {
        $ping = new self($fd, $workerId);
        $ping->opcode = self::OPCODE_PONG;
        $ping->flags = 0;
        return $ping;
    }

    /**
     * @return string
     */
    public function getOpcodeDesc()
    {
        return self::$cache['OPCODE'][$this->opcode] ?? 'UNDEFINED';
    }

    /**
     * 封包
     * @return string
     */
    public function packet(): string
    {
        if ($this->isCompression()) {
            $data = gzdeflate($this->body);
        } else {
            $data = $this->body;
        }
        $hash = hash('adler32', $data);
        $length = strlen($data);

        // NCCCH8 = 15
        $package = pack(
            'NCCCNH8',
            $length + 15,
            self::VERSION,
            $this->flags,
            $this->opcode,
            $this->workerId,
            $hash
        );

        $package .= $data;

        return $package;
    }

    /**
     * 解包
     * @param string $data
     * @return TransferFrame
     */
    public function unpack(string $data): self
    {
        [
            'l' => $length, 'v' => $version, 'flags' => $flags, 'op' => $opcode, 'worker' => $worker, 'h' => $hash,
        ] = unpack('Nl/Cv/Cflags/Cop/Nworker/H8h', $data); // 4+1+1+1+4+4

        if (self::VERSION !== $version) {
            // 版本不匹配
            return null;
        }

        // 获取Body
        $data = substr($data, 15, $length) ?: '';

        // 校验数据
        if ($hash !== hash('adler32', $data)) {
            // 数据校验错误
            return null;
        }

        // 设置操作码
        $this->opcode = $opcode;
        $this->flags = $flags;
        $this->workerId = $worker;

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
     * @return int
     */
    public function getWorkerId(): int
    {
        return $this->workerId;
    }

    /**
     * @param int $workerId
     * @return $this
     */
    public function setWorkerId(int $workerId)
    {
        $this->workerId = $workerId;
        return $this;
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

    public function __toString(): string
    {
        $fd = $this->fd ?: 'null';
        $info = "tFrame: opcode={$this->getOpcodeDesc()}[{$this->opcode}], flags={$this->flags}";
        $info .= ", worker={$this->workerId}, fd={$fd}, body_len = " . strlen($this->body);
        return $info;
    }

    public function __debugInfo()
    {
        return [$this->__toString()];
    }
}
