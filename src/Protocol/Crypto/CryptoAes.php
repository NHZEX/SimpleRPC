<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Protocol\Crypto;

use HZEX\SimpleRpc\Exception\CryptoException;
use LengthException;

class CryptoAes
{
    /**
     * @var array
     */
    private const KEYLEN = [
        'aes-128' => 16,
        'aes-192' => 24,
        'aes-156' => 32,
    ];
    /**
     * @var string
     */
    private $aesMethod = 'aes-128-gcm';
    private $keyLen = 0;
    /**
     * @var int
     */
    private $ivLen = 0;
    /**
     * @var int
     */
    private $tagLen = 16;

    public function __construct()
    {
        $this->ivLen = openssl_cipher_iv_length($this->aesMethod);
        $this->keyLen = self::KEYLEN[substr($this->aesMethod, 0, 7)] ?? 0;
        if (0 === $this->keyLen) {
            throw new LengthException("Unknown cipher algorithm");
        }
    }

    /**
     * @param string $data
     * @param string $key
     * @param string $add
     * @return string
     * @throws CryptoException
     */
    public function encrypt(string $data, string $key, string $add = '')
    {
        if (empty($data)) {
            throw new CryptoException('encrypt empty data', 0);
        }

        $key = substr(hash('md5', $key, true), -$this->keyLen);

        $iv = openssl_random_pseudo_bytes($this->ivLen);
        $ciphertext = openssl_encrypt($data, $this->aesMethod, $key, OPENSSL_RAW_DATA, $iv, $tag, $add, $this->tagLen);

        if (false === $ciphertext) {
            $message = openssl_error_string() ?: 'unable to encrypt ciphertext';
            throw new CryptoException($message, 0);
        }

        return $iv . $tag . $ciphertext;
    }

    /**
     * @param string $ciphertext
     * @param string $key
     * @param string $add
     * @return string
     * @throws CryptoException
     */
    public function decrypt(string $ciphertext, string $key, string $add = '')
    {
        if (empty($ciphertext)) {
            return $ciphertext;
        }

        $minimumLength = $this->ivLen + $this->tagLen;
        if (($ciphertextLen = strlen($ciphertext)) <= $minimumLength) {
            $errMsg = "invalid ciphertext: length less than {$minimumLength} byte, curr {$ciphertextLen}";
            throw new CryptoException($errMsg, 0);
        }

        $iv = substr($ciphertext, 0, $this->ivLen);
        $tag = substr($ciphertext, $this->ivLen, $this->tagLen);
        $ciphertext = substr($ciphertext, $this->ivLen + $this->tagLen);

        $key = substr(hash('md5', $key, true), -$this->keyLen);

        $original = openssl_decrypt($ciphertext, $this->aesMethod, $key, OPENSSL_RAW_DATA, $iv, $tag, $add);

        if (false === $original) {
            $message = openssl_error_string() ?: 'unable to decrypt ciphertext';
            throw new CryptoException($message, 0);
        }

        return $original;
    }
}
