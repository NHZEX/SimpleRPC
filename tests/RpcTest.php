<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use Closure;
use HZEX\SimpleRpc\Exception\RpcProviderException;
use HZEX\SimpleRpc\RpcProvider;
use HZEX\SimpleRpc\RpcTerminal;
use HZEX\SimpleRpc\Stub\Tests;
use HZEX\SimpleRpc\Stub\VirtualTunnel;
use HZEX\SimpleRpc\Transfer\FunAsync\TransferFunAsync;
use PHPUnit\Framework\TestCase;

class RpcTest extends TestCase
{
    /**
     * @dataProvider serverProviderDataProvider
     * @param string      $name
     * @param             $concrete
     * @param string|null $callName
     * @throws RpcProviderException
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
     * @throws RpcProviderException
     */
    public function testTransferInvoke()
    {
        $mockTransmit = new VirtualTunnel();
        $provider = new RpcProvider();
        $rpc = new RpcTerminal($mockTransmit, $provider);

        $method = $rpc
            ->methodAsync(1, 'test')
            ->then(function ($result) {
                $this->assertEquals('success', $result);
            })
            ->fail(function ($code, $message, $trace) {
                $this->assertEquals([0, 'asd', '123'], [$code, $message, $trace]);
            });

        $method->response(serialize('success'), false);
        $method->response(serialize(['code' => 0, 'message' => 'asd', 'trace' => '123']), true);
    }

    /**
     * @throws RpcProviderException
     */
    public function testTransferMiddleware()
    {
        $mockTransmit = new VirtualTunnel();
        $provider = new RpcProvider();
        $rpc = new RpcTerminal($mockTransmit, $provider);

        $middlewareCount = 2;
        $method = $rpc
            ->methodAsync(1, 'test')
            ->middleware(function (TransferFunAsync $transfer, Closure $next) use (&$middlewareCount) {
                $this->assertEquals('success', $transfer->getResult());

                $middlewareCount--;
                return $next($transfer);
            })
            ->middleware(function (TransferFunAsync $transfer, Closure $next) use (&$middlewareCount) {
                $middlewareCount--;
                return $next($transfer);
            })
            ->then(function ($result) {
                $this->assertEquals('success', $result);
            })
            ->fail(function ($code, $message, $trace) {
                $this->assertEquals([0, 'asd', '123'], [$code, $message, $trace]);
            });

        $method->response(serialize('success'), false);
        $method->response(serialize(['code' => 0, 'message' => 'asd', 'trace' => '123']), true);

        $this->assertEquals(0, $middlewareCount);
    }
}
