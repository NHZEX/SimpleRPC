<?php

namespace TestBootstart;

use ErrorException;
use Symfony\Component\Process\Process;
use Throwable;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
set_error_handler('\TestBootstart\handleError');
set_exception_handler('\TestBootstart\handleException');
register_shutdown_function('\TestBootstart\handleShutdown');
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
    killRpcServer();
    dump($throwable);
}
/**
 * @throws ErrorException
 */
function handleShutdown()
{
    killRpcServer();
    $lastError = error_get_last();
    if ($lastError && error_reporting() & $lastError['type']) {
        ['type' => $type, 'message' => $message, 'file' => $file, 'line' => $line] = $lastError;
        handleError($type, $message, $file, $line);
        return;
    }
}

/**
 * @var Process $servProcess
 */
$servProcess = null;

function startRpcServer()
{
    global $servProcess, $servProcessPid;
    if ($servProcess instanceof Process && $servProcess->isRunning()) {
        return;
    }
    $servProcess = new Process(['php', __DIR__ . '/Unit/start.php']);
    $servProcess->start();
    $servProcessPid = $servProcess->getPid();
}

function killRpcServer()
{
    global $servProcess, $servProcessPid;
    if (!$servProcessPid) {
        return;
    }
    posix_kill($servProcessPid, SIGKILL);
    $servProcess->stop(0.01);
    $servProcess = null;
    $servProcessPid = null;
}

function stopRpcServer($timeout = 0, $signal = null)
{
    global $servProcess, $servProcessPid;
    if (!$servProcess instanceof Process || !$servProcess->isRunning()) {
        return;
    }
    $servProcess->stop($timeout, $signal);
    $servProcess = null;
    $servProcessPid = null;
}
