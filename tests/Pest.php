<?php

/**
 * Pest bootstrap.
 *
 * The adapter needs a framework, and the framework needs an application, so tests/Fixtures/app plays
 * that part: an app_path with the .env the suite runs against, and tests/Fixtures/application.php
 * holds the hooks and the controller both feature entry points share.
 *
 * Everything written at runtime -- Workerman's pid, status and log files included -- goes to a
 * temporary directory that is removed when the process exits, so a run leaves the repository exactly
 * as it found it.
 *
 * The socket helpers below are hand written on purpose. A client that shared its framing code with
 * the server would prove nothing about the wire, and the point of the feature suite is that a
 * browser and a plain tcp client both get what the protocol says they should.
 */

use plato\plato;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
*/

uses(Tests\TestCase::class)->in('Unit', 'Feature');

/*
|--------------------------------------------------------------------------
| Paths and ports
|--------------------------------------------------------------------------
*/

/**
 * Root of the fixture application.
 *
 * @param string $path Relative path, '' for the root itself
 *
 * @return string
 */
function plato_wm_app(string $path = ''): string
{
    $root = __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'app';

    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/');
}

/**
 * One of the fixture entry points.
 *
 * @param string $file File name under tests/Fixtures
 *
 * @return string
 */
function plato_wm_fixture(string $file): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . $file;
}

/**
 * Writable runtime directory of this process, under the system temporary directory.
 *
 * @return string
 */
function plato_wm_data(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'plato-workerman-test-' . getmypid();
}

/**
 * A free tcp port on the loopback interface.
 *
 * Bound and released, so there is a window in which something else could take it. It is the window
 * every suite that starts a server has, and it beats a hard coded port that a second checkout on the
 * same machine would fight over.
 *
 * @return int
 */
function plato_wm_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ( $socket === false )
    {
        throw new RuntimeException('cannot bind a loopback port: ' . $error);
    }

    $name = (string) stream_socket_get_name($socket, false);

    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * Remove a directory tree, guarded so it can only ever delete a test directory of this package.
 *
 * @param string $path
 *
 * @return void
 */
function plato_wm_rmdir(string $path): void
{
    $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'plato-workerman-test-';

    if ( !is_dir($path) || strpos($path, $prefix) !== 0 )
    {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $items as $item )
    {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($path);
}

/*
|--------------------------------------------------------------------------
| Listeners
|--------------------------------------------------------------------------
*/

/**
 * Start a listener as a child process, once, and leave it running until the suite exits.
 *
 * @param string             $listen  Listen address, and the key this is memoised under
 * @param array<int, string> $command Argument vector of the child, php binary first
 * @param array<string, string> $env   Environment of the child; PATH and the data directory are added
 *
 * @return void
 */
function plato_wm_boot(string $listen, array $command, array $env = []): void
{
    static $running = [];

    if ( isset($running[$listen]) )
    {
        return;
    }

    $data = plato_wm_data();

    is_dir($data) || @mkdir($data, 0777, true);

    $log     = $data . DIRECTORY_SEPARATOR . 'server-' . substr(md5($listen), 0, 8) . '.out';
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        null,
        $env + ['PATH' => (string) getenv('PATH'), 'PLATO_WM_TEST_DATA' => $data]
    );

    if ( !is_resource($process) )
    {
        throw new RuntimeException('cannot start ' . $listen);
    }

    $running[$listen] = $process;

    register_shutdown_function(function () use ($process): void
    {
        // SIGTERM is the contract: the workers finish the message in hand, then the master returns
        proc_terminate($process);

        for ( $i = 0; $i < 100 && (proc_get_status($process)['running'] ?? false); $i++ )
        {
            usleep(20000);
        }

        (proc_get_status($process)['running'] ?? false) && proc_terminate($process, SIGKILL);

        proc_close($process);
    });

    // The master binds and forks before anything is served, so readiness is a connection that
    // completes rather than a sleep long enough to feel safe
    for ( $i = 0; $i < 200; $i++ )
    {
        $client = @stream_socket_client(plato_wm_address($listen), $errno, $error, 0.2);

        if ( is_resource($client) )
        {
            fclose($client);

            return;
        }

        usleep(50000);
    }

    throw new RuntimeException(sprintf(
        '%s did not come up; the server said: %s',
        $listen,
        is_file($log) ? (string) file_get_contents($log) : '(nothing)'
    ));
}

/**
 * The tcp address behind a listen value, whatever protocol it names.
 *
 * @param string $listen
 *
 * @return string
 */
function plato_wm_address(string $listen): string
{
    return 'tcp://' . substr($listen, (int) strpos($listen, '://') + 3);
}

/**
 * A client socket on a listener, with a timeout so that a failure is a failed assertion rather than
 * a suite that hangs.
 *
 * @param string $listen
 *
 * @return resource
 */
function plato_wm_socket(string $listen)
{
    $socket = @stream_socket_client(plato_wm_address($listen), $errno, $error, 2);

    if ( !is_resource($socket) )
    {
        throw new RuntimeException('cannot reach ' . $listen . ': ' . $error);
    }

    stream_set_timeout($socket, 5);

    return $socket;
}

/**
 * Read exactly $length bytes, or fewer when the peer closed or went quiet.
 *
 * @param resource $socket
 * @param int      $length
 *
 * @return string
 */
function plato_wm_read($socket, int $length): string
{
    $data = '';

    while ( strlen($data) < $length )
    {
        $chunk = fread($socket, $length - strlen($data));

        if ( $chunk === false || $chunk === '' )
        {
            if ( feof($socket) || (stream_get_meta_data($socket)['timed_out'] ?? false) )
            {
                break;
            }

            continue;
        }

        $data .= $chunk;
    }

    return $data;
}

/*
|--------------------------------------------------------------------------
| Websocket, by hand
|--------------------------------------------------------------------------
*/

/**
 * Open a websocket connection.
 *
 * @param string $listen Listener address
 * @param string $path   Request target, query string included
 *
 * @return array{0: resource, 1: string}  The socket and the handshake response
 */
function plato_wm_ws_open(string $listen, string $path = '/chat'): array
{
    $socket = plato_wm_socket($listen);

    fwrite($socket, implode("\r\n", [
        'GET ' . $path . ' HTTP/1.1',
        'Host: ' . substr(plato_wm_address($listen), strlen('tcp://')),
        'Upgrade: websocket',
        'Connection: Upgrade',
        'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)),
        'Sec-WebSocket-Version: 13',
        'Origin: http://127.0.0.1',
        '',
        '',
    ]));

    $response = '';

    while ( strpos($response, "\r\n\r\n") === false )
    {
        $chunk = fread($socket, 1024);

        if ( $chunk === false || $chunk === '' )
        {
            break;
        }

        $response .= $chunk;
    }

    return [$socket, $response];
}

/**
 * Send one text frame, masked as a frame from a client has to be.
 *
 * @param resource             $socket
 * @param array<string, mixed> $message
 *
 * @return void
 */
function plato_wm_ws_send($socket, array $message): void
{
    $payload = (string) json_encode($message);
    $length  = strlen($payload);
    $head    = chr(0x81);

    if ( $length < 126 )
    {
        $head .= chr(0x80 | $length);
    }
    elseif ( $length < 65536 )
    {
        $head .= chr(0x80 | 126) . pack('n', $length);
    }
    else
    {
        $head .= chr(0x80 | 127) . pack('J', $length);
    }

    $mask = random_bytes(4);

    fwrite($socket, $head . $mask . ($payload ^ str_repeat($mask, (int) ceil($length / 4))));
}

/**
 * Read one frame.
 *
 * @param resource $socket
 *
 * @return array{opcode: int, payload: string}|null  Null when the peer closed instead
 */
function plato_wm_ws_recv($socket)
{
    $head = plato_wm_read($socket, 2);

    if ( strlen($head) < 2 )
    {
        return null;
    }

    $opcode = ord($head[0]) & 0x0F;
    $length = ord($head[1]) & 0x7F;

    if ( $length === 126 )
    {
        $length = (int) unpack('n', plato_wm_read($socket, 2))[1];
    }
    elseif ( $length === 127 )
    {
        $length = (int) unpack('J', plato_wm_read($socket, 8))[1];
    }

    return ['opcode' => $opcode, 'payload' => $length === 0 ? '' : plato_wm_read($socket, $length)];
}

/**
 * Read one frame and decode the message in it.
 *
 * @param resource $socket
 *
 * @return array<string, mixed>|null
 */
function plato_wm_ws_message($socket)
{
    $frame = plato_wm_ws_recv($socket);

    if ( $frame === null || $frame['opcode'] !== 0x1 )
    {
        return null;
    }

    $data = json_decode($frame['payload'], true);

    return is_array($data) ? $data : null;
}

/*
|--------------------------------------------------------------------------
| Length prefixed frames, by hand
|--------------------------------------------------------------------------
*/

/**
 * Send one message in the shape Workerman's Frame protocol reads: four bytes of total length, big
 * endian, then the payload.
 *
 * @param resource             $socket
 * @param array<string, mixed> $message
 *
 * @return void
 */
function plato_wm_frame_send($socket, array $message): void
{
    $payload = (string) json_encode($message);

    fwrite($socket, pack('N', strlen($payload) + 4) . $payload);
}

/**
 * Read one length prefixed message.
 *
 * @param resource $socket
 *
 * @return array<string, mixed>|null
 */
function plato_wm_frame_recv($socket)
{
    $head = plato_wm_read($socket, 4);

    if ( strlen($head) < 4 )
    {
        return null;
    }

    $data = json_decode(plato_wm_read($socket, (int) unpack('N', $head)[1] - 4), true);

    return is_array($data) ? $data : null;
}

/*
|--------------------------------------------------------------------------
| Framework bootstrap
|--------------------------------------------------------------------------
*/

plato::registry([
    'app_path'  => plato_wm_app(),
    'data_path' => plato_wm_data(),
    'env_path'  => plato_wm_app('.env.testing'),
    'debug'     => true,
    'env'       => 'dev',
]);

// Registered from inside a handler so that it runs after every handler registered during the run --
// log::boot() registers one of its own, and the listeners are stopped by handlers registered while
// the suite runs; none of them may write into a directory that is already gone
register_shutdown_function(function ()
{
    register_shutdown_function(function ()
    {
        plato_wm_rmdir(plato_wm_data());
    });
});
