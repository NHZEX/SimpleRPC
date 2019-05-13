<?php

namespace HZEX\SimpleRpc\Protocol;

class PrcFrame
{
    /** @var string */
    protected $data;
    /** @var int */
    protected $opcode = 0;
    /** @var int */
    protected $flags = 0;

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
     * @param int $opcode
     * @return $this
     */
    public function setOpcode(int $opcode)
    {
        $this->opcode = $opcode;
        return $this;
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
     * PrcFrame constructor.
     * @param string|null $package
     */
    public function __construct(?string $package = null)
    {
        if (null !== $package) {
            $this->unpack($package);
        }
    }

    /**
     * @return string
     */
    public function pack()
    {
        if ($this->flags & RpcProtocol::FLAG_COMPRESSION) {
            $data = gzdeflate($this->data);
        } else {
            $data = $this->data;
        }
        $hash = hash('adler32', $data);
        $length = strlen($data);

        // a4NCCNH8 = 18
        $package = pack(
            'a4NCCNH8',
            RpcProtocol::HEAD,
            RpcProtocol::VERSION,
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
        if (18 > strlen($package) && $package[0] === RpcProtocol::HEAD[0]) {
            return null;
        }

        [
            'head' => $head,
            'version' => $version,
            'opcode' => $opcode,
            'flags' => $flags,
            'length' => $length,
            'hash' => $hash,
        ] = unpack('a4head/Nversion/Copcode/Cflags/Nlength/H8hash', $package);

        if (RpcProtocol::HEAD !== $head) {
            return null;
        }

        $data = substr($package, 18, $length);
        if ($hash !== hash('adler32', $data)) {
            return null;
        }

        if ($flags & RpcProtocol::FLAG_COMPRESSION) {
            $data = gzinflate($data);
        }

        $this->opcode = $opcode;
        $this->flags = $flags;
        $this->data = $data;
        return $this;
    }
}
