<?php
declare(strict_types=1);

use Swoole\Coroutine\Scheduler;
use Swoole\ExitException;
use Swoole\Runtime;

Runtime::enableCoroutine(true);
$phpunitFile = __DIR__ . '/vendor/phpunit/phpunit/phpunit';
$exit_status = 1;

$scheduler = new Scheduler;
$scheduler->add(function () use (&$exit_status, $phpunitFile) {
    try {
        $codeContent = file_get_contents($phpunitFile);
        $codeContent = substr($codeContent, strpos($codeContent, '<?php'));
        file_put_contents($phpunitFile . '.copy.php', $codeContent);
        require $phpunitFile . '.copy.php';
    } catch (ExitException $e) {
        $exit_status = $e->getStatus();
    }
});
$scheduler->start();
exit($exit_status);
