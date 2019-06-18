<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Co;

class RpcContext
{
    public static function setFd(int $fd)
    {
        Co::getContext()[__CLASS__]['fd'] = $fd;
    }

    public static function getFd(): ?int
    {
        return Co::getContext()[__CLASS__]['fd'] ?? null;
    }

    public static function destroy()
    {
        // 协程上下文会随着协程完结自行销毁
        // unset(Co::getContext()[__CLASS__]);
    }
}
