<?php
declare(strict_types=1);

use Swoole\Coroutine\Scheduler;
use Swoole\ExitException;
use Swoole\Runtime;

Runtime::enableCoroutine(true);
$exit_status = 0;

$scheduler = new Scheduler;
$scheduler->add(function () use (&$exit_status) {
    try {
        $file = __DIR__ . '/vendor/phpunit/phpunit/phpunit';
        $codeContent = file_get_contents($file);
        $codeContent = substr($codeContent, strpos($codeContent, '<?php'));
        file_put_contents($file . '.copy', $codeContent);
        require $file . '.copy';
    } catch (ExitException $e) {
        $exit_status = $e->getStatus();
        return;
    }
});
$scheduler->start();

exit($exit_status);
