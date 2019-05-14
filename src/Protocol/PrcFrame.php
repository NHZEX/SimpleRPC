<?php

namespace HZEX\SimpleRpc\Protocol;

class PrcFrame
{
    /** @var string 包头 */
    public const HEAD = 'nrpc';
    /** @var int 包版本 */
    public const VERSION = 0x01;

    /** @var int 标志 压缩 */
    public const FLAG_COMPRESSION = 0x01;

    /** @var int 操作码 执行方法 */
    public const OPCODE_EXECUTE = 0x01;
    /** @var int 操作码 执行结果 */
    public const OPCODE_RESULT = 0x02;
    /** @var int 操作码 执行失败 */
    public const OPCODE_FAILURE = 0x03;

    /** @var string */
    protected $data;
    /** @var int */
    protected $opcode = 0;
    /** @var int */
    protected $flags = 0;

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @param string $data
     * @return $this
     */
    public function setData(string $data)
    {
        $this->data = $data;
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
     * @param string|null $package
     * @return PrcFrame|null
     */
    public static function make(?string $package = null)
    {
        $that = new self();
        if (null !== $package) {
            $that = $that->unpack($package);
        }
        return $that;
    }

    /**
     * @return string
     */
    public function pack()
    {
        if ($this->flags & self::FLAG_COMPRESSION) {
            $data = gzdeflate($this->data);
        } else {
            $data = $this->data;
        }
        $hash = hash('adler32', $data);
        $length = strlen($data);

        // a4NCCNH8 = 18
        $package = pack(
            'a4NCCNH8',
            self::HEAD,
            self::VERSION,
            $this->opcode,
            $this->flags,
            $length,
            $hash
        );

        $package .= $data;

        return $package;
    }

    /**
     * @param string $package
     * @return PrcFrame|null
     */
    public function unpack(string $package): ?PrcFrame
    {
        if (18 > strlen($package) || $package[0] !== self::HEAD[0]) {
            return null;
        }

        [
            'head' => $head, 'version' => $version, 'opcode' => $opcode, 'flags' => $flags, 'length' => $length, 'hash' => $hash,
        ] = unpack('a4head/Nversion/Copcode/Cflags/Nlength/H8hash', $package);

        if (self::HEAD !== $head || self::VERSION !== $version) {
            return null;
        }

        $data = substr($package, 18, $length);
        if ($hash !== hash('adler32', $data)) {
            return null;
        }

        if ($flags & self::FLAG_COMPRESSION) {
            $data = gzinflate($data);
        }

        $this->opcode = $opcode;
        $this->flags = $flags;
        $this->data = $data;
        return $this;
    }
}
