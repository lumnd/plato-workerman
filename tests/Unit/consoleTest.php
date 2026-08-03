<?php

/**
 * The console commands as the kernel sees them: the names it registers, the help it prints, and the
 * two ways of asking for a listener this class cannot drive.
 *
 * Starting one is tests/Feature/consoleTest.php, which runs the command in a child process for the
 * same reason nothing here does: server:start does not return.
 */

use plato\console\command;
use plato\console\console as kernel;
use plato\server\server;
use plato\workerman\console;
use Tests\Fixtures\foreign;

afterEach(function ()
{
    server::reset();
    kernel::input(['plato']);
});

it('is a console command the kernel can register', function ()
{
    expect(is_a(console::class, command::class, true))->toBeTrue();
});

it('answers to the server verbs, all of them prefixed', function ()
{
    $names = console::names();

    expect(array_keys($names))->toBe([
        'server:start',
        'server:stop',
        'server:restart',
        'server:reload',
        'server:status',
        'server:connections',
    ]);

    foreach ( $names as $name => $describe )
    {
        expect($describe)->not->toBe('');
    }
});

it('documents the options each verb takes', function ()
{
    expect(console::usage('server:start'))->toContain('--server=NAME')
        ->and(console::usage('server:start'))->toContain('--daemon')
        ->and(console::usage('server:start'))->toContain('--processes=N')
        ->and(console::usage('server:stop'))->toContain('--force')
        ->and(console::usage('server:stop'))->not->toContain('--processes')
        ->and(console::usage('server:status'))->toContain('--server=NAME');
});

it('needs no path in place before the framework boots', function ()
{
    expect(console::requires())->toBe([]);
});

it('reports a listener that is not configured', function ()
{
    server::configure(['default' => 'default', 'servers' => []]);

    kernel::input(['plato', 'server:start', '--server=nowhere']);

    expect(console::handle('server:start'))->toBe(kernel::FAILURE);
});

it('refuses to drive a listener served by another adapter', function ()
{
    server::configure([
        'default' => 'other',
        'servers' => [
            'other' => ['driver' => foreign::class, 'listen' => 'websocket://127.0.0.1:8282'],
        ],
    ]);

    expect(console::handle('server:stop'))->toBe(kernel::FAILURE);
});

