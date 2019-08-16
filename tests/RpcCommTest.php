<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use Exception;
use HZEX\SimpleRpc\Exception\RpcInvalidResponseException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcProvider;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\SnowFlake;
use HZEX\SimpleRpc\Stub\TestsRpcFacadeFun;
use HZEX\SimpleRpc\Stub\VirtualTunnel;
use HZEX\SimpleRpc\Transfer\FunAsync\TransferFunAsync;
use PHPUnit\Framework\TestCase;

class RpcCommTest extends TestCase
{
    protected function tearDown(): void
    {
        VirtualTunnel::clear();
    }

    /**
     * @throws RpcInvalidResponseException
     * @throws RpcSendDataException
     */
    public function testRpcExec()
    {
        $mockTransmit = new VirtualTunnel();
        $count = 0;

        $provider = new RpcProvider();
        $provider->bind('testSuccess', function ($a, $b, $c) use (&$count) {
            $this->assertEquals(6, $a + $b + $c);
            $count++;
            return 'success';
        });
        $provider->bind('testFail', function () {
            throw new Exception('test', 1234);
        });
        $provider->bind('testInj', function () use (&$count) {
            $count++;
            return 'success';
        });

        $rpc = new RpcTerminal($mockTransmit, $provider);
        $rpc->setSnowFlake(new SnowFlake(1));

        $rpc->methodAsync(1, 'testSuccess', 1, 2, 3)
            ->then(function ($result) {
                $this->assertEquals('success', $result);
            })
            ->exec();
        $this->assertTrue($rpc->receive($mockTransmit::getData()));
        $this->assertTrue($rpc->receive($mockTransmit::getData()));

        $rpc->methodAsync(1, 'testFail')
            ->fail(function ($code, $message, $trace) use (&$count) {
                $this->assertEquals(1234, $code);
                $this->assertEquals('test', $message);
                $this->assertNotEmpty($trace);
                $count++;
            })
            ->exec();
        $this->assertTrue($rpc->receive($mockTransmit::getData()));
        $this->assertTrue($rpc->receive($mockTransmit::getData()));

        $rpc->methodAsync(1, 'testInj')
            ->then(function (TransferFunAsync $transfer, $result) use (&$count) {
                $this->assertEquals('success', $result);
                $this->assertEquals('testInj', $transfer->getMethodName());
                $count++;
            })
            ->exec();
        $this->assertTrue($rpc->receive($mockTransmit::getData()));
        $this->assertTrue($rpc->receive($mockTransmit::getData()));
        $this->assertEquals(4, $count);
    }

    /**
     * @throws Exception
     */
    public function testRpcFacade()
    {
        $mockTransmit = new VirtualTunnel();
        $provider = new RpcProvider();
        $crpc = new RpcTerminal($mockTransmit, $provider);
        $crpc->setSnowFlake(new SnowFlake(1));

        $f = new TestsRpcFacadeFun($crpc, 1);
        $f->runAutoUpdate('success', 122, true)
            ->then(function ($result) {
                $this->assertEquals('122-success-true', $result);
            })->exec();


        // 声明一个Rpc接收端
        $provider = new RpcProvider();
        $provider->bind('Tests1', new class() {
            public function runAutoUpdate($a, $b, $c)
            {
                return $b . '-' . $a . '-' . var_export($c, true);
            }
        });
        $srpc = new RpcTerminal($mockTransmit, $provider);
        $srpc->setSnowFlake(new SnowFlake(1));

        // 服务的响应请求
        $this->assertTrue($srpc->receive($mockTransmit::getData()));
        // 客户端响应请求验证
        $this->assertStringContainsString('122-success-true', ($mockTransmit::look()->getBody()));
        // 客户端响应请求
        $this->assertTrue($crpc->receive($mockTransmit::getData()));
    }
}
