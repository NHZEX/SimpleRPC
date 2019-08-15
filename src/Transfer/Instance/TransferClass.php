<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Transfer\Instance;

use Co;
use HZEX\SimpleRpc\Exception\RpcException;
use HZEX\SimpleRpc\Exception\RpcRemoteExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\TransferAbstract;
use LengthException;

/**
 * 调用对象
 *
 * Class TransferClass
 * @package HZEX\SimpleRpc\Transfer\Instance
 */
class TransferClass extends TransferAbstract
{
    /**
     * 对象实例Id
     * @var int
     */
    private $objectId = 0;
    /**
     * 超时限制
     * @var int
     */
    private $timeout = 60;
    /**
     * 方法启动时间
     * @var int
     */
    private $startTime = 0;

    /**
     * @param TransferClass $transfer
     * @return string
     */
    public static function pack(TransferClass $transfer): string
    {
        $pack = pack('CJJ', strlen($transfer->methodName), $transfer->objectId, $transfer->requestId);
        $pack = $pack . $transfer->methodName . $transfer->getArgvSerialize();

        return $pack;
    }

    public static function unpack(string $content)
    {
        ['len' => $nlen, 'object' => $oid, 'id' => $rid] = unpack('Clen/Jobject/Jid', $content);
        $name = substr($content, 17, $nlen);
        $argv = substr($content, 17 + $nlen);
        $argv = unserialize($argv);
        [$name, $method] = explode('$', $name);

        return [$oid, $rid, $name, $method, $argv];
    }

    /**
     * TransferMethodCo constructor.
     * @param RpcTerminal $rpc
     * @param string      $name
     * @param array       $argv
     */
    public function __construct(RpcTerminal $rpc, string $name, array $argv)
    {
        if (($namelen = strlen($name)) > 255) {
            throw new LengthException('方法名称长度超出支持范围: ' . $namelen);
        }
        $this->cid = Co::getCid();
        $this->rpc = $rpc;
        $this->methodName = $name;
        $this->methodArgv = $argv;
    }

    /**
     * @param int $objectId
     */
    public function setObjectId(int $objectId): void
    {
        $this->objectId = $objectId;
    }

    /**
     * 提交执行执行
     * @return mixed
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public function exec()
    {
        // 如果通道已经停止则不要再发送任何请求
        if ($this->rpc->getTunnel()->isStop()) {
            return null;
        }
        // 记录启动时间
        $this->startTime = time();
        // 计算停止时间
        $this->stopTime = $this->startTime + $this->timeout;
        // 发生执行请求
        $this->rpc->requestClass($this);

        // 让出控制权
        Co::yield();

        // 远程执行失败抛出异常
        if ($this->isFailure) {
            throw (new RpcRemoteExecuteException($this->result['message'], $this->result['code']))
                ->setRemoteTrace($this->result['trace']);
        }

        return $this->result;
    }

    /**
     * 设置响应参数
     * @param string $result
     * @param bool   $failure
     * @throws RpcException
     */
    public function response(string $result, bool $failure)
    {
        // 设置响应参数
        $this->resultRaw = $result;
        $this->result = unserialize($this->resultRaw);
        $this->isFailure = $failure;
        // 恢复协程
        if (Co::exists($this->cid)) {
            Co::resume($this->cid);
        } else {
            throw new RpcException(
                "无法处理响应#{$this->requestId}->{$this->objectId}, 协程#{$this->cid}不存在",
                RPC_RESPONSE_CO_RESUME_EXCEPTION
            );
        }
    }
}
