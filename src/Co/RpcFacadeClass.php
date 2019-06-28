<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

use Co;
use HZEX\SimpleRpc\Exception\RpcRemoteExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcTerminal;
use think\Container;

/**
 * 远程类门面
 * Class RpcFacadeClass
 * @package HZEX\SimpleRpc
 */
abstract class RpcFacadeClass
{
    /**
     * @var RpcTerminal
     */
    private $terminal;

    /**
     * @var int|null
     */
    private $fd;

    /**
     * @var RpcClassCo
     */
    private $remoteObject;

    /**
     * @var array
     */
    private $constructArgv;

    /**
     * 实例构建计算
     * @var int
     */
    private $constructCount = 0;

    /**
     * @var bool 实例自动重建
     */
    protected $instanceAutoReconstruction = false;

    /**
     * 当前等待构建协程
     * @var int
     */
    private $currentWaitConstructCo = -1;

    /**
     * @var FacadeHandle
     */
    protected $facadeHandle;

    /**
     * @return string
     */
    abstract protected function getFacadeClass(): string;

    /**+
     * @param int|null $fd
     * @param mixed    ...$argv
     * @return static
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public static function new(?int $fd = null, ...$argv)
    {
        /** @var RpcTerminal $terminal */
        $terminal = Container::getInstance()->make(RpcTerminal::class);
        return (new static($terminal, $fd, $argv));
    }

    /**
     * RpcFacade2 constructor.
     * @param RpcTerminal $rpc
     * @param int|null    $fd
     * @param array       $argv
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public function __construct(RpcTerminal $rpc, ?int $fd, array $argv)
    {
        $this->terminal = $rpc;
        $this->fd = $fd;
        $this->constructArgv = $argv;

        $this->remoteObject = new RpcClassCo($this->terminal, $this->fd, $this->getFacadeClass());
        $this->__constructInstance();
    }

    /**
     * 内部实例方法
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    private function __constructInstance()
    {
        if (-1 === $this->currentWaitConstructCo) {
            $this->currentWaitConstructCo = Co::getCid();
        } else {
            // 等待类构建成功
            while (-1 !== $this->currentWaitConstructCo) {
                Co::sleep(0.05);
            }
            return;
        }

        // 构建前处理
        if (is_object($this->facadeHandle) && $this->facadeHandle instanceof FacadeHandle) {
            $result = $this->facadeHandle->constructBefore($this, $this->constructArgv);
            if (null !== $result) {
                $this->constructArgv = $result;
            }
        }

        // 开始实例化
        $this->remoteObject->instance($this->constructArgv);

        // 构建后处理
        if (is_object($this->facadeHandle) && $this->facadeHandle instanceof FacadeHandle) {
            $this->facadeHandle->constructAfter($this);
        }

        // 构建计数
        $this->constructCount++;
        $this->currentWaitConstructCo = -1;
    }

    /**
     * 类实例Id
     * @return int
     */
    public function getObjectId(): int
    {
        return $this->remoteObject->getObjectId();
    }

    /**
     * 实例计数
     * @return int
     */
    public function getConstructCount(): int
    {
        return $this->constructCount;
    }

    /**
     * @param string $name
     * @param        $arguments
     * @return mixed
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public function __call(string $name, $arguments)
    {
        try {
            return $this->handleResult($name, $this->remoteObject->method($name, $arguments));
        } catch (RpcRemoteExecuteException $e) {
            if ($this->instanceAutoReconstruction && $e->getCode() === RPC_INVALID_RESPONSE_EXCEPTION) {
                try {
                    $this->__constructInstance();
                    return $this->handleResult($name, $this->remoteObject->method($name, $arguments));
                } catch (RpcRemoteExecuteException $e) {
                    throw (new RpcRemoteExecuteException('[retry]' . $e->getMessage(), $e->getCode(), $e))
                        ->setRemoteTrace($e->getRemoteTrace());
                }
            }
            throw $e;
        }
    }

    private function handleResult($method, $result)
    {
        if (is_string($result) && $result === "CHAIN:{$this->getObjectId()}:{$method}") {
            $result = $this;
        }
        return $result;
    }

    /**
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public function __destruct()
    {
        $this->remoteObject->destroy();
    }
}
