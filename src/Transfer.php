<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use ReflectionException;
use ReflectionFunction;

class Transfer
{
    /** @var int 执行超时时长 */
    private $execTimeLimit = 600;
    /** @var RpcTerminal 绑定的Rpc终端 */
    private $rpc;
    /** @var int 请求Id */
    private $requestId;
    /** @var int|null 连接Id */
    private $fd;
    /** @var int 方法创建时间 */
    private $createTime;
    /** @var int 方法超时时间 */
    private $stopTime;
    /** @var string 方法名称 */
    private $methodName;
    /** @var array 执行参数 */
    private $methodArgv;
    /** @var Closure[] 执行成功 */
    private $thens = [];
    /** @var Closure[] 执行失败 */
    private $fails = [];
    /** @var bool 是否已经执行 */
    private $exec = false;

    public function __construct(RpcTerminal $rpc, string $name, array $argv)
    {
        $this->rpc = $rpc;
        $this->methodName = $name;
        $this->methodArgv = $argv;
        $this->createTime = time();
        $this->stopTime = $this->createTime + $this->execTimeLimit;
    }

    /**
     * @return int|null
     */
    public function getFd(): ?int
    {
        return $this->fd;
    }

    /**
     * @param int|null $fd
     */
    public function setFd(?int $fd): void
    {
        $this->fd = $fd;
    }

    /**
     * @return int
     */
    public function getRequestId(): int
    {
        return $this->requestId;
    }

    /**
     * @param int $requestId
     */
    public function setRequestId(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * 设置执行超时
     * @param int $time
     * @return $this
     */
    public function setExecTimeOut(int $time)
    {
        $this->stopTime = $time;
        return $this;
    }

    /**
     * 获取RPC实例
     * @return RpcTerminal
     */
    public function getRpcTerminal()
    {
        return $this->rpc;
    }

    /**
     * @return string
     */
    public function getMethodName(): string
    {
        return $this->methodName;
    }

    /**
     * @return array
     */
    public function getMethodArgv(): array
    {
        return $this->methodArgv;
    }

    /**
     * @return string
     */
    public function getArgvSerialize(): string
    {
        return serialize($this->methodArgv);
    }

    /**
     * 获取执行超时
     * @return int
     */
    public function getExecTimeout(): int
    {
        return $this->stopTime;
    }

    /**
     * 执行成功响应
     * @param Closure $call
     * @return $this
     */
    public function then(Closure $call)
    {
        $this->thens[] = $call;
        return $this;
    }

    /**
     * 执行失败响应
     * @param Closure $call
     * @return $this
     */
    public function fail(Closure $call)
    {
        $this->fails[] = $call;
        return $this;
    }

    /**
     * 提交执行执行
     * @return bool
     */
    public function exec()
    {
        if ($this->exec) {
            return false;
        }
        $this->rpc->request($this);
        $this->exec = true;
        return true;
    }

    /**
     * 响应处理
     * @param string $result
     * @param bool   $failure
     * @throws RpcFunctionInvokeException
     */
    public function response(string $result, bool $failure)
    {
        $result = unserialize($result);
        if ($failure) {
            foreach ($this->fails as $closure) {
                // [code => int, message => string, trace => string]
                $this->invokeFunction($closure, $result['code'], $result['message'], $result['trace']);
            }
        } else {
            foreach ($this->thens as $closure) {
                $this->invokeFunction($closure, $result);
            }
        }
        $this->fails = [];
        $this->thens = [];
    }

    /**
     * 调用方法
     * @param Closure $function
     * @param array   $vars
     * @return mixed
     * @throws RpcFunctionInvokeException
     */
    public function invokeFunction(Closure $function, ...$vars)
    {
        try {
            $reflect = new ReflectionFunction($function);
            $args = $this->bindParams($reflect, $vars);
            return $reflect->invokeArgs($args);
        } catch (ReflectionException $e) {
            throw new RpcFunctionInvokeException('function invoke failure', 0, $e);
        }
    }

    /**
     * 绑定参数
     * @param ReflectionFunction $reflect 反射
     * @param array              $vars    参数
     * @return array
     */
    protected function bindParams(ReflectionFunction $reflect, $vars = [])
    {
        if ($reflect->getNumberOfParameters() == 0) {
            return [];
        }
        $left = 0;
        $argv = [];
        $params = $reflect->getParameters();
        foreach ($params as $param) {
            $class = $param->getClass();
            if ($class && $param->getPosition() === $left) {
                if (RpcTerminal::class === $class->getName()) {
                    $argv[] = $this->rpc;
                    $left++;
                } elseif (Transfer::class === $class->getName()) {
                    $argv[] = $this;
                    $left++;
                }
            }
        }
        $argv = array_merge($argv, $vars);
        return $argv;
    }
}
