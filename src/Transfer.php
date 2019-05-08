<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use ReflectionException;
use ReflectionFunction;

class Transfer
{
    private $execTimeLimit = 600;
    /** @var Rpc */
    private $rpc;
    /** @var int */
    private $execTime;
    /** @var int */
    private $execTimeout;
    /** @var string */
    private $methodName;
    /** @var array */
    private $methodArgv;
    /** @var Closure[] */
    private $thens = [];
    /** @var Closure[] */
    private $fails = [];
    /** @var bool */
    private $exec = false;

    public function __construct(Rpc $rpc, string $name, array $argv)
    {
        $this->rpc = $rpc;
        $this->methodName = $name;
        $this->methodArgv = $argv;
        $this->execTime = time();
        $this->execTimeout = $this->execTime + $this->execTimeLimit;
    }

    /**
     * 设置执行超时
     * @param int $time
     * @return $this
     */
    public function setExecTimeOut(int $time)
    {
        $this->execTimeout = $time;
        return $this;
    }

    /**
     * 获取RPC实例
     * @return Rpc
     */
    public function getRpc()
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
        return $this->execTimeout;
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
        try {
            $this->rpc->execMethod($this);
            $this->exec = true;
        } catch (RpcFunctionInvokeException $e) {
            return false;
        }
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
                $this->invokeFunction($closure, ...$result);
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
            return call_user_func_array($function, $args);
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
                if (Rpc::class === $class->getName()) {
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
