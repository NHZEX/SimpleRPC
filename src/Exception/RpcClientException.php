<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Exception;

use Exception;

class RpcClientException extends Exception
{
    /**
     * 连接是否断开
     * @return bool
     */
    public function isDisconnect(): bool
    {
        // https://wiki.swoole.com/wiki/page/172.html
        // https://wiki.swoole.com/wiki/page/1034.html
        switch ($this->getCode()) {
            case 100: // ENETDOWN Network is down 网络瘫痪
            case 101: // ENETUNREACH Network is unreachable 网络不可达
            case 102: // ENETRESET Network dropped 网络连接丢失
            case 103: // ECONNABORTED Software caused connection 软件导致连接中断
            case 104: // ECONNRESET Connection reset by 连接被重置
            case 110: // ETIMEDOUT Connection timed 连接超时
            case 111: // ECONNREFUSED  Connection refused 拒绝连接
            case 112: // EHOSTDOWN Host is down 主机已关闭
            case 113: // EHOSTUNREACH No route to host 没有主机的路由
                return true;
        }
        return false;
    }
}
