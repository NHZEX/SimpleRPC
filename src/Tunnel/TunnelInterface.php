<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;

interface TunnelInterface
{
    /**
     * 设置终端通道关联
     * @param RpcTerminal $terminal
     * @return TunnelInterface
     */
    public function setRpcTerminal(RpcTerminal $terminal): self;

    /**
     * 获取工人Id
     * @return int
     */
    public function getWorkerId(): int;

    /**
     * 发送数据
     * @param TransferFrame   $frame
     * @return bool
     */
    public function send(TransferFrame $frame): bool;

    /**
     * 设置停止发送
     * @return void
     */
    public function stop(): void;

    /**
     * 是否停止发送
     * @return bool
     */
    public function isStop(): bool;
}
