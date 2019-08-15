<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use Co;
use HZEX\SimpleRpc\Exception\RpcRemoteExecuteException;
use HZEX\SimpleRpc\Exception\RpcSendDataException;
use HZEX\SimpleRpc\RpcClient;
use HZEX\SimpleRpc\RpcProvider;
use HZEX\SimpleRpc\Tests\Unit\Logger;
use HZEX\SimpleRpc\Tests\Unit\RpcClientOpserver;
use HZEX\SimpleRpc\Tests\Unit\TestFacade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RpcClientTest extends TestCase
{
    /**
     * @var Process
     */
    private static $servProcess;
    /**
     * @var Logger
     */
    private static $logger;
    /**
     * @var RpcClient
     */
    private static $rpcClient;

    public static function startRpcServer()
    {
        self::$servProcess = new Process(['php', __DIR__ . '/Unit/start.php']);
        self::$servProcess->start();
    }

    public static function killRpcServer()
    {
        self::$servProcess->signal(SIGKILL);
        self::$servProcess->stop();
    }

    public static function setUpBeforeClass(): void
    {
        self::startRpcServer();
        self::$logger = new Logger();
    }

    public static function tearDownAfterClass(): void
    {
        self::$rpcClient->close();
        self::$rpcClient = null;
        self::$servProcess->stop(0);
        self::$logger->output();
    }

    /**
     * 测试Rpc连接
     */
    public function testLink()
    {
        $opserver = new RpcClientOpserver();
        self::$rpcClient = new RpcClient($opserver, self::$logger);
        $provider = new RpcProvider();
        // 等待连接成功
        self::$rpcClient->connect($provider, '127.0.0.1', 9981);
        $maxWait = 600;
        do {
            Co::sleep(0.01);
        } while (!$opserver->isConnect() && $maxWait--);
        $this->assertTrue($maxWait > 0);
        // 等待连接断开
        self::killRpcServer();
        $maxWait = 600;
        do {
            Co::sleep(0.01);
        } while ($opserver->isConnect() && $maxWait--);
        $this->assertTrue($maxWait > 0);
        // 等待连接重连
        self::startRpcServer();
        $maxWait = 600;
        do {
            Co::sleep(0.01);
        } while (!$opserver->isConnect() && $maxWait--);
        $this->assertTrue($maxWait > 0);
        // 关闭客户端
        self::$rpcClient->close();
    }

    /**
     * 测试Rpc方法调用
     * @throws RpcRemoteExecuteException
     * @throws RpcSendDataException
     */
    public function testTransfer()
    {
        $opserver = new RpcClientOpserver();
        self::$rpcClient = new RpcClient($opserver, self::$logger);
        $provider = new RpcProvider();
        // 等待连接成功
        self::$rpcClient->connect($provider, '127.0.0.1', 9981);
        $maxWait = 600;
        do {
            Co::sleep(0.01);
        } while (!$opserver->isConnect() && $maxWait--);
        $this->assertTrue($maxWait > 0);

        $class = TestFacade::new(1);
        $result = $class->add(1, 2);
        $this->assertEquals(3, $result);
    }
}
