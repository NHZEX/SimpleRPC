<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;

interface Middleware
{
    /**
     * 处理请求
     * @param TransferAbstract $transfer
     * @param Closure  $next
     * @return mixed
     */
    public function handle(TransferAbstract $transfer, Closure $next);
}
