<?php

use HZEX\SimpleRpc\Tests\Unit\RpcServer;

require __DIR__ . '/../../vendor/autoload.php';

(new RpcServer())->start();
