<?php

/**
 * The file `plato\console\console` reads at the project root, as a host project writes it.
 *
 * tests/Fixtures plays the project root for tests/Feature/consoleTest.php, so this is where the
 * fixture application says where it lives and which commands it has registered.
 */

return [
    'app_path'  => __DIR__ . '/app',
    'data_path' => getenv('PLATO_WM_TEST_DATA') ?: sys_get_temp_dir() . '/plato-workerman-test-child',
    'env_path'  => __DIR__ . '/app/.env.testing',
    'debug'     => false,
    'env'       => 'dev',

    // The one line an application adds to get `server:start` and its siblings
    'commands' => [plato\workerman\console::class],
];
