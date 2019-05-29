<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Co;

class RpcContext
{
    private static $context = [];

    public static function setFd(int $fd)
    {
        self::$context[Co::getCid()]['fd'] = $fd;
    }

    public static function getFd(): int
    {
        return self::$context[Co::getCid()]['fd'];
    }

    public static function destroy()
    {
        unset(self::$context[Co::getCid()]);
    }
}
