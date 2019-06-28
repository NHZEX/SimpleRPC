<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Exception;
use HZEX\SimpleRpc\Co\TransferCo;
use HZEX\SimpleRpc\Co\TransferMethodCo;
use HZEX\SimpleRpc\Exception\RpcInvalidResponseException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Tunnel\TunnelInterface;
use Throwable;

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
     * @var TransferInterface[]
     */
    private $requestList = [];
    /**
     * 对象实例托管
     * @var array
     */
    private $instance = [];
    /**
     * 客户实例
     * @var array
     */
    private $clientBind = [];
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
     * @return SnowFlake
     */
    public function getSnowflake(): SnowFlake
    {
        return $this->snowflake;
    }

    /**
     * 清理超时方法
     * @return int
     */
    public function gcTransfer()
    {
        $gcTime = time();
        /** @var TransferInterface[] $gcWait */
        $gcWait = [];
        foreach ($this->requestList as $key => $transfer) {
            if ($gcTime > $transfer->getStopTime()) {
                $gcWait[] = $transfer;
                unset($this->requestList[$key]);
            }
        }
        // TODO 改为使用队列处理超时请求
        go(function () use ($gcWait) {
            try {
                foreach ($gcWait as $transfer) {
                    $transfer->response(serialize([
                        'code' => -1,
                        'message' => 'rpc request processing timeout',
                        'trace' => '',
                    ]), true);
                }
            } catch (Throwable $e) {
                echo (string) $e;
            }
        });
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
     * 销毁实例托管
     * @param int|null $fd
     * @return bool
     */
    public function destroyInstanceHosting(?int $fd)
    {
        foreach (($this->clientBind[$fd] ?? []) as $bind => $time) {
            unset($this->instance[$bind]);
        }
        unset($this->clientBind[$fd]);

        return true;
    }

    /**
     * @return int
     */
    public function countInstanceHosting()
    {
        return count($this->instance);
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
     * @throws RpcInvalidResponseException
     * @throws RpcSendDataException
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
            case TransferFrame::OPCODE_CLASS:
                $this->handleClassRequest($frame);
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
     * @return TransferInterface
     */
    private function getWaitRequest(int $serial): ?TransferInterface
    {
        return $this->requestList[$serial] ?? null;
    }

    /**
     * 添加等待请求
     * @param int               $serial
     * @param TransferInterface $transfer
     * @return $this
     */
    private function addWaitRequest(int $serial, TransferInterface $transfer)
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
     * 实例远程方法请求
     * @param int|null $fd
     * @param string   $name
     * @param mixed    ...$argv
     * @return TransferCo
     */
    public function methodCo(?int $fd, string $name, ...$argv)
    {
        $transfer = new TransferCo($this, $name, $argv);
        $transfer->setFd($fd);
        return $transfer;
    }

    /**
     * 请求一个远程方法
     * @param TransferInterface $transfer
     * @return bool
     * @throws RpcSendDataException
     */
    public function request(TransferInterface $transfer): bool
    {
        $methodName = $transfer->getMethodName();
        $serial = $this->snowflake->nextId();
        // 设置请求ID
        $transfer->setRequestId($serial);
        // 关联执行类
        $this->addWaitRequest($serial, $transfer);
        // 组包
        $pack = pack('CJ', strlen($methodName), $serial);
        $pack = $pack . $methodName . $transfer->getArgvSerialize();
        // 发送包数据
        $frame = new TransferFrame($transfer->getFd());
        $frame->setOpcode($frame::OPCODE_EXECUTE);
        $frame->setBody($pack);
        return $this->send($frame);
    }

    /**
     * @param TransferMethodCo $transfer
     * @return bool
     * @throws RpcSendDataException
     */
    public function requestClass(TransferMethodCo $transfer): bool
    {
        // 生成请求ID
        $serial = $this->snowflake->nextId();
        // 设置请求ID
        $transfer->setRequestId($serial);
        // 关联执行类
        $this->addWaitRequest($serial, $transfer);
        // 组包
        $pack = $transfer::pack($transfer);
        // 发送包数据
        $frame = new TransferFrame($transfer->getFd());
        $frame->setOpcode($frame::OPCODE_CLASS);
        $frame->setBody($pack);
        return $this->send($frame);
    }

    /**
     * 发送请求数据
     * @param TransferFrame $frame
     * @return bool
     * @throws RpcSendDataException
     */
    public function send(TransferFrame $frame): bool
    {
        if (false === $this->tunnel->send($frame)) {
            throw new RpcSendDataException();
        }
        return true;
    }

    /**
     * 处理请求
     * @param TransferFrame $frame
     * @return bool
     * @throws RpcSendDataException
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
     * 处理类请求
     * @param TransferFrame $frame
     * @return bool
     * @throws RpcSendDataException
     */
    protected function handleClassRequest(TransferFrame $frame)
    {
        [$oid, $rid, $name, $method, $argv] = TransferMethodCo::unpack($frame->getBody());

        try {
            // TODO 原型
            switch ($method) {
                case '__construct':
                    $oid = $this->snowflake->nextId();
                    $class = $this->provider->getProvider($name);
                    // 创建实例
                    $this->instance[$oid] = new $class(...$argv);
                    // 绑定客户
                    $this->clientBind[$frame->getFd()][$oid] = time();
                    $result = $oid;
                    break;
                case '__destruct':
                    // 销毁实例
                    unset($this->instance[$oid]);
                    // 销毁绑定
                    unset($this->clientBind[$frame->getFd()][$oid]);
                    $result = true;
                    break;
                default:
                    if (false === isset($this->instance[$oid])) {
                        $message = "invalid request: the instance does not exist {$name}#{$oid}";
                        throw new RpcInvalidResponseException($message);
                    }
                    // 调用实例
                    $result = $this->instance[$oid]->$method(...$argv);
                    // 兼容链式调用
                    if (is_object($result) && spl_object_hash($result) === spl_object_hash($this->instance[$oid])) {
                        $result = "CHAIN:{$oid}:{$method}";
                    }
                    // 更新信息
                    $this->clientBind[$frame->getFd()][$oid] = time();
            }
        } catch (Exception $exception) {
            // 记录错误信息
            $this->respFailure($rid, $frame, $exception);
            return false;
        }
        $this->respResult($rid, $frame, $result);
        return true;
    }

    /**
     * 处理结果
     * @param TransferFrame $frame
     * @param bool          $failure
     * @throws RpcInvalidResponseException
     */
    protected function handleResponse(TransferFrame $frame, bool $failure = false)
    {
        $body = $frame->getBody();
        ['id' => $id] = unpack('Jid', $body);
        $result = substr($body, 8);

        if (null === ($request = $this->getWaitRequest($id))) {
            throw new RpcInvalidResponseException("invalid request: does not exist id #{$id}");
        }

        // 请求响应处理
        $this->getWaitRequest($id)->response($result, $failure);
        $this->delWaitRequest($id);
    }

    /**
     * 发送成功响应
     * @param int           $requestId
     * @param TransferFrame $recFrame
     * @param mixed         $result
     * @return bool
     * @throws RpcSendDataException
     */
    protected function respResult(int $requestId, TransferFrame $recFrame, $result)
    {
        $frame = new TransferFrame($recFrame->getFd(), $recFrame->getWorkerId());
        $frame->setOpcode($frame::OPCODE_RESULT);
        $frame->setBody(pack('J', $requestId) . serialize($result));
        return $this->send($frame);
    }

    /**
     * 发送失败响应
     * @param int           $requestId
     * @param TransferFrame $recFrame
     * @param Exception     $e
     * @return bool
     * @throws RpcSendDataException
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
        return $this->send($frame);
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
