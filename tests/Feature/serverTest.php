<?php

/**
 * The adapter against a real listener, over a real socket, in real worker processes.
 *
 * tests/Fixtures/server.php is the application: it boots the framework, declares the listener, binds
 * the hooks and calls server::start(). Everything below talks to it the way a client would, with the
 * hand written handshake and frames in tests/Pest.php.
 *
 * Two listeners run side by side on purpose. What they have in common is the point of the contract:
 * the same controller, the same dispatch and the same identity on the connection, over a protocol
 * the framework knows nothing about.
 */

use Workerman\Protocols\Frame;

/**
 * Address of the websocket listener, picked once for this file.
 *
 * @return string
 */
function plato_wm_ws_listen(): string
{
    static $listen = null;

    return $listen = $listen ?? 'websocket://127.0.0.1:' . plato_wm_port();
}

/**
 * Address of the length prefixed tcp listener, picked once for this file.
 *
 * @return string
 */
function plato_wm_tcp_listen(): string
{
    static $listen = null;

    return $listen = $listen ?? 'tcp://127.0.0.1:' . plato_wm_port();
}

/**
 * The websocket listener, started on the first test that needs it.
 *
 * @param string $path Request target of the connection to open
 *
 * @return array{0: resource, 1: string}
 */
function plato_wm_ws_client(string $path = '/chat'): array
{
    plato_wm_boot(plato_wm_ws_listen(), [PHP_BINARY, plato_wm_fixture('server.php'), plato_wm_ws_listen(), '2']);

    return plato_wm_ws_open(plato_wm_ws_listen(), $path);
}

/**
 * The framed tcp listener, started on the first test that needs it.
 *
 * @return resource
 */
function plato_wm_tcp_client()
{
    plato_wm_boot(
        plato_wm_tcp_listen(),
        [PHP_BINARY, plato_wm_fixture('server.php'), plato_wm_tcp_listen(), '1', Frame::class]
    );

    return plato_wm_socket(plato_wm_tcp_listen());
}

/*
|--------------------------------------------------------------------------
| Websocket
|--------------------------------------------------------------------------
*/

it('answers a handshake and dispatches one message to a controller', function ()
{
    [$socket, $response] = plato_wm_ws_client('/chat?token=abc');

    expect($response)->toContain('101');

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'index', 'say' => 'hello', 'seq' => '7']);

    $reply = plato_wm_ws_message($socket);

    expect($reply)->toBeArray()
        ->and($reply['code'])->toBe(0)
        // req::get() reads the payload, exactly as it reads a query string over http
        ->and($reply['said'])->toBe('hello')
        // The correlation id a client with several requests in flight pairs its answers with
        ->and($reply['seq'])->toBe('7');

    fclose($socket);
});

it('establishes the identity once, at open, and dispatches every later message as it', function ()
{
    [$socket] = plato_wm_ws_client('/chat?token=abc');

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'index']);
    $first = plato_wm_ws_message($socket);

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'index']);
    $second = plato_wm_ws_message($socket);

    // Neither message carries a token: the identity is on the connection, and the dispatcher puts
    // it back into plato::$auth before every dispatch
    expect($first['auth'])->toBe(['token' => 'abc'])
        ->and($second['auth'])->toBe(['token' => 'abc'])
        // The same connection object came back, which is what makes an attribute worth setting
        ->and($second['conn']['id'])->toBe($first['conn']['id']);

    fclose($socket);
});

it('hands the handshake to the open hook, which is all a websocket client authenticates with', function ()
{
    [$socket] = plato_wm_ws_client('/chat?token=abc&room=7');

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'index']);

    $handshake = plato_wm_ws_message($socket)['conn']['handshake'];

    expect($handshake['path'])->toBe('/chat')
        ->and($handshake['query'])->toBe(['token' => 'abc', 'room' => '7'])
        ->and($handshake['headers'])->toHaveKey('sec-websocket-key')
        ->and($handshake['headers']['upgrade'])->toBe('websocket');

    fclose($socket);
});

it('registers every worker process, so worker::owns() answers truthfully', function ()
{
    [$socket] = plato_wm_ws_client();

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'index']);

    $reply = plato_wm_ws_message($socket);

    // Two processes were asked for, and this one knows which of them it is. Without the enter()
    // call it would answer -1 and 0, and every worker would believe it was alone
    expect($reply['worker']['count'])->toBe(2)
        ->and($reply['worker']['index'])->toBeGreaterThanOrEqual(0)
        ->and($reply['worker']['index'])->toBeLessThan(2)
        ->and($reply['conn']['remote'])->toBe('set');

    fclose($socket);
});

it('closes a connection an open hook refused', function ()
{
    [$socket, $response] = plato_wm_ws_client('/chat?token=no');

    expect($response)->not->toContain('101');

    fclose($socket);
});

it('answers a message hook without reaching a controller', function ()
{
    [$socket] = plato_wm_ws_client();

    plato_wm_ws_send($socket, ['ct' => 'ping']);

    expect(plato_wm_ws_message($socket))->toBe(['code' => 0, 'msg' => 'pong']);

    fclose($socket);
});

it('answers an unknown route with an error reply instead of silence', function ()
{
    [$socket] = plato_wm_ws_client();

    plato_wm_ws_send($socket, ['ct' => 'nothing', 'ac' => 'here', 'seq' => 'a1']);

    $reply = plato_wm_ws_message($socket);

    expect($reply['code'])->toBe(-2)
        ->and($reply['seq'])->toBe('a1');

    fclose($socket);
});

it('keeps the worker and the connection when an action throws', function ()
{
    [$socket] = plato_wm_ws_client();

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'boom']);

    $failure = plato_wm_ws_message($socket);

    expect($failure['code'])->toBe(-4)
        ->and($failure['msg'])->toContain('deliberate failure');

    // The connection that just failed still serves the next message, which is what the dispatcher
    // catching everything is for
    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'index', 'say' => 'still here']);

    expect(plato_wm_ws_message($socket)['said'])->toBe('still here');

    fclose($socket);
});

it('reaches a connection of this process by id, through the facade', function ()
{
    [$socket] = plato_wm_ws_client();

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'index']);

    $id = plato_wm_ws_message($socket)['conn']['id'];

    // server::send() resolves the driver through plato\runtime, which the fork emptied: the worker
    // has to have put itself back there, or this reaches nobody
    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'push', 'id' => $id]);

    expect(plato_wm_ws_message($socket))->toBe(['code' => 0, 'msg' => 'pushed'])
        ->and(plato_wm_ws_message($socket))->toBe(['code' => 0, 'sent' => true]);

    fclose($socket);
});

it('closes the connection when the action does', function ()
{
    [$socket] = plato_wm_ws_client();

    plato_wm_ws_send($socket, ['ct' => 'echo', 'ac' => 'bye']);

    // The action closed the connection, so its own reply is never written: connection::close()
    // marks the connection shut before the driver hears about it
    $frame = plato_wm_ws_recv($socket);

    expect($frame === null || $frame['opcode'] === 0x8)->toBeTrue();

    fclose($socket);
});

/*
|--------------------------------------------------------------------------
| Framed tcp
|--------------------------------------------------------------------------
*/

it('serves the same dispatch over a framed tcp listener', function ()
{
    // The protocol neutrality of the contract, demonstrated rather than claimed: a raw tcp socket,
    // framed by a Workerman protocol class named in the configuration, and the same controller
    $socket = plato_wm_tcp_client();

    plato_wm_frame_send($socket, ['ct' => 'echo', 'ac' => 'index', 'say' => 'over tcp']);

    $reply = plato_wm_frame_recv($socket);

    expect($reply['code'])->toBe(0)
        ->and($reply['said'])->toBe('over tcp')
        // No handshake to authenticate on, and the open hook said so
        ->and($reply['conn']['handshake'])->toBeNull()
        ->and($reply['auth'])->toBe(['token' => 'anonymous'])
        ->and($reply['worker'])->toBe(['index' => 0, 'count' => 1]);

    fclose($socket);
});

it('answers an error reply over a framed tcp listener too', function ()
{
    $socket = plato_wm_tcp_client();

    plato_wm_frame_send($socket, ['ct' => 'nothing', 'ac' => 'here']);

    expect(plato_wm_frame_recv($socket)['code'])->toBe(-2);

    fclose($socket);
});
