<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Co;
use LengthException;
use RuntimeException;

final class SnowFlake
{
    /** @var int 2019-01-01 00:00:00.000 - 1546272000000 */
    protected const TWEPOCH = 1546272000000;
    // 是否已经初始化完成
    protected static $inited = false;
    // 累加器归零处理
    protected static $maxSequenceZeroRand = 256;
    // 每一部分占用的位数
    protected static $bitTwepoch = 41;  // 毫秒级时间
    protected static $bitWorkerId = 8;  // 工人ID
    protected static $bitExtension = 3; // 扩展位
    protected static $bitSequence = 11; // 毫秒累加器
    // 每一部分的最大值
    protected static $maxExtension;
    protected static $maxWorkerId;
    protected static $maxSequence;
    // 每一部分向左的位移值
    protected static $extensionLeft;
    protected static $workerIdLeft;
    protected static $timestampLeft;
    /** @var int */
    protected $workerId = 0;
    /** @var int */
    protected $extension = 0;
    /** @var int */
    protected $lastTimestamp = 0;
    /** @var int */
    protected $sequence = 0;

    /**
     * SnowFlake constructor.
     * @param int $workerId
     * @throws LengthException
     */
    public function __construct(int $workerId = 0)
    {
        if (!self::$inited) {
            self::$maxWorkerId = -1 ^ (-1 << self::$bitWorkerId);
            self::$maxExtension = -1 ^ (-1 << self::$bitExtension);
            self::$maxSequence = -1 ^ (-1 << self::$bitSequence);

            self::$timestampLeft = self::$bitWorkerId + self::$bitExtension + self::$bitSequence;
            self::$workerIdLeft = self::$bitExtension + self::$bitSequence;
            self::$extensionLeft = self::$bitSequence;

            self::$inited = true;
        }
        if (self::$maxWorkerId < $workerId || 0 > $workerId) {
            throw new LengthException('机器编号编号取值范围为：0-' . self::$maxWorkerId);
        }
        $this->workerId = $workerId;
        $this->nextId();
    }

    /**
     * 获取一个十六进制id
     * @return string
     */
    public function nextHex(): string
    {
        return dechex($this->nextId());
    }

    /**
     * 获取一个id
     * @return int
     */
    public function nextId(): int
    {
        $currStmp = $this->getCurrUnixMicroTimestamp();
        // 时间回拨，抛出异常
        if ($currStmp < $this->lastTimestamp) {
            if (null === ($currStmp = $this->waitTimeGoBack($currStmp))) {
                throw new RuntimeException("Clock moved backwards.  Refusing to generate id");
            }
        }
        if ($currStmp == $this->lastTimestamp) {
            //相同毫秒内，序列号自增
            $this->sequence = ($this->sequence + 1) & self::$maxSequence;
            //同一毫秒的序列数已经达到最大
            if ($this->sequence == 0) {
                $currStmp = $this->getNextUnixMicroTimestamp();
            }
        } else {
            //不同毫秒内，序列号重置
            $this->sequence = mt_rand(0, self::$maxSequenceZeroRand);
        }
        $this->lastTimestamp = $currStmp;
        return $currStmp << self::$timestampLeft       //时间戳部分
            | $this->extension << self::$extensionLeft //扩展部分
            | $this->workerId << self::$workerIdLeft   //工人标识部分
            | $this->sequence;                         //序列号部分
    }

    /**
     * 等待时间回拨
     * 支持3毫秒波动纠正
     * @param int $microTimestamp
     * @param int $retry
     * @return int|null
     */
    private function waitTimeGoBack(int $microTimestamp, int $retry = 3): ?int
    {
        if ($retry < 0) {
            return null;
        }
        $timeDiff = $this->lastTimestamp - $microTimestamp;
        if ($timeDiff <= 3000) {
            if (-1 === Co::getPcid()) {
                usleep($timeDiff);
            } else {
                Co::sleep(min(round($timeDiff / 1000, 2), 0.01));
            }
        }
        $microTimestamp = $this->getCurrUnixMicroTimestamp();
        if ($microTimestamp >= $this->lastTimestamp) {
            return $microTimestamp;
        }

        $this->waitTimeGoBack($microTimestamp, --$retry);

        return null;
    }

    /**
     * 获取下一个毫秒时间戳 (阻塞到下一个毫秒，直到获得新的时间戳)
     * @return int
     */
    protected function getNextUnixMicroTimestamp(): int
    {
        $numt = $this->getCurrUnixMicroTimestamp();
        while ($numt <= $this->lastTimestamp) {
            $numt = $this->getCurrUnixMicroTimestamp();
        }
        return $numt;
    }

    /**
     * 获取当前毫秒时间戳
     * @return int
     */
    protected function getCurrUnixMicroTimestamp(): int
    {
        return (int) (microtime(true) * 1000) - self::TWEPOCH;
    }
}
