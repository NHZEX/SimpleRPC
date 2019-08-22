<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Transfer\Fun;

use Co;
use HZEX\SimpleRpc\Exception\RpcException;
use HZEX\SimpleRpc\Exception\RpcRemoteExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\TransferAbstract;
use LengthException;

class TransferFun extends TransferAbstract
{
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
     * 提交执行执行
     * @return mixed
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public function exec()
    {
        // 记录启动时间
        $this->execTime = time();
        // 计算停止时间
        $this->stopTime = $this->execTime + $this->timeout;
        // 发送执行请求
        $this->rpc->request($this);

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
        if (Co::exists($this->cid) && $this->isTargetResume()) {
            Co::resume($this->cid);
        } else {
            throw new RpcException(
                "响应#{$this->requestId}无法被调度, 协程#{$this->cid}不存在",
                RPC_RESPONSE_CO_RESUME_EXCEPTION
            );
        }
    }
}
