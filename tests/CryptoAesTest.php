<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use HZEX\SimpleRpc\Exception\CryptoException;
use HZEX\SimpleRpc\Protocol\Crypto\CryptoAes;
use PHPUnit\Framework\TestCase;

class CryptoAesTest extends TestCase
{
    /**
     * @throws CryptoException
     */
    public function testEncrypt()
    {
        $data = '123';
        $aes = new CryptoAes();
        $endata = $aes->encrypt($data, '456', 'add');
        $result = $aes->decrypt($endata, '456', 'add');

        $this->assertEquals($data, $result);
    }
}
