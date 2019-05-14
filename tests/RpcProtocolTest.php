<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use HZEX\SimpleRpc\Protocol\PrcFrame;
use PHPUnit\Framework\TestCase;

class RpcProtocolTest extends TestCase
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
        $frame = new PrcFrame();
        $frame->setFlags($flags);
        $frame->setOpcode($opcode);
        $frame->setData($data);
        $content = $frame->pack();

        $unframe = PrcFrame::make($content);

        $this->assertEquals($opcode, $unframe->getOpcode());
        $this->assertEquals($data, $unframe->getData());
        $this->assertEquals($flags, $unframe->getFlags());
    }

    public function prcFrameProvider()
    {
        return [
            [PrcFrame::OPCODE_EXECUTE, '123456qwert', 0],
            [PrcFrame::OPCODE_RESULT, '123456qwert', 0],
            [PrcFrame::OPCODE_RESULT, '123456qwert', 0],
            [PrcFrame::OPCODE_EXECUTE, '123456qwert', PrcFrame::FLAG_COMPRESSION],
        ];
    }

    /**
     * 固定内容协议测试
     */
    public function testFixedFrame()
    {
        $frame = new PrcFrame();
        $frame->setFlags(1);
        $frame->setOpcode(2);
        $frame->setData('abc');
        $this->assertEquals('6e72706300000001020100000005039600e84b4c4a0600', bin2hex($frame->pack()));

        $unframe = PrcFrame::make(hex2bin('6e72706300000001020100000005039600e84b4c4a0600'));
        $this->assertEquals(1, $unframe->getFlags());
        $this->assertEquals(2, $unframe->getOpcode());
        $this->assertEquals('abc', $unframe->getData());
    }
}
