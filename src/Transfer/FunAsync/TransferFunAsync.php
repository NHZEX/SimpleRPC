<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Transfer\FunAsync;

use Closure;
use HZEX\SimpleRpc\Exception\RpcProviderException;
use HZEX\SimpleRpc\Middleware;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\TransferAbstract;
use InvalidArgumentException;
use LengthException;
use LogicException;

/**
 * 调用方法
 * Class TransferFun
 * @package HZEX\SimpleRpc\Transfer\Fun
 */
class TransferFunAsync extends TransferAbstract
{
    /** @var Middleware[] */
    private $middlewares = [];
    /** @var Closure[] 执行成功 */
    private $thens = [];
    /** @var Closure[] 执行失败 */
    private $fails = [];
    /** @var bool 是否已经执行 */
    private $exec = false;

    public function __construct(RpcTerminal $rpc, string $name, array $argv)
    {
        if (($namelen = strlen($name)) > 255) {
            throw new LengthException('方法名称长度超出支持范围: ' . $namelen);
        }
        $this->rpc = $rpc;
        $this->methodName = $name;
        $this->methodArgv = $argv;
    }

    /**
     * @return mixed
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * 添加中间件
     * @param Closure $middleware
     * @return TransferFunAsync
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
     */
    public function exec()
    {
        if ($this->exec) {
            return false;
        }
        // 记录启动时间
        $this->execTime = time();
        // 计算停止时间
        $this->stopTime = $this->execTime + $this->timeout;
        // 发送执行请求
        $this->rpc->request($this);
        $this->exec = true;
        return true;
    }

    /**
     * 响应处理
     * @param string $result
     * @param bool   $failure
     * @throws RpcProviderException
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
                $this->rpc->getProvider()->invokeFunction(
                    $closure,
                    [
                        $this->result['code'],
                        $this->result['message'],
                        $this->result['trace'],
                    ]
                );
            }
        } else {
            foreach ($this->thens as $closure) {
                $this->rpc->getProvider()->invokeFunction($closure, [$this->result]);
            }
        }
        $this->fails = [];
        $this->thens = [];
    }

    protected function resolve()
    {
        return function (TransferFunAsync $request) {
            if (0 === count($this->middlewares)) {
                return $request;
            }

            $middleware = array_shift($this->middlewares);

            if (null === $middleware || false === $middleware instanceof Closure) {
                throw new InvalidArgumentException('The queue was exhausted, with no response returned');
            }

            $response = $middleware($request, $this->resolve());

            if (!$response instanceof TransferFunAsync) {
                throw new LogicException('The middleware must return Transfer instance');
            }

            return $response;
        };
    }
}
