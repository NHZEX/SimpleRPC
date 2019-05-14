<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use Exception;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use HZEX\SimpleRpc\Exception\RpcSendDataErrorException;
use HZEX\SimpleRpc\Rpc;
use HZEX\SimpleRpc\RpcServerProvider;
use HZEX\SimpleRpc\Stub\TestsRpcFacade;
use HZEX\SimpleRpc\Stub\VirtualSend;
use HZEX\SimpleRpc\Transfer;
use HZEX\SimpleRpc\Transmit\Callback;
use PHPUnit\Framework\TestCase;

class RpcCommTest extends TestCase
{
    protected function tearDown(): void
    {
        RpcServerProvider::destroyInstance();
        Rpc::destroyAllInstance();
        VirtualSend::clear();
    }

    /**
     * @throws RpcFunctionInvokeException
     * @throws RpcSendDataErrorException
     */
    public function testRpcExec()
    {
        $mockTransmit = new VirtualSend();

        $provider = RpcServerProvider::getInstance();
        $provider->bind('testSuccess', function ($a, $b, $c) {
            $this->assertEquals(6, $a + $b + $c);
            return 'success';
        });
        $provider->bind('testFail', function () {
            throw new Exception('test', 1234);
        });
        $provider->bind('testInj', function (Rpc $rpc) {
            $this->assertEquals(789, $rpc->getId());
            return 'success';
        });

        $rpc = Rpc::getInstance(789);
        $rpc->setFlags(0);
        $rpc->transmit($mockTransmit);
        $rpc->bindServerProvider(RpcServerProvider::getInstance());

        $rpc->method('testSuccess', 1, 2, 3)
            ->then(function ($result) {
                $this->assertEquals('success', $result);
            })
            ->exec();
        $this->assertTrue($rpc->receive($mockTransmit::getData()));
        $this->assertTrue($rpc->receive($mockTransmit::getData()));

        $rpc->method('testFail')
            ->fail(function ($code, $message, $trace) {
                $this->assertEquals(1234, $code);
                $this->assertEquals('test', $message);
                $this->assertNotEmpty($trace);
            })
            ->exec();
        $this->assertTrue($rpc->receive($mockTransmit::getData()));
        $this->assertTrue($rpc->receive($mockTransmit::getData()));

        $rpc->method('testInj')
            ->then(function (Rpc $rpc, Transfer $transfer, $result) {
                $this->assertEquals('success', $result);
                $this->assertEquals(789, $rpc->getId());
                $this->assertEquals('testInj', $transfer->getMethodName());
            })
            ->exec();
        $this->assertTrue($rpc->receive($mockTransmit::getData()));
        $this->assertTrue($rpc->receive($mockTransmit::getData()));
    }

    public function testMakeRpcFacade()
    {
        $crpc = Rpc::getInstance(889);
        $this->assertEquals((new TestsRpcFacade($crpc)), TestsRpcFacade::make(889));
    }

    /**
     * @throws Exception
     */
    public function testRpcFacade()
    {
        $mockTransmit = new VirtualSend();

        $crpc = Rpc::getInstance(999);
        $crpc->setFlags(0);
        $crpc->transmit($mockTransmit);

        $f = new TestsRpcFacade($crpc);
        $f->runAutoUpdate('success', 122, true)
            ->then(function ($result) {
                $this->assertEquals('122-success-true', $result);
            })->exec();


        // 声明一个Rpc接收端
        RpcServerProvider::destroyInstance();
        $provider = RpcServerProvider::getInstance();
        $provider->bind('Tests1', new class() {
            public function runAutoUpdate($a, $b, $c)
            {
                return $b . '-' . $a . '-' . var_export($c, true);
            }
        });
        $srpc = Rpc::getInstance(533);
        $srpc->transmit($mockTransmit);
        $srpc->bindServerProvider(RpcServerProvider::getInstance());
        $srpc->setFlags(0);

        // 服务的响应请求
        $this->assertTrue($srpc->receive($mockTransmit::getData()));

        // 客户端响应请求
        $this->assertTrue($crpc->receive($mockTransmit::getData()));
    }

    public function testRpcTransmitCallback()
    {
        $transmit = new Callback(function (string $data) {
            $this->assertStringStartsWith('nrpc', $data);
            return true;
        });

        $crpc = Rpc::getInstance(999);
        $crpc->setFlags(0);
        $crpc->transmit($transmit);

        $f = new TestsRpcFacade($crpc);
        $f->runAutoUpdate('success', 122, true)
            ->then(function ($result) {
                $this->assertEquals('122-success-true', $result);
            })->exec();
    }
}
