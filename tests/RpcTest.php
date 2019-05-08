<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use HZEX\SimpleRpc\Exception\RpcFunctionNotExistException;
use HZEX\SimpleRpc\Rpc;
use HZEX\SimpleRpc\RpcServerProvider;
use HZEX\SimpleRpc\Stub\Tests;
use HZEX\SimpleRpc\Transfer;
use PHPUnit\Framework\TestCase;

class RpcTest extends TestCase
{
    /**
     * @dataProvider serverProviderDataProvider
     * @param string      $name
     * @param             $concrete
     * @param string|null $callName
     * @throws RpcFunctionInvokeException
     * @throws RpcFunctionNotExistException
     */
    public function testServerProvider(string $name, $concrete, ?string $callName = null)
    {
        $provider = RpcServerProvider::getInstance();

        $provider->bind($name, $concrete);

        if (null === $callName) {
            $callName = $name;
        }

        $this->assertEquals('success', $provider->invoke($callName));

        RpcServerProvider::destroyInstance();
    }

    public function serverProviderDataProvider()
    {
        return [
            ['callFun', function () {
                return 'success';
            }],
            ['callFun', Closure::fromCallable(function () {
                return 'success';
            })],
            ['callFun', 'rpc_fun_test'],
            ['callFun', Closure::fromCallable('rpc_fun_test')],
            ['callFun', [Tests::class, 'runStaticTest']],
            ['callFun', 'HZEX\SimpleRpc\Stub\Tests::runStaticTest'],
            ['callFun', [new Tests(), 'runTest']],
            ['Tests', Tests::class, 'Tests.runStaticTest'],
            ['Tests', Tests::class, 'Tests.runTest'],
            ['Tests.runTest', [new Tests(), 'runTest']],
        ];
    }

    /**
     * @throws RpcFunctionInvokeException
     * @throws RpcFunctionNotExistException
     */
    public function testInvokeInjection()
    {
        $rpc = Rpc::getInstance(789);
        RpcServerProvider::getInstance()->bind('test', function (Rpc $rpc, $test) {
            $this->assertEquals(123, $test);
            $this->assertTrue($rpc->getId() === 789);
            return 'success';
        });
        $rpc->bindServerProvider(RpcServerProvider::getInstance());
        RpcServerProvider::destroyInstance();
        $provider = $rpc->getServerProvider();
        $result = $provider->invoke('test', [123]);
        $this->assertEquals('success', $result);
        Rpc::destroyInstance($rpc->getId());
    }

    /**
     * @throws RpcFunctionInvokeException
     * @throws RpcFunctionNotExistException
     */
    public function testTransferInjection()
    {
        $rpc = Rpc::getInstance(789);
        RpcServerProvider::getInstance()
            ->bind('test', function (Rpc $rpc, $a, $b, $c) {
                $this->assertEquals(789, $rpc->getId());
                $this->assertEquals(6, $a + $b + $c);
                return 'success';
            });
        $rpc->bindServerProvider(RpcServerProvider::getInstance());
        $result = $rpc->getServerProvider()->invoke('test', [1, 2, 3]);
        $this->assertEquals('success', $result);
    }

    /**
     * @throws RpcFunctionInvokeException
     */
    public function testTransferInvoke()
    {
        $rpc = Rpc::getInstance(789);
        $rpc->bindServerProvider(RpcServerProvider::getInstance());

        $method = $rpc
            ->method('test')
            ->then(function (Rpc $rpc, Transfer $transfer, $result) {
                $this->assertEquals(789, $rpc->getId());
                $this->assertEquals('test', $transfer->getMethodName());
                $this->assertEquals(789, $transfer->getRpc()->getId());
                $this->assertEquals('success', $result);
            })
            ->fail(function (Rpc $rpc, Transfer $transfer, $code, $message, $trace) {
                $this->assertEquals(789, $rpc->getId());
                $this->assertEquals('test', $transfer->getMethodName());
                $this->assertEquals(789, $transfer->getRpc()->getId());
                $this->assertEquals([0, 'asd', '123'], [$code, $message, $trace]);
            });

        $method->response(serialize('success'), false);
        $method->response(serialize(['code' => 0, 'message' => 'asd', 'trace' => '123']), true);
    }
}
