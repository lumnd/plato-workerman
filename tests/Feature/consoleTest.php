<?php

/**
 * `plato server:start` end to end, over the framework's own console kernel.
 *
 * What the other feature file drives is the adapter; what this one drives is the arrangement a host
 * project actually installs: the shipped config/server.php, the short driver name `workerman`
 * resolved by bootstrap.php, the command registered in plato.config.php, and vendor/bin/plato --
 * tests/Fixtures/console.php being the same two lines with the project root pointed at the fixture.
 */

/**
 * Address the console started listener runs on, picked once for this file.
 *
 * @return string
 */
function plato_wm_console_listen(): string
{
    static $listen = null;

    return $listen = $listen ?? 'websocket://127.0.0.1:' . plato_wm_port();
}

/**
 * The console started listener, started on the first test that needs it.
 *
 * @return array{0: resource, 1: string}
 */
function plato_wm_console_client(): array
{
    plato_wm_boot(
        plato_wm_console_listen(),
        // --processes overrides what the configuration says, for this run only
        [PHP_BINARY, plato_wm_fixture('console.php'), 'server:start', '--processes=2'],
        [
            // The framework's own config/server.php reads these; nothing about the listener is
            // declared by the fixture
            'SERVER_LISTEN'    => plato_wm_console_listen(),
            'SERVER_PROCESSES' => '1',
        ]
    );

    return plato_wm_ws_open(plato_wm_console_listen(), '/chat?token=console');
}

it('starts the listener the shipped configuration describes', function ()
{
    [$socket, $response] = plato_wm_console_client();

    expect($response)->toContain('101');

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'index', 'say' => 'from the console']);

    $reply = plato_wm_ws_message($socket);

    expect($reply['code'])->toBe(0)
        ->and($reply['said'])->toBe('from the console')
        // Started by `plato server:start`, whose argument vector Workerman would otherwise refuse
        // to recognise -- there is no `server:start` among the verbs it parses out of $argv
        ->and($reply['auth'])->toBe(['token' => 'console'])
        // SERVER_PROCESSES said one, the command line said two, and the command line ran
        ->and($reply['worker']['count'])->toBe(2);

    fclose($socket);
});

it('reports an unknown listener instead of starting something else', function ()
{
    $process = proc_open(
        [PHP_BINARY, plato_wm_fixture('console.php'), 'server:start', '--server=nowhere'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['PATH' => (string) getenv('PATH'), 'PLATO_WM_TEST_DATA' => plato_wm_data()]
    );

    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(1)
        ->and($out)->toContain('server "nowhere" is not configured');
});

it('lists its commands in the console help', function ()
{
    $process = proc_open(
        [PHP_BINARY, plato_wm_fixture('console.php'), 'help'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['PATH' => (string) getenv('PATH'), 'PLATO_WM_TEST_DATA' => plato_wm_data()]
    );

    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    proc_close($process);

    expect($out)->toContain('server:start')
        ->and($out)->toContain('server:reload');
});
