<?php

/**
 * Entry point of the test application, run as a child process by tests/Feature/serverTest.php.
 *
 * It plays the part a host project's own start script plays: boot the framework, declare a
 * listener, bind the hooks an application binds, and hand the process to the adapter.
 *
 *     php tests/Fixtures/server.php <listen> [processes] [protocol]
 */

use plato\plato;
use plato\server\server;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$listen    = $argv[1] ?? 'websocket://127.0.0.1:8282';
$processes = (int) ($argv[2] ?? 2);
$protocol  = $argv[3] ?? '';

plato::registry([
    'app_path'  => __DIR__ . '/app',
    // Given by the test so it can clean up after the server; the fallback only matters when this
    // file is run by hand
    'data_path' => getenv('PLATO_WM_TEST_DATA') ?: sys_get_temp_dir() . '/plato-workerman-test-child',
    'env_path'  => __DIR__ . '/app/.env.testing',
    'debug'     => false,
    'env'       => 'dev',
]);

server::configure([
    'default' => 'test',
    'servers' => [
        'test' => [
            'driver'    => 'workerman',
            'listen'    => $listen,
            'protocol'  => $protocol,
            // Per listener, because the name is what the pid, status and log files are called and
            // the suite runs more than one server at a time
            'name'      => 'plato-wm-' . substr(md5($listen), 0, 8),
            'processes' => $processes,
            'dispatch'  => [
                // The suite asserts on the text of a failure, so it has to be in the reply
                'error_detail' => true,
            ],
        ],
    ],
]);

require_once __DIR__ . '/application.php';

server::start();
