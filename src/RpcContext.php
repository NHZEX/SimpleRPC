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

    public static function setSession(RpcSession $session)
    {
        Coroutine::getContext()[__CLASS__ . '_session'] = $session;
    }

    public static function getSession(): ?RpcSession
    {
        return Coroutine::getContext()[__CLASS__ . '_session'] ?? null;
    }

    public static function copyContext(int $cid)
    {
        $context = Coroutine::getContext($cid);
        $target = Coroutine::getContext();
        foreach ($context as $key => $value) {
            if (0 === strpos($key, __CLASS__)) {
                $target[$key] = $value;
            }
        }
    }
}
