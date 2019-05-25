<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use HZEX\SimpleRpc\Protocol\TransferFrame;
use PHPUnit\Framework\TestCase;

class TransferFrameTest extends TestCase
{
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
            [TransferFrame::OPCODE_EXECUTE, '123456', TransferFrame::FLAG_COMPRESSION | TransferFrame::FLAG_RSV1],
            [TransferFrame::OPCODE_RESULT, '123456', TransferFrame::FLAG_RSV1 | TransferFrame::FLAG_RSV5],
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
        $this->assertEquals('00000010010102039600e84b4c4a0600', bin2hex($frame->packet()));

        $unframe = TransferFrame::make(hex2bin('00000010010102039600e84b4c4a0600'));
        $this->assertEquals(1, $unframe->getFlags());
        $this->assertEquals(2, $unframe->getOpcode());
        $this->assertEquals('abc', $unframe->getBody());
    }
}
