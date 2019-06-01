<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Exception;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Tunnel\TunnelInterface;
use LengthException;

class RpcTerminal
{
    /**
     * @var TunnelInterface
     */
    private $tunnel;
    /**
     * @var RpcProvider
     */
    private $provider;
    /**
     * @var Transfer[]
     */
    private $requestList = [];
    /**
     * @var SnowFlake
     */
    private $snowflake;

    public function __construct(TunnelInterface $tunnel, RpcProvider $provider)
    {
        $this->tunnel = $tunnel;
        $this->provider = $provider->cloneInstance($this);
        $this->tunnel->setRpcTerminal($this);
    }

    /**
     * @return RpcProvider
     */
    public function getProvider(): RpcProvider
    {
        return $this->provider;
    }

    /**
     * 设置雪花ID生成器
     * @param SnowFlake $snowFlake
     * @return self
     */
    public function setSnowFlake(SnowFlake $snowFlake): self
    {
        $this->snowflake = $snowFlake;
        return $this;
    }

    /**
     * 清理超时方法
     * @return int
     */
    public function gcTransfer()
    {
        $gcTime = time();
        /** @var string[] $gcWait */
        $gcWait = [];
        foreach ($this->requestList as $key => $transfer) {
            if ($gcTime > $transfer->getExecTimeout()) {
                $gcWait[] = $transfer;
                unset($this->requestList[$key]);
            }
        }
        return count($gcWait);
    }

    /**
     * @return int
     */
    public function countTransfer()
    {
        return count($this->requestList);
    }

    /**
     * @param int|null $fd
     * @param int      $workerId
     * @return bool
     */
    public function ping(?int $fd = null, $workerId = 0)
    {
        return $this->tunnel->send(TransferFrame::ping($fd, $workerId));
    }

    /**
     * RPC包接收处理
     * @param TransferFrame $frame
     * @return bool
     * @throws RpcFunctionInvokeException
     */
    public function receive(TransferFrame $frame)
    {
        // 判断包类型
        switch ($frame->getOpcode()) {
            case TransferFrame::OPCODE_PING:
                $this->tunnel->send(TransferFrame::pong($frame->getFd()));
                break;
            case TransferFrame::OPCODE_EXECUTE:
                $this->handleRequest($frame);
                break;
            case TransferFrame::OPCODE_RESULT:
            case TransferFrame::OPCODE_FAILURE:
                $this->handleResponse($frame, $frame->getOpcode() === TransferFrame::OPCODE_FAILURE);
                break;
        }

        return true;
    }

    /**
     * 获取等待请求
     * @param int $serial
     * @return Transfer
     */
    private function getWaitRequest(int $serial)
    {
        return $this->requestList[$serial];
    }

    /**
     * 添加等待请求
     * @param int      $serial
     * @param Transfer $transfer
     * @return $this
     */
    private function addWaitRequest(int $serial, Transfer $transfer)
    {
        $this->requestList[$serial] = $transfer;
        return $this;
    }

    /**
     * 移除等待请求
     * @param int $serial
     * @return self
     */
    private function delWaitRequest(int $serial)
    {
        unset($this->requestList[$serial]);
        return $this;
    }

    /**
     * 实例远程方法请求
     * @param int|null $fd
     * @param string   $name
     * @param mixed    ...$argv
     * @return Transfer
     */
    public function method(?int $fd, string $name, ...$argv)
    {
        $transfer = new Transfer($this, $name, $argv);
        $transfer->setFd($fd);
        return $transfer;
    }

    /**
     * 请求一个远程方法
     * @param Transfer $transfer
     */
    public function request(Transfer $transfer)
    {
        $methodName = $transfer->getMethodName();
        if (($namelen = strlen($methodName)) > 255) {
            throw new LengthException('方法名称长度超出支持范围 ' . $namelen);
        }
        $serial = $this->snowflake->nextId();
        // 设置请求ID
        $transfer->setRequestId($serial);
        // 关联执行类
        $this->addWaitRequest($serial, $transfer);
        // 组包
        $pack = pack('CJ', $namelen, $serial);
        $pack = $pack . $methodName . $transfer->getArgvSerialize();
        // 发送包数据
        $frame = new TransferFrame($transfer->getFd());
        $frame->setOpcode($frame::OPCODE_EXECUTE);
        $frame->setBody($pack);
        $this->tunnel->send($frame);
    }

    /**
     * 处理响应
     * @param TransferFrame $frame
     * @return bool
     */
    protected function handleRequest(TransferFrame $frame)
    {
        $body = $frame->getBody();
        ['len' => $nlen, 'id' => $execid] = unpack('Clen/Jid', $body);
        $name = substr($body, 9, $nlen);
        $argv = substr($body, 9 + $nlen);
        $argv = unserialize($argv);

        try {
            $result = $this->provider->invoke($name, $argv);
        } catch (Exception $exception) {
            // 记录错误信息
            $this->respFailure($execid, $frame, $exception);
            return false;
        }
        $this->respResult($execid, $frame, $result);
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
        ['id' => $id] = unpack('Jid', $body);
        $result = substr($body, 8);
        $this->getWaitRequest($id)->response($result, $failure);

        $this->delWaitRequest($id);
    }

    /**
     * 方法响应
     * @param int           $requestId
     * @param TransferFrame $recFrame
     * @param mixed         $result
     * @return bool
     */
    protected function respResult(int $requestId, TransferFrame $recFrame, $result)
    {
        $frame = new TransferFrame($recFrame->getFd(), $recFrame->getWorkerId());
        $frame->setOpcode($frame::OPCODE_RESULT);
        $frame->setBody(pack('J', $requestId) . serialize($result));
        return $this->tunnel->send($frame);
    }

    /**
     * 方法响应
     * @param int           $requestId
     * @param TransferFrame $recFrame
     * @param Exception     $e
     * @return bool
     */
    protected function respFailure(int $requestId, TransferFrame $recFrame, Exception $e)
    {
        $trace = $e;
        $traceContent = '';
        do {
            $traceContent .= "Position: {$trace->getFile()}:{$trace->getLine()}\n";
            $traceContent .= 'Class: \\' . get_class($trace) . "\n";
            $traceContent .= "Message: [{$trace->getCode()}] {$trace->getMessage()}\n";
            $traceContent .= "{$trace->getTraceAsString()}\n";
        } while ($trace = $trace->getPrevious());

        $e = [
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'trace' => $traceContent,
        ];

        $frame = new TransferFrame($recFrame->getFd(), $recFrame->getWorkerId());
        $frame->setOpcode($frame::OPCODE_FAILURE);
        $frame->setBody(pack('J', $requestId) . serialize($e));
        return $this->tunnel->send($frame);
    }

    /**
     * @param int $length
     * @return string
     */
    protected function generateRandomString($length = 16)
    {
        static $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        static $charactersLength = 36 - 1;

        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[mt_rand(0, $charactersLength)];
        }
        return $randomString;
    }
}
