<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use HZEX\SimpleRpc\Struct\Connection;

class RpcSession
{
    /**
     * @var int
     */
    public $workerId;
    /**
     * @var Connection
     */
    public $connection;
    /**
     * @var string
     */
    public $cryptoKey;

    public function __construct(int $workerId, Connection $info)
    {
        $this->workerId = $workerId;
        $this->connection = $info;
    }

    public function initPassword()
    {
        $this->cryptoKey = openssl_random_pseudo_bytes(16);
    }

    /**
     * @return int
     */
    public function getWorkerId(): ?int
    {
        return $this->workerId;
    }

    /**
     * @return Connection
     */
    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    /**
     * @return mixed
     */
    public function getCryptoKey(): ?string
    {
        return $this->cryptoKey;
    }
}
