<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use HZEX\SimpleRpc\Exception\RpcFunctionNotExistException;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

class RpcProvider
{
    /**
     * 服务提供商
     * @var array
     */
    protected $provider = [];

    /**
     * 服务实例
     * @var array
     */
    protected $instances = [];

    /**
     * Rpc操作终端
     * @var RpcTerminal
     */
    private $terminal;

    /**
     * 克隆Rpc专用实例
     * @param RpcTerminal $rpc
     * @return $this
     */
    public function cloneInstance(RpcTerminal $rpc)
    {
        $instance = clone $this;
        $instance->terminal = $rpc;

        return $instance;
    }

    /**
     * 绑定一个类、闭包、实例、接口实现到容器
     * @param string $name     类标识、接口
     * @param mixed  $concrete 要绑定的类、闭包或者实例
     * @return $this
     */
    public function bind($name, $concrete = null)
    {
        if (is_array($concrete)
            && 2 === count($concrete)
            && is_string($concrete[1])
            && (is_string($concrete[0]) || is_object($concrete[0]))
        ) {
            $concrete = Closure::fromCallable($concrete);
        } elseif (is_string($concrete)
            && false !== strpos($concrete, '::')
        ) {
            $concrete = Closure::fromCallable($concrete);
        } elseif (is_string($concrete) && function_exists($concrete)) {
            $concrete = Closure::fromCallable($concrete);
        }

        if ($concrete instanceof Closure) {
            $this->provider[$name] = $concrete;
        } elseif (is_object($concrete)) {
            $this->instance($name, $concrete);
        } else {
            $this->provider[$name] = $concrete;
        }
        return $this;
    }

    /**
     * 绑定一个类实例到容器
     * @param string $name     类名或者标识
     * @param object $instance 类的实例
     * @return $this
     */
    public function instance(string $name, $instance)
    {
        if (isset($this->provider[$name])) {
            $bind = $this->provider[$name];
            if (is_string($bind)) {
                return $this->instance($bind, $instance);
            }
        }
        $this->instances[$name] = $instance;
        return $this;
    }

    /**
     * 判断容器中是否存在类及标识
     * @access public
     * @param string $name 类名或者标识
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->provider[$name]) || isset($this->instances[$name]);
    }

    /**
     * 获取提供商实例
     * @param string $name 类名或者标识
     * @param array  $vars 变量
     * @return mixed
     * @throws RpcFunctionInvokeException
     * @throws RpcFunctionNotExistException
     */
    public function invoke(string $name, array $vars = [])
    {
        $classNamePos = strpos($name, '.');
        $className = false === $classNamePos ? false : substr($name, 0, $classNamePos);

        if ($className && $this->has($className)) {
            $methodName = substr($name, $classNamePos + 1);
            $name = $className;

            if (isset($this->instances[$name])) {
                $class = $this->instances[$name];
            } elseif (isset($this->provider[$name])) {
                $concrete = $this->provider[$name];
                if (is_object($concrete)) {
                    $class = $concrete;
                } elseif (is_string($concrete)) {
                    $class = new $concrete();
                }
            }

            if (isset($class)) {
                return $this->invokeClassMethod($class, $methodName, $vars);
            }
        }

        if (isset($this->provider[$name])) {
            $concrete = $this->provider[$name];
            if ($concrete instanceof Closure) {
                return $this->invokeFunction($concrete, $vars);
            }
        }

        throw new RpcFunctionNotExistException("invoke function {$name} not exist");
    }

    /**
     * 调用方法
     * @param object $class
     * @param string $method
     * @param array  $vars
     * @return mixed
     * @throws RpcFunctionInvokeException
     */
    public function invokeClassMethod($class, string $method, array $vars)
    {
        try {
            $reflect = new ReflectionMethod($class, $method);
            if (false === $reflect->isPublic()) {
                throw new RpcFunctionInvokeException('invoke protected function');
            }
            if ($reflect->isStatic()) {
                $closure = $reflect->getClosure(null);
            } else {
                $closure = $reflect->getClosure($class);
            }
            return $this->invokeFunction($closure, $vars);
        } catch (ReflectionException $e) {
            throw new RpcFunctionInvokeException('function invoke failure', 0, $e);
        }
    }

    /**
     * 调用方法
     * @param Closure $function
     * @param array   $vars
     * @return mixed
     * @throws RpcFunctionInvokeException
     */
    public function invokeFunction(Closure $function, $vars)
    {
        try {
            $reflect = new ReflectionFunction($function);
            $argv = $this->bindParams($reflect, $vars);
            return $reflect->invokeArgs($argv);
        } catch (ReflectionException $e) {
            throw new RpcFunctionInvokeException('function invoke failure', 0, $e);
        }
    }

    /**
     * @param ReflectionFunctionAbstract  $reflect
     * @param                             $vars
     * @return array
     */
    protected function bindParams(ReflectionFunctionAbstract $reflect, $vars)
    {
        $argv = [];
        if ($reflect->getNumberOfParameters()) {
            $params = $reflect->getParameters();
            if ($class = $params[0]->getClass()) {
                if (RpcTerminal::class === $class->getName()) {
                    $argv[] = $this->terminal;
                }
            }
        }
        return array_merge($argv, $vars);
    }
}
