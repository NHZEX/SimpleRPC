<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use HZEX\SimpleRpc\Exception\RpcFunctionNotExistException;
use HZEX\SimpleRpc\RpcProvider;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\Stub\Tests;
use HZEX\SimpleRpc\Stub\VirtualTunnel;
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
        $provider = new RpcProvider();

        $provider->bind($name, $concrete);

        if (null === $callName) {
            $callName = $name;
        }

        $this->assertEquals('success', $provider->invoke($callName));
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
        $mockTransmit = new VirtualTunnel();
        $provider = new RpcProvider();
        $provider->bind('test', function (RpcTerminal $terminal, $test) {
            $this->assertEquals(123, $test);
            $this->assertInstanceOf(RpcTerminal::class, $terminal);
            return 'success';
        });

        $rpc = new RpcTerminal($mockTransmit, $provider);
        $provider = $rpc->getProvider();
        $result = $provider->invoke('test', [123]);
        $this->assertEquals('success', $result);
    }

    /**
     * @throws RpcFunctionInvokeException
     * @throws RpcFunctionNotExistException
     */
    public function testTransferInjection()
    {
        $mockTransmit = new VirtualTunnel();
        $provider = new RpcProvider();
        $provider->bind('test', function (RpcTerminal $terminal, $a, $b, $c) {
            $this->assertEquals(6, $a + $b + $c);
            $this->assertInstanceOf(RpcTerminal::class, $terminal);
            return 'success';
        });

        $rpc = new RpcTerminal($mockTransmit, $provider);
        $result = $rpc->getProvider()->invoke('test', [1, 2, 3]);
        $this->assertEquals('success', $result);
    }

    /**
     * @throws RpcFunctionInvokeException
     */
    public function testTransferInvoke()
    {
        $mockTransmit = new VirtualTunnel();
        $provider = new RpcProvider();
        $rpc = new RpcTerminal($mockTransmit, $provider);

        $method = $rpc
            ->method(1, 'test')
            ->then(function (RpcTerminal $terminal, Transfer $transfer, $result) {
                $this->assertEquals('test', $transfer->getMethodName());
                $this->assertInstanceOf(RpcTerminal::class, $terminal);
                $this->assertEquals('success', $result);
            })
            ->fail(function (RpcTerminal $terminal, Transfer $transfer, $code, $message, $trace) {
                $this->assertEquals('test', $transfer->getMethodName());
                $this->assertInstanceOf(RpcTerminal::class, $terminal);
                $this->assertEquals([0, 'asd', '123'], [$code, $message, $trace]);
            });

        $method->response(serialize('success'), false);
        $method->response(serialize(['code' => 0, 'message' => 'asd', 'trace' => '123']), true);
    }
}
