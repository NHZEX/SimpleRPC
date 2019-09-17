<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use HZEX\SimpleRpc\Exception\ClassNotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * 容器类 只支持托管实例
 *
 * Class Container
 * @package HZEX\SimpleRpc
 *
 * @property RpcTerminal $rpcTerminal
 */
class Container implements ContainerInterface
{
    /**
     * 容器对象实例
     * @var Container
     */
    protected static $instance;

    /**
     * 容器中的对象实例
     * @var array
     */
    protected $instances = [];

    /**
     * 容器绑定标识
     * @var array
     */
    protected $bind = [
        'rpcTerminal' => RpcTerminal::class,
    ];

    /**
     * 获取当前容器的实例（单例）
     * @access public
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * 根据别名获取真实类名
     * @param  string $abstract
     * @return string
     */
    public function getAlias(string $abstract): string
    {
        if (isset($this->bind[$abstract])) {
            $bind = $this->bind[$abstract];

            if (is_string($bind) && $abstract !== $bind) {
                return $this->getAlias($bind);
            }
        }

        return $abstract;
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return mixed Entry.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     *
     * @throws NotFoundExceptionInterface  No entry was found for **this** identifier.
     */
    public function get($id)
    {
        $id = $this->getAlias($id);

        if ($this->has($id)) {
            return $this->instances[$id];
        }

        throw new ClassNotFoundException('instances not exists: ' . $id, $id);
    }

    public function set($id, $instances)
    {
        $id = $this->getAlias($id);

        return $this->instances[$id] = $instances;
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
     * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return bool
     */
    public function has($id)
    {
        $id = $this->getAlias($id);

        return isset($this->instances[$id]);
    }

    public function delete($id)
    {
        $id = $this->getAlias($id);

        unset($this->instances[$id]);
    }

    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    public function __get($name)
    {
        return $this->get($name);
    }

    public function __isset($name): bool
    {
        return $this->has($name);
    }

    public function __unset($name)
    {
        $this->delete($name);
    }
}
