<?php

/**
 * The console entry point of the test application, run as a child process by
 * tests/Feature/consoleTest.php.
 *
 * `vendor/bin/plato` finds composer's autoloader and hands everything else to
 * `plato\console\console`. That is all this does, with tests/Fixtures as the project root, so what
 * the suite drives is the command a host project runs:
 *
 *     php tests/Fixtures/console.php server:start
 *
 * The listener is not declared here. It comes from the framework's own config/server.php -- the
 * shipped defaults, the short driver name `workerman` and all -- which is the arrangement an
 * application gets by installing this package and changing nothing.
 */

use plato\console\console;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/application.php';

/*
 * The framework reads its configuration defaults out of $_ENV, and php only fills $_ENV from the
 * process environment when variables_order says so. A host entry point is where that decision
 * belongs, so the test's dynamic port arrives here rather than in a .env file that cannot know it.
 */
foreach ( ['SERVER_LISTEN', 'SERVER_PROCESSES', 'SERVER_DRIVER', 'SERVER_NAME'] as $key )
{
    $value = getenv($key);

    if ( $value !== false && $value !== '' )
    {
        $_ENV[$key] = $value;
    }
}

exit(console::run($argv, __DIR__));
