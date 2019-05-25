<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Tunnel\TunnelInterface;
use Swoole\Timer;

class RpcTerminal
{
    /** @var TunnelInterface */
    private $tunnel;
    /** @var int */
    private $keepTime;

    public function __construct(TunnelInterface $tunnel)
    {
        $this->tunnel = $tunnel;
        $this->tunnel->setRpcTerminal($this);
    }

    public function connected()
    {
        $this->keepTime = Timer::tick(1000, function () {
            $this->tunnel->send(TransferFrame::ping(), null);
        });
    }

    /**
     * RPC包接收处理
     * @param string   $packet
     * @param int|null $fd
     * @return bool
     */
    public function receive(string $packet, ?int $fd)
    {
        $frame = TransferFrame::make($packet, $fd);
        if (false === $frame instanceof TransferFrame) {
            return false;
        }

        // 判断包类型
        switch ($frame->getOpcode()) {
            case TransferFrame::OPCODE_PING:
                $this->tunnel->send(TransferFrame::pong(), $frame->getFd());
                break;
            case TransferFrame::OPCODE_EXECUTE:
                $this->handleRequest($frame);
                break;
            case TransferFrame::OPCODE_RESULT:
                $this->handleResponse($frame);
                break;
            case TransferFrame::OPCODE_FAILURE:
                $this->handleResponse($frame, true);
                break;
        }

        return true;
    }

    public function closed()
    {

    }

    /**
     * 处理响应
     * @param TransferFrame $frame
     * @return bool
     * @throws RpcSendDataErrorException
     */
    protected function handleRequest(TransferFrame $frame)
    {
        $body = $frame->getBody();
        ['len' => $nlen, 'execid' => $execid] = unpack('Clen/a16execid', $body);
        $name = substr($body, 17, $nlen);
        $argv = substr($body, 17 + $nlen);
        $argv = unserialize($argv);

        try {
            $result = $this->provider->invoke($name, $argv);
        } catch (Exception $exception) {
            // 记录错误信息
            $this->respFailure($execid, $exception);
            return false;
        }
        $this->respResult($execid, $result);
        return true;
    }

    /**
     * 处理请求
     * @param TransferFrame $frame
     * @param bool          $failure
     * @throws RpcFunctionInvokeException
     */
    protected function handleResponse(TransferFrame $frame, bool $failure = false)
    {
        $body = $frame->getBody();
        $execid = substr($body, 0, 16);
        $result = substr($body, 16);

        $this->getExecMethod($execid)->response($result, $failure);

        $this->delExecMethod($execid);
    }
}
