<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use HZEX\SimpleRpc\Rpc;
use HZEX\SimpleRpc\RpcServerProvider;
use HZEX\SimpleRpc\Transmit\TransmitInterface;
use PHPUnit\Framework\TestCase;

class RpcCommTest extends TestCase
{
    /**
     * @throws \Exception
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
}
