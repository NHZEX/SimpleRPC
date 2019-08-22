<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use HZEX\SimpleRpc\Protocol\Crypto\CryptoAes;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use PHPUnit\Framework\TestCase;

class TransferFrameTest extends TestCase
{
    /**
     * @dataProvider descProvider
     * @param $op
     * @param $str
     */
    public function testDesc($op, $str)
    {
        $frame = new TransferFrame();
        $frame->setOpcode($op);
        $this->assertEquals($str, $frame->getOpcodeDesc());
    }

    public function descProvider()
    {
        return [
            [TransferFrame::OPCODE_PONG, 'PONG'],
            [TransferFrame::OPCODE_PING, 'PING'],
            [TransferFrame::OPCODE_EXECUTE, 'EXECUTE'],
            [TransferFrame::OPCODE_RESULT, 'RESULT'],
            [TransferFrame::OPCODE_FAILURE, 'FAILURE'],
            [-1, 'UNDEFINED'],
        ];
    }

    /**
     * 协议打包&解包测试
     * @dataProvider prcFrameProvider
     * @param int    $opcode
     * @param string $data
     * @param int    $flags
     */
    public function testPrcFrame(int $opcode, string $data, int $flags)
    {
        $frame = new TransferFrame();
        $frame->setFlags($flags);
        $frame->setOpcode($opcode);
        $frame->setBody($data);
        $content = $frame->packet();

        $unframe = TransferFrame::make($content);

        $this->assertEquals($opcode, $unframe->getOpcode());
        $this->assertEquals($data, $unframe->getBody());
        $this->assertEquals($flags, $unframe->getFlags());
    }

    public function prcFrameProvider()
    {
        return [
            [TransferFrame::OPCODE_EXECUTE, '123456', TransferFrame::FLAG_COMPRESSION | TransferFrame::FLAG_RSV2],
            [TransferFrame::OPCODE_RESULT, '123456', TransferFrame::FLAG_RSV2 | TransferFrame::FLAG_RSV5],
            [TransferFrame::OPCODE_FAILURE, '123456', TransferFrame::FLAG_RSV5 | TransferFrame::FLAG_RSV7],
            [TransferFrame::OPCODE_EXECUTE, '123456', TransferFrame::FLAG_COMPRESSION],
        ];
    }

    /**
     * 固定内容协议测试
     */
    public function testFixedFrame()
    {
        $frame = new TransferFrame();
        $frame->setFlags(TransferFrame::FLAG_COMPRESSION);
        $frame->setOpcode(2);
        $frame->setBody('abc');
        $this->assertEquals('00000014010102ffffffff039600e84b4c4a0600', bin2hex($frame->packet()));

        $unframe = TransferFrame::make(hex2bin('00000014010102ffffffff039600e84b4c4a0600'));
        $this->assertEquals(1, $unframe->getFlags());
        $this->assertEquals(2, $unframe->getOpcode());
        $this->assertEquals('abc', $unframe->getBody());
        $this->assertEquals(TransferFrame::WORKER_ID_NULL, $unframe->getWorkerId());
    }

    /**
     * 固定内容加密测试
     */
    public function testCryptoFrame()
    {
        $crypto = new CryptoAes();
        TransferFrame::setEncryptHandle(function ($data) use ($crypto) {
            return $crypto->encrypt($data, '123456789', '123');
        });
        TransferFrame::setDecryptHandle(function ($data) use ($crypto) {
            return $crypto->decrypt($data, '123456789', '123');
        });

        $body = '123456789';
        $frame = new TransferFrame();
        $frame->setFlags(TransferFrame::FLAG_COMPRESSION | TransferFrame::FLAG_CRYPTO);
        $frame->setOpcode(TransferFrame::OPCODE_RESULT);
        $frame->setBody($body);

        $package = $frame->packet();
        $frame2 = TransferFrame::make($package);
        $this->assertEquals($body, $frame2->getBody());

        TransferFrame::setEncryptHandle(null);
        TransferFrame::setDecryptHandle(null);
    }
}
