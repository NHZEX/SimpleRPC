<?php
/** @noinspection PhpRedundantCatchClauseInspection */
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use Co;
use Exception;
use HZEX\SimpleRpc\Exception\RpcInvalidResponseException;
use HZEX\SimpleRpc\Exception\RpcRemoteExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcClient;
use HZEX\SimpleRpc\RpcProvider;
use HZEX\SimpleRpc\Tests\Unit\Logger;
use HZEX\SimpleRpc\Tests\Unit\RpcClientOpserver;
use HZEX\SimpleRpc\Tests\Unit\TestFacade;
use PHPUnit\Framework\TestCase;
use Swoole\Timer;
use function TestBootstart\killRpcServer;
use function TestBootstart\startRpcServer;

class RpcClientTest extends TestCase
{
    /**
     * @var Logger
     */
    private static $logger;
    /**
     * @var RpcClient
     */
    private $rpcClient;
    /**
     * @var RpcClientOpserver
     */
    private $opserver;
    /**
     * @var int
     */
    private $gcTime;

    public static function setUpBeforeClass(): void
    {
        self::$logger = new Logger();
    }

    public static function tearDownAfterClass(): void
    {
        killRpcServer();
    }

    public function setUp(): void
    {
        startRpcServer();

        $this->opserver = new RpcClientOpserver();
        $this->rpcClient = new RpcClient($this->opserver, self::$logger);
        $provider = new RpcProvider();
        // 等待连接成功
        $this->rpcClient->connect($provider, '127.0.0.1', 9981);
        $maxWait = 600;
        do {
            Co::sleep(0.01);
        } while (!$this->opserver->isConnect() && $maxWait--);
        $this->assertTrue($maxWait > 0);

        $this->gcTime = Timer::tick(100, function () {
            $this->rpcClient->getTerminal()->gcTransfer();
        });
    }

    public function tearDown(): void
    {
        if ($this->gcTime) {
            Timer::clear($this->gcTime);
        }
        $this->rpcClient->close();
        $this->rpcClient = null;
        self::$logger->output();
    }

    /**
     * 测试Rpc连接
     */
    public function testLink()
    {
        // 等待连接断开
        killRpcServer();
        $maxWait = 600;
        do {
            Co::sleep(0.01);
        } while ($this->opserver->isConnect() && $maxWait--);
        $this->assertTrue($maxWait > 0);
        // 等待连接重连
        startRpcServer();
        $maxWait = 600;
        do {
            Co::sleep(0.01);
        } while (!$this->opserver->isConnect() && $maxWait--);
        $this->assertTrue($maxWait > 0);
    }

    /**
     * 测试Rpc类调用
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public function testTransferClass()
    {
        $testCount = 1000;

        $count = $testCount;
        $result = 0;
        while ($count--) {
            $class = TestFacade::new();
            $result = $class->add(1, $result);
        }
        $this->assertEquals($testCount, $result);

        $count = $testCount;
        $result = 0;
        $class = TestFacade::new();
        while ($count--) {
            $result = $class->add(1, $result);
        }
        $this->assertEquals($testCount, $result);
    }

    /**
     * 测试Rpc方法调用
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public function testTransferFun()
    {
        $testCount = 1000;

        $count = $testCount;
        $result = 0;
        while ($count--) {
            $result = $this->rpcClient->getTerminal()
                ->methodCo(null, 'TestFun', 1, $result)
                ->exec();
        }
        $this->assertEquals($testCount, $result);
    }

    /**
     * 测试Rpc方法异步调用
     */
    public function testTransferAsyncFun()
    {
        $testCount = 1000;

        $count = $testCount;
        $result = 0;

        $chan = new Co\Channel(1);
        while ($count--) {
            go(function () use ($result, $chan) {
                $this->rpcClient->getTerminal()
                    ->methodAsync(null, 'TestFun', 1, $result)
                    ->then(function ($r) use ($chan) {
                        $chan->push($r);
                    })
                    ->fail(function () use ($chan) {
                        $chan->push(func_get_args());
                    })
                    ->exec();
            });
            $result = $chan->pop(1.0);
            if (!is_int($result)) {
                break;
            }
        }

        $this->assertEquals($testCount, $result);
    }

    /**
     * 测试Rpc请求超时中断是否正确
     */
    public function testTimeout()
    {
        $this->expectException(RpcRemoteExecuteException::class);
        $this->expectExceptionCode(RPC_RESPONSE_TIME_OUT);

        $this->rpcClient->getTerminal()
            ->methodCo(null, 'sleep', 3)
            ->setTimeout(1)
            ->exec();
    }

    /**
     * 测试Rpc产生异常无效请求的情况
     */
    public function testInvalidRequest()
    {
        $this->expectException(RpcInvalidResponseException::class);

        $chan = new Co\Channel(1);

        $this->rpcClient->getTerminal()->setErrorHandle(function (Exception $exception) use ($chan) {
            $chan->push($exception);
        });

        try {
            $this->rpcClient->getTerminal()
                ->methodCo(null, 'sleep', 2)
                ->setTimeout(1)
                ->exec();
        } catch (RpcRemoteExecuteException $e) {
        }

        $exception = $chan->pop(3.0);
        throw $exception;
    }
}
