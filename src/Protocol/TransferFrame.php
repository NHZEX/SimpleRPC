<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Protocol;

use Closure;
use Exception;
use HZEX\SimpleRpc\Exception\RpcInvalidFrame;
use ReflectionClass;
use ReflectionException;

class TransferFrame
{
    /** @var int 包版本 0-255 */
    public const VERSION = 0x01;
    /** @var int 工人ID 无效值 */
    public const WORKER_ID_NULL = 0xFFFFFFFF;

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
    /** @var int 操作码 连接会话 */
    public const OPCODE_LINK = 0x06;
    /** @var int 操作码 调用类 */
    public const OPCODE_CLASS = 0x07;

    /** @var int 标志 压缩 */
    public const FLAG_COMPRESSION = 0x01;
    /** @var int 标志 加密 */
    public const FLAG_CRYPTO = 0x02;
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
    /**
     * @var string
     */
    protected $body = '';
    /**
     * @var int
     */
    protected $opcode = 0;
    /**
     * @var int
     */
    protected $flags = 0;
    /**
     * 创建帧的Fd
     * @var null|int
     */
    protected $fd = null;
    /**
     * 处理当前帧的工人ID
     * @var int
     */
    protected $workerId = self::WORKER_ID_NULL;
    /**
     * 常量缓存
     * @var array
     */
    private static $constantsCache;
    /**
     * 数据加密处理
     * @var Closure
     */
    private static $bodyEncryptHandle;
    /**
     * 数据解密处理
     * @var Closure
     */
    private static $bodyDecryptHandle;

    /**
     * TransferFrame constructor.
     * @param int|null $fd
     * @param int      $workerId
     */
    public function __construct(?int $fd = null, $workerId = self::WORKER_ID_NULL)
    {
        $this->fd = $fd;
        $this->workerId = $workerId;
        $this->flags = self::FLAG_COMPRESSION | self::FLAG_CRYPTO;
        $this->analyze();
    }

    /**
     * 解析类常量并生成名字到值的查询缓存
     */
    private function analyze()
    {
        if (null !== self::$constantsCache) {
            return;
        }
        try {
            $ref = new ReflectionClass($this);
        } catch (ReflectionException $e) {
            return;
        }
        self::$constantsCache = [];
        foreach ($ref->getConstants() as $name => $value) {
            $pos = strpos($name, '_');
            if (false === $pos) {
                $prefix = 'ROOT';
            } else {
                $prefix = substr($name, 0, $pos);
                $name = substr($name, $pos + 1);
            }
            self::$constantsCache[$prefix][$value] = $name;
        }
    }

    /**
     * @param Closure $encryptHandle
     */
    public static function setEncryptHandle(?Closure $encryptHandle): void
    {
        self::$bodyEncryptHandle = $encryptHandle;
    }

    /**
     * @param Closure $decryptHandle
     */
    public static function setDecryptHandle(?Closure $decryptHandle): void
    {
        self::$bodyDecryptHandle = $decryptHandle;
    }

    /**
     * 把数据解析为通信帧
     * @param string|null $package
     * @param int|null    $fd
     * @return self|null
     * @throws RpcInvalidFrame
     */
    public static function make(string $package, ?int $fd = null): ?self
    {
        try {
            $that = new self($fd);
            $that->unpack($package);
        } catch (Exception $e) {
            $e = new RpcInvalidFrame($e, "invalid frame: ({$e->getCode()}){$e->getMessage()}");
            $e->setOriginal($package);
            throw $e;
        }
        return $that;
    }

    /**
     * 生成ping帧数据 - 通信测试
     *
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
     * 生成pong帧数据 - 通信测试
     *
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
     * 生成link帧数据
     * 连接建立成功时由服务器发送此帧
     *
     * @param int|null $fd
     * @param int      $workerId
     * @return self
     */
    public static function link(?int $fd = null, $workerId = self::WORKER_ID_NULL): self
    {
        $ping = new self($fd, $workerId);
        $ping->opcode = self::OPCODE_LINK;
        $ping->flags = 0;
        return $ping;
    }

    /**
     * 获取当前帧类型
     * @return string
     */
    public function getOpcodeDesc()
    {
        return self::$constantsCache['OPCODE'][$this->opcode] ?? 'UNDEFINED';
    }

    /**
     * 把当前帧打包为数据包
     * @return string
     */
    public function packet(): string
    {
        if ($this->isCompression()) {
            $data = gzdeflate($this->body);
        } else {
            $data = $this->body;
        }
        if ($this->isCrypto() && is_callable(self::$bodyEncryptHandle)) {
            $data = call_user_func(self::$bodyEncryptHandle, $data, $this);
        } else {
            // 移除加密标志位
            $this->flags &= ~self::FLAG_CRYPTO;
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
     * 解包数据并将内容应用到当前帧
     * @param string $original
     * @return TransferFrame
     * @throws RpcInvalidFrame
     */
    public function unpack(string $original): self
    {
        [
            'l' => $length, 'v' => $version, 'flags' => $flags, 'op' => $opcode, 'worker' => $worker, 'h' => $hash,
        ] = unpack('Nl/Cv/Cflags/Cop/Nworker/H8h', $original); // 4+1+1+1+4+4

        if (self::VERSION !== $version) {
            // 版本不匹配
            throw new RpcInvalidFrame(null, "version does not match, {$version} != " . self::VERSION);
        }

        // 获取Body
        $data = substr($original, 15, $length) ?: '';

        // 校验数据
        if ($hash !== ($okhash = hash('adler32', $data))) {
            // 数据校验错误
            throw new RpcInvalidFrame(null, "hash does not match, {$hash} != {$okhash}");
        }

        // 设置操作码
        $this->opcode = $opcode;
        $this->flags = $flags;
        $this->workerId = $worker;

        // 解密
        if ($this->isCrypto() && is_callable(self::$bodyEncryptHandle)) {
            $data = call_user_func(self::$bodyDecryptHandle, $data, $this);
        } else {
            // 移除加密标志位
            $this->flags &= ~self::FLAG_CRYPTO;
        }
        // 解压Body
        if ($this->isCompression()) {
            $data = gzinflate($data);
        }

        // 设置Body数据
        $this->body = $data;

        return $this;
    }

    /**
     * 获取创建当前帧的Fd
     * @return int|null
     */
    public function getFd(): ?int
    {
        return $this->fd;
    }

    /**
     * 获取将要处理当前帧的工人ID
     * @return int
     */
    public function getWorkerId(): int
    {
        return $this->workerId;
    }

    /**
     * 设置当前帧将要发送到的工人ID
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
     * 设置帧内容
     * @param string $body
     * @return $this
     */
    public function setBody(string $body)
    {
        $this->body = $body;
        return $this;
    }

    /**
     * 获取帧操作码
     * @return int
     */
    public function getOpcode(): int
    {
        return $this->opcode;
    }

    /**
     * 设置帧操作码
     * @param int $opcode
     * @return $this
     */
    public function setOpcode(int $opcode)
    {
        $this->opcode = $opcode;
        return $this;
    }

    /**
     * 获取帧功能
     * @return int
     */
    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * 设置帧功能
     * @param int $flags
     * @return $this
     */
    public function setFlags(int $flags)
    {
        $this->flags = $flags;
        return $this;
    }

    /**
     * 当前帧是否压缩
     * @return bool
     */
    public function isCompression(): bool
    {
        return self::FLAG_COMPRESSION === ($this->flags & self::FLAG_COMPRESSION);
    }

    /**
     * 当前帧是否加密
     * @return bool
     */
    public function isCrypto(): bool
    {
        return self::FLAG_CRYPTO === ($this->flags & self::FLAG_CRYPTO);
    }

    /**
     * 获取帧调试信息
     * @return string
     */
    public function __toString(): string
    {
        $fd = $this->fd ?: 'null';
        $info = "tFrame: opcode={$this->getOpcodeDesc()}[{$this->opcode}], flags={$this->flags}";
        $info .= ", worker={$this->workerId}, fd={$fd}, body_len = " . strlen($this->body);
        return $info;
    }

    /**
     * 输出帧调试信息
     * @return array
     */
    public function __debugInfo()
    {
        return [$this->__toString()];
    }
}
