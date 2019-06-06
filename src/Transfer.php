<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use InvalidArgumentException;
use LengthException;
use LogicException;
use ReflectionException;
use ReflectionFunction;

class Transfer implements TransferInterface
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
    /** @var Middleware[] */
    private $middlewares = [];
    /** @var Closure[] 执行成功 */
    private $thens = [];
    /** @var Closure[] 执行失败 */
    private $fails = [];
    /** @var bool 是否已经执行 */
    private $exec = false;
    /** @var bool 是否失败响应 */
    private $isFailure;
    /** @var mixed 响应内容 */
    private $result;

    public function __construct(RpcTerminal $rpc, string $name, array $argv)
    {
        if (($namelen = strlen($name)) > 255) {
            throw new LengthException('方法名称长度超出支持范围: ' . $namelen);
        }
        $this->rpc = $rpc;
        $this->methodName = $name;
        $this->methodArgv = $argv;
        $this->createTime = time();
        $this->stopTime = $this->createTime + $this->execTimeLimit;
    }

    public function getCid(): int
    {
        return -1;
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
     * @return self
     */
    public function setFd(?int $fd): TransferInterface
    {
        $this->fd = $fd;
        return $this;
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
     * @return self
     */
    public function setRequestId(int $requestId): TransferInterface
    {
        $this->requestId = $requestId;
        return $this;
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
    public function getRpcTerminal(): RpcTerminal
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
     * @return mixed
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * @return bool
     */
    public function isFailure(): bool
    {
        return $this->isFailure;
    }

    /**
     * @return int
     */
    public function getStopTime(): int
    {
        return $this->stopTime;
    }

    /**
     * 添加中间件
     * @param Closure $middleware
     * @return Transfer
     */
    public function middleware(Closure $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
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
     * @throws Exception\RpcSendDataException
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
        $this->isFailure = $failure;
        $this->result = unserialize($result);
        // 调度中间件
        if (false === empty($this->middlewares)) {
            $this->resolve()($this);
        }
        // 处理响应回调
        if ($failure) {
            foreach ($this->fails as $closure) {
                // [code => int, message => string, trace => string]
                $this->invokeFunction(
                    $closure,
                    $this->result['code'],
                    $this->result['message'],
                    $this->result['trace']
                );
            }
        } else {
            foreach ($this->thens as $closure) {
                $this->invokeFunction($closure, $this->result);
            }
        }
        $this->fails = [];
        $this->thens = [];
    }

    protected function resolve()
    {
        return function (Transfer $request) {
            if (0 === count($this->middlewares)) {
                return $request;
            }

            $middleware = array_shift($this->middlewares);

            if (null === $middleware || false === $middleware instanceof Closure) {
                throw new InvalidArgumentException('The queue was exhausted, with no response returned');
            }

            $response = $middleware($request, $this->resolve());

            if (!$response instanceof Transfer) {
                throw new LogicException('The middleware must return Transfer instance');
            }

            return $response;
        };
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
