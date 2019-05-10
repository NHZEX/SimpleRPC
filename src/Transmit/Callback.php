<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Transmit;

use Closure;

class Callback implements TransmitInterface
{
    /** @var callable */
    private $call;

    /**
     * SwooleWebSocket constructor.
     * @param Closure|callable $fun
     */
    public function __construct(callable $fun)
    {
        $this->call = $fun;
    }

    /**
     * 发送数据包
     * @param string $data
     * @return bool
     */
    public function __invoke(string $data): bool
    {
        return call_user_func($this->call, $data);
    }
}
