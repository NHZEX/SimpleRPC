<?php

use HZEX\SimpleRpc\Tests\Unit\RpcServer;

require __DIR__ . '/../../vendor/autoload.php';

error_reporting(E_ALL);
set_error_handler('\handleError');
set_exception_handler('\handleException');

/**
 * @param int    $errno
 * @param string $errstr
 * @param string $errfile
 * @param int    $errline
 * @throws ErrorException
 */
function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0)
{
    throw new ErrorException($errstr, $errno, 1, $errfile, $errline);
}
/**
 * @param Throwable $throwable
 */
function handleException(Throwable $throwable)
{
    dump($throwable);
}

(new RpcServer())->start();
