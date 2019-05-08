<?php
declare(strict_types=1);

namespace app\Service;

use app\Server\Rpc\Exception\RpcFunctionInvokeException;
use app\Server\Rpc\Exception\RpcSendDataErrorException;
use app\Server\Rpc\Transmit\TransmitInterface;
use app\Service\Rpc\RpcServerProvider;
use app\Service\Rpc\Transfer;
use Closure;
use Exception;
use LengthException;

class Rpc
{
    const PRC_COMPRESSION_DATA = 1;

    /** @var string 数据包头 */
    private const HEAD = 'nrpc';
    /** @var string 数据类型 执行方法 */
    private const TYPE_EXECUTE = '01';
    /** @var string 数据类型 执行结果 */
    private const TYPE_RESULT = '02';
    /** @var string 数据类型 执行失败 */
    private const TYPE_FAILURE = '03';

    /** @var self[] 全局实例对象集 */
    private static $instances = [];
    /** @var Transfer[] 全局执行统计 */
    private static $execCount = [];
    /** @var int|string 实例ID */
    private $id;
    /** @var Closure 数据发送处理器 */
    private $sendCall;

    /** @var RpcServerProvider */
    private $provider;

    /** @var int */
    private $flags = self::PRC_COMPRESSION_DATA;

    /**
     * 获取指定RPC实例
     * @param int|string $id
     * @return self
     */
    public static function getInstance($id = 0)
    {
        if (false === isset(self::$instances[$id])) {
            self::$instances[$id] = new self();
            self::$instances[$id]->id = $id;
        }

        return self::$instances[$id];
    }

    /**
     * 是否存指定RPC实例
     * @param int|string $id
     * @return bool
     */
    public static function hasInstance($id = 0)
    {
        return isset(self::$instances[$id]);
    }

    /**
     * 销毁指定RPC实例
     * @param int|string $id
     */
    public static function destroyInstance($id = 0)
    {
        unset(self::$instances[$id]);
    }

    /**
     * 清理超时方法
     * @return int
     */
    public static function gcTransfer()
    {
        $gcTime = time();
        /** @var string[] $gcExec */
        $gcExec = [];
        foreach (self::$execCount as $key => $transfer) {
            if ($gcTime > $transfer->getExecTimeout()) {
                $gcExec[] = $transfer;
                unset(self::$execCount[$key]);
            }
        }
        return count($gcExec);
    }

    /**
     * 统计当前等待中的方法
     * @return int
     */
    public static function countTransfer()
    {
        return count(self::$execCount);
    }

    /**
     * 绑定服务提供商
     * @param RpcServerProvider $provider
     * @return $this
     */
    public function bindServerProvider(RpcServerProvider $provider)
    {
        $this->provider = $provider->cloneInstance($this);
        return $this;
    }

    /**
     * 获取实例标识
     * @return int|string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * 设置实例选项
     * @param int $option
     * @return Rpc
     */
    public function setFlags(int $option)
    {
        $this->flags = $option;
        return $this;
    }

    /**
     * 获取实例选项
     * @return int
     */
    public function getFlags()
    {
        return $this->flags;
    }

    /**
     * 获取执行方法
     * @param string $serial
     * @return Transfer
     */
    private function getExecMethod(string $serial)
    {
        return self::$execCount[$this->id . '=' . $serial];
    }

    /**
     * 添加执行方法
     * @param string   $serial
     * @param Transfer $transfer
     * @return $this
     */
    private function addExecMethod(string $serial, Transfer $transfer)
    {
        self::$execCount[$this->id . '=' . $serial] = $transfer;
        return $this;
    }

    /**
     * 移除执行方法
     * @param string $serial
     * @return self
     */
    private function delExecMethod(string $serial)
    {
        unset(self::$execCount[$this->id . '=' . $serial]);
        return $this;
    }

    /**
     * RPC包发送处理
     * @param TransmitInterface $call
     * @return self
     */
    public function transmit(TransmitInterface $call)
    {
        $this->sendCall = $call;
        return $this;
    }

    /**
     * RPC包接收处理
     * @param string $packet
     * @return bool
     * @throws Exception
     */
    public function receive(string $packet)
    {
        if ($packet[0] !== self::HEAD[0] || substr($packet, 0, 4) !== self::HEAD) {
            return false;
        }
        // 解析头部
        $packet = substr($packet, 4);
        $flags = substr($packet, 0, 4);
        $packet = substr($packet, 4);

        // 解压数据
        $hash = substr($packet, -4);
        $packet = substr($packet, 0, strlen($packet) - 4);
        if ($hash !== hash('adler32', $packet, true)) {
            return false;
        }
        // 解压数据
        if ($flags & self::PRC_COMPRESSION_DATA) {
            $packet = gzinflate($packet);
        }

        $packet_type = substr($packet, 0, 2);
        $payload = substr($packet, 2);

        // 判断包类型
        switch ($packet_type) {
            case self::TYPE_EXECUTE:
                $this->unpackExecute($payload);
                break;
            case self::TYPE_RESULT:
                $this->unpackResult($payload);
                break;
            case self::TYPE_FAILURE:
                $this->unpackResult($payload, true);
                break;
        }

        return true;
    }

    /**
     * 发送包
     * @param string $packet
     * @throws RpcSendDataErrorException
     */
    protected function sendData(string $packet)
    {
        if (false === is_callable($this->sendCall)) {
            return;
        }
        $flags = substr('0000' . dechex($this->flags), -4);
        // 数据处理
        if ($this->flags & self::PRC_COMPRESSION_DATA) {
            $packet = gzdeflate($packet);
        }
        $hash = hash('adler32', $packet, true);
        $packet = self::HEAD . $flags . $packet . $hash;
        $result = call_user_func($this->sendCall, $packet);

        if (false === $result) {
            throw new RpcSendDataErrorException('数据发送失败, len:' . strlen($packet), 0);
        }
    }

    /**
     * 执行一个远程方法
     * @param string $name
     * @param mixed  ...$argv
     * @return Transfer
     */
    public function method(string $name, ...$argv)
    {
        return new Transfer($this, $name, $argv);
    }

    /**
     * 执行调用对象
     * @param Transfer $transfer
     * @throws RpcFunctionInvokeException
     */
    public function execMethod(Transfer $transfer)
    {
        $methodName = $transfer->getMethodName();
        $namelen = strlen($methodName);
        if ($namelen > 255) {
            throw new LengthException('方法名称长度超出支持范围 ' . $namelen);
        }
        $namelen = substr('00' . dechex($namelen), -2);


        $serial = $this->generateRandomString(16);

        // 关联执行类
        $this->addExecMethod($serial, $transfer);
        // 发送包数据
        try {
            $this->sendData(self::TYPE_EXECUTE . $serial . $namelen . $methodName . $transfer->getArgvSerialize());
        } catch (RpcSendDataErrorException $e) {
            $transfer->response(serialize([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]), true);
        }
    }

    /**
     * 方法响应
     * @param string $exec_id
     * @param mixed  $result
     * @throws RpcSendDataErrorException
     */
    protected function respResult(string $exec_id, $result)
    {
        $this->sendData(self::TYPE_RESULT . $exec_id . serialize($result));
    }

    /**
     * 方法响应
     * @param string    $exec_id
     * @param Exception $e
     * @throws RpcSendDataErrorException
     */
    protected function respFailure(string $exec_id, Exception $e)
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
        $this->sendData(self::TYPE_FAILURE . $exec_id . serialize($e));
    }

    /**
     * 解请求包
     * @param string $payload
     * @return bool
     * @throws Exception
     */
    protected function unpackExecute(string $payload)
    {
        $execid = substr($payload, 0, 16);
        $nlen = hexdec(substr($payload, 16, 2));
        $name = substr($payload, 18, $nlen);
        $argv = substr($payload, 18 + $nlen);
        $argv = unserialize($argv);

        try {
            $result = $this->provider->invoke($name, $argv);
        } catch (Exception $exception) {
            // 记录错误信息
            $this->respFailure($execid, $exception);
            return false;
        }
        $this->respResult($execid, $result);
        return true;
    }

    /**
     * 解响应包
     * @param string $payload
     * @param bool   $failure
     * @throws Exception
     */
    protected function unpackResult(string $payload, bool $failure = false)
    {
        $execid = substr($payload, 0, 16);
        $result = substr($payload, 16);

        $this->getExecMethod($execid)->response($result, $failure);

        $this->delExecMethod($execid);
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
