<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;

interface Middleware
{
    /**
     * 处理请求
     * @param Transfer $transfer
     * @param Closure  $next
     * @return mixed
     */
    public function handle(Transfer $transfer, Closure $next);
}
