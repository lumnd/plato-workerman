<?php

/**
 * The adapter as an object: settings, the guards it applies before it binds, and the calls the
 * contract makes on a process that holds no connections.
 *
 * Nothing here opens a socket or forks: that is tests/Feature/serverTest.php, which drives a real
 * listener over a real connection.
 */

use plato\exception\server_exception;
use plato\server\driver as contract;
use plato\server\server;
use plato\workerman\driver;
use Workerman\Protocols\Frame;

/**
 * A driver with one setting changed, on top of the defaults.
 *
 * @param array<string, mixed> $config
 *
 * @return driver
 */
function plato_wm_driver(array $config = []): driver
{
    $driver = new driver();
    $driver->configure($config);

    return $driver;
}

afterEach(function ()
{
    server::reset();
});

it('answers the framework driver contract', function ()
{
    expect(new driver())->toBeInstanceOf(contract::class);
});

it('merges settings over the defaults instead of replacing them', function ()
{
    $driver = plato_wm_driver(['listen' => 'text://127.0.0.1:9999']);

    expect($driver->config('listen'))->toBe('text://127.0.0.1:9999')
        ->and($driver->config('processes'))->toBe(driver::DEFAULTS['processes'])
        ->and($driver->config('name'))->toBe(driver::DEFAULTS['name']);
});

it('replaces the previous settings rather than merging onto them', function ()
{
    $driver = plato_wm_driver(['listen' => 'text://127.0.0.1:9999', 'processes' => 9]);
    $driver->configure(['listen' => 'frame://127.0.0.1:9999']);

    expect($driver->config('processes'))->toBe(driver::DEFAULTS['processes']);
});

it('keeps the settings of the entry it was handed, framework keys included', function ()
{
    $driver = plato_wm_driver(['dispatch' => ['max_payload' => 12]]);

    expect($driver->config('dispatch'))->toBe(['max_payload' => 12]);
});

it('accepts every protocol that carries message boundaries', function (string $listen)
{
    plato_wm_driver(['listen' => $listen])->verify();
})->with([
    'websocket://127.0.0.1:8282',
    'text://127.0.0.1:8282',
    'frame://127.0.0.1:8282',
])->throwsNoExceptions();

it('refuses a byte stream that nothing frames', function (string $listen)
{
    plato_wm_driver(['listen' => $listen])->verify();
})->with([
    'tcp://127.0.0.1:8282',
    'ssl://127.0.0.1:8282',
    'unix:///tmp/plato-workerman-test.sock',
])->throws(server_exception::class, 'byte stream');

it('accepts a byte stream once a protocol class frames it', function ()
{
    plato_wm_driver(['listen' => 'tcp://127.0.0.1:8282', 'protocol' => Frame::class])->verify();
})->throwsNoExceptions();

it('refuses udp, which has no connection to authenticate once', function ()
{
    plato_wm_driver(['listen' => 'udp://127.0.0.1:8282'])->verify();
})->throws(server_exception::class, 'udp://');

it('refuses http, which is a different shape of request', function ()
{
    plato_wm_driver(['listen' => 'http://127.0.0.1:8080'])->verify();
})->throws(server_exception::class, 'http://');

it('refuses the client side websocket scheme', function ()
{
    plato_wm_driver(['listen' => 'ws://127.0.0.1:8282'])->verify();
})->throws(server_exception::class, 'websocket://');

it('refuses a listen value that is not scheme://host:port', function ()
{
    plato_wm_driver(['listen' => '127.0.0.1:8282'])->verify();
})->throws(server_exception::class, 'scheme://host:port');

it('refuses a protocol class that does not exist', function ()
{
    plato_wm_driver(['listen' => 'tcp://127.0.0.1:8282', 'protocol' => 'no\\such\\protocol'])->verify();
})->throws(server_exception::class, 'does not exist');

it('refuses a class that does not answer what workerman asks a protocol', function ()
{
    plato_wm_driver(['listen' => 'tcp://127.0.0.1:8282', 'protocol' => driver::class])->verify();
})->throws(server_exception::class, 'has no input()');

it('refuses a coroutine event loop, which would serve two messages at once', function (string $loop)
{
    plato_wm_driver(['event_loop' => $loop])->verify();
})->with([
    'Workerman\\Events\\Fiber',
    'Workerman\\Events\\Swoole',
    '\\Workerman\\Events\\Swow',
])->throws(server_exception::class, 'static properties');

it('accepts the event loops that run one callback at a time', function (string $loop)
{
    plato_wm_driver(['event_loop' => $loop])->verify();
})->with([
    'Workerman\\Events\\Select',
    'Workerman\\Events\\Event',
])->throwsNoExceptions();

it('holds no connections before it has served anything', function ()
{
    $driver = plato_wm_driver();

    expect($driver->connections())->toBe([])
        ->and($driver->connection('7'))->toBeNull()
        ->and($driver->send('7', 'hello'))->toBeFalse()
        ->and($driver->close('7'))->toBeFalse();
});

it('ignores a stop that arrives before there is a loop to stop', function ()
{
    plato_wm_driver()->stop();
})->throwsNoExceptions();

it('refuses a command workerman does not have', function ()
{
    plato_wm_driver()->command('launch');
})->throws(server_exception::class, 'workerman has no "launch" command');

it('normalises a workerman 4 handshake buffer into path, query and headers', function ()
{
    // The Workerman 5 shape -- a Request object -- is covered end to end by the feature suite; this
    // is the raw buffer Workerman 4 hands over, which that suite cannot produce on a 5.x install
    $method = new ReflectionMethod(driver::class, '_handshake');
    $method->setAccessible(true);

    $buffer = "GET /chat?token=abc&room=7 HTTP/1.1\r\n"
        . "Host: 127.0.0.1:8282\r\n"
        . "Upgrade: websocket\r\n"
        . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

    expect($method->invoke(plato_wm_driver(), $buffer))->toBe([
        'path'  => '/chat',
        'query' => ['token' => 'abc', 'room' => '7'],
        'headers' => [
            'host'              => '127.0.0.1:8282',
            'upgrade'           => 'websocket',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ],
    ]);
});

it('reports an empty handshake for a protocol that has none', function ()
{
    $method = new ReflectionMethod(driver::class, '_handshake');
    $method->setAccessible(true);

    expect($method->invoke(plato_wm_driver(), ''))->toBe(['path' => '', 'query' => [], 'headers' => []]);
});

it('builds a websocket close frame the way a server has to send one', function ()
{
    $method = new ReflectionMethod(driver::class, '_close_frame');
    $method->setAccessible(true);

    $frame = $method->invoke(plato_wm_driver(), 1008, 'refused');

    expect(ord($frame[0]))->toBe(0x88)
        // Unmasked, and the length is the code plus the reason
        ->and(ord($frame[1]))->toBe(9)
        ->and(unpack('n', substr($frame, 2, 2))[1])->toBe(1008)
        ->and(substr($frame, 4))->toBe('refused');
});

it('is what the short name workerman resolves to', function ()
{
    // What bootstrap.php is for: config/server.php ships `'driver' => 'workerman'`, and without the
    // registration that name resolves to nothing at all
    server::configure([
        'default' => 'named',
        'servers' => [
            'named' => ['driver' => 'workerman', 'listen' => 'websocket://127.0.0.1:8282'],
        ],
    ]);

    expect(server::driver('named'))->toBeInstanceOf(driver::class);
});

it('is resolvable as a class name, for an application that wants no bootstrap file', function ()
{
    server::configure([
        'default' => 'classnamed',
        'servers' => [
            'classnamed' => ['driver' => driver::class, 'listen' => 'frame://127.0.0.1:8282'],
        ],
    ]);

    /** @var driver $driver */
    $driver = server::driver('classnamed');

    expect($driver)->toBeInstanceOf(driver::class)
        // Configured by the facade with the settings of its own entry
        ->and($driver->config('listen'))->toBe('frame://127.0.0.1:8282');
});
