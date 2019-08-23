<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Swoole\Coroutine;

class RpcContext
{
    public static function setFd(int $fd)
    {
        Coroutine::getContext()[__CLASS__ . '_fd'] = $fd;
    }

    public static function getFd(): ?int
    {
        return Coroutine::getContext()[__CLASS__ . '_fd'] ?? null;
    }

    public static function setUid(?int $uid)
    {
        Coroutine::getContext()[__CLASS__ . '_uid'] = $uid;
    }

    public static function getUid(): ?int
    {
        return Coroutine::getContext()[__CLASS__ . '_uid'] ?? null;
    }
}
