<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use Exception;
use HZEX\SimpleRpc\Rpc;
use HZEX\SimpleRpc\RpcServerProvider;
use HZEX\SimpleRpc\Stub\TestsRpcFacade;
use HZEX\SimpleRpc\Transmit\Callback;
use HZEX\SimpleRpc\Transmit\TransmitInterface;
use PHPUnit\Framework\TestCase;

class RpcCommTest extends TestCase
{
    protected function tearDown(): void
    {
        RpcServerProvider::destroyInstance();
        Rpc::destroyAllInstance();
    }

    /**
     * @throws Exception
     */
    public function testRpc()
    {
        global $cSendData;
        $cSendData = '';

        $transmit = new class implements TransmitInterface {
            public function __invoke(string $data): bool
            {
                global $cSendData;
                $cSendData = $data;
                return true;
            }
        };

        $crpc = Rpc::getInstance(789);
        $crpc->setFlags(0);
        $crpc->transmit($transmit);
        $crpc->method('test', 1, 2, 3)
            ->then(function ($result) {
                $this->assertEquals('success6', $result);
            })
            ->fail(function ($code, $message, $trace) {
                // TODO 调用失败的单元测试未完成
                $this->assertIsArray([$code, $message, $trace]);
            })
            ->exec();


        RpcServerProvider::destroyInstance();
        $provider = RpcServerProvider::getInstance();
        $provider->bind('test', function ($a, $b, $c) {
            $this->assertEquals(6, $a + $b + $c);
            return 'success6';
        });
        $srpc = Rpc::getInstance(456);
        $srpc->transmit($transmit);
        $srpc->bindServerProvider(RpcServerProvider::getInstance());
        $this->assertTrue($srpc->receive($cSendData));

        $this->assertTrue($crpc->receive($cSendData));
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
        global $cSendData;
        $cSendData = '';

        $transmit = new class implements TransmitInterface {
            public function __invoke(string $data): bool
            {
                global $cSendData;
                $cSendData = $data;
                return true;
            }
        };

        $crpc = Rpc::getInstance(999);
        $crpc->setFlags(0);
        $crpc->transmit($transmit);

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
        $srpc->transmit($transmit);
        $srpc->bindServerProvider(RpcServerProvider::getInstance());
        $srpc->setFlags(0);

        // 服务的响应请求
        $this->assertTrue($srpc->receive($cSendData));

        // 客户端响应请求
        $this->assertTrue($crpc->receive($cSendData));
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
