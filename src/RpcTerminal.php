<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use Exception;
use HZEX\SimpleRpc\Contract\TransferInterface;
use HZEX\SimpleRpc\Exception\RpcException;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Transfer\Fun\TransferFun;
use HZEX\SimpleRpc\Transfer\FunAsync\TransferFunAsync;
use HZEX\SimpleRpc\Transfer\Instance\TransferClass;
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
    /**
     * @var Closure|null
     */
    private $errorHandle = null;

    public function __construct(TunnelInterface $tunnel, RpcProvider $provider)
    {
        $this->tunnel = $tunnel;
        $this->provider = $provider->cloneInstance($this);
        $this->tunnel->setRpcTerminal($this);
    }

    /**
     * @return TunnelInterface
     */
    public function getTunnel(): TunnelInterface
    {
        return $this->tunnel;
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
     * @param Closure|null $errorHandle
     * @return RpcTerminal
     */
    public function setErrorHandle(?Closure $errorHandle): self
    {
        $this->errorHandle = $errorHandle;
        return $this;
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
                        'code' => RPC_RESPONSE_TIME_OUT,
                        'message' => "rpc request processing timeout: {$transfer}",
                        'trace' => '',
                    ]), true);
                }
            } catch (Throwable $e) {
                // TODO 统一日志输出
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
     * @throws RpcException
     */
    public function receive(TransferFrame $frame)
    {
        try {
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
        } catch (RpcException $exception) {
            if (is_callable($this->errorHandle)) {
                call_user_func($this->errorHandle, $exception);
            } else {
                throw $exception;
            }
            return false;
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
     * @return TransferFunAsync
     */
    public function methodAsync(?int $fd, string $name, ...$argv)
    {
        $transfer = new TransferFunAsync($this, $name, $argv);
        $transfer->setFd($fd);
        return $transfer;
    }

    /**
     * 实例远程方法请求
     * @param int|null $fd
     * @param string   $name
     * @param mixed    ...$argv
     * @return TransferFun
     */
    public function methodCo(?int $fd, string $name, ...$argv)
    {
        $transfer = new TransferFun($this, $name, $argv);
        $transfer->setFd($fd);
        return $transfer;
    }

    /**
     * 请求一个远程方法
     * @param TransferInterface $transfer
     * @return bool
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
     * @param TransferClass $transfer
     * @return bool
     */
    public function requestClass(TransferClass $transfer): bool
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
     */
    public function send(TransferFrame $frame): bool
    {
        $this->tunnel->send($frame);
        return true;
    }

    /**
     * 处理请求
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
     * 处理类请求
     * @param TransferFrame $frame
     * @return bool
     */
    protected function handleClassRequest(TransferFrame $frame)
    {
        [$oid, $rid, $name, $method, $argv] = TransferClass::unpack($frame->getBody());

        try {
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
                        $message = "invalid request: the object instance does not exist {$name}#{$oid}";
                        throw new RpcException($message, RPC_INVALID_RESPONSE_EXCEPTION);
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
     * @throws RpcException
     */
    protected function handleResponse(TransferFrame $frame, bool $failure = false)
    {
        $body = $frame->getBody();
        ['id' => $id] = unpack('Jid', $body);
        $result = substr($body, 8);

        if (null === ($request = $this->getWaitRequest($id))) {
            throw new RpcException("invalid request: does not exist id #{$id}", RPC_INVALID_RESPONSE_EXCEPTION);
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
}
