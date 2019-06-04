<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

interface TransferInterface
{
    public function __construct(RpcTerminal $rpc, string $name, array $argv);

    /**
     * @return int
     */
    public function getCid(): int;

    /**
     * @return int|null
     */
    public function getFd(): ?int;

    /**
     * @param int|null $fd
     * @return self
     */
    public function setFd(?int $fd): TransferInterface;

    /**
     * @return int
     */
    public function getRequestId(): int;

    /**
     * @param int $requestId
     * @return self
     */
    public function setRequestId(int $requestId): TransferInterface;

    /**
     * 获取RPC实例
     * @return RpcTerminal
     */
    public function getRpcTerminal(): RpcTerminal;

    /**
     * @return string
     */
    public function getMethodName(): string;

    /**
     * @return array
     */
    public function getMethodArgv(): array;

    /**
     * @return string
     */
    public function getArgvSerialize(): string;

    /**
     * @return int
     */
    public function getStopTime(): int;

    /**
     * 提交执行执行
     * @return mixed
     */
    public function exec();

    /**
     * 设置响应参数
     * @param string $result
     * @param bool   $failure
     */
    public function response(string $result, bool $failure);
}
