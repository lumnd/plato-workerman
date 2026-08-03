<?php

/**
 * Workerman adapter: the event loop, the protocol and the worker processes plato\server does not ship
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\workerman;

use plato\exception\server_exception;
use plato\log;
use plato\plato;
use plato\runtime;
use plato\server\connection;
use plato\server\dispatcher;
use plato\server\driver as contract;
use plato\server\server;
use plato\worker as process;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\ProtocolInterface;
use Workerman\Timer;
use Workerman\Worker;

/**
 * One configured listener, served by Workerman.
 *
 * `plato\server` ships a contract, a connection object and a dispatcher, and no loop: a resident
 * socket server is an event loop, a process manager and a protocol codec, and none of the three is
 * a framework's business. This class is the other half. It owns the listening socket, the protocol,
 * the worker processes and the keepalive timer, and it hands every complete message it receives to
 * `dispatcher::handle()`, writing back whatever comes out.
 *
 * Nothing above `plato\server\driver` knows it is Workerman, and nothing here knows what a `ct` is.
 *
 * **Framing is the hard rule.** `dispatcher::handle()` takes one whole application message, so a
 * listener has to speak a protocol that has message boundaries. `websocket://`, `text://` and
 * `frame://` do; `tcp://` does not, and this adapter refuses it unless the `protocol` setting names
 * a Workerman protocol class that supplies the framing. `udp://` is refused outright -- a datagram
 * has no connection to carry an identity on -- and so is `http://`, which is a different kind of
 * server and not this one.
 *
 * **Coroutines stay off.** The framework's concurrency contract is one message at a time per
 * process: request state lives in static properties, and a scheduler that puts two dispatches inside
 * one pid corrupts them. Workerman 4 has no coroutine loop; Workerman 5 has three, and this adapter
 * refuses to start when one of them is configured.
 *
 * **What it does at the fork**, in `onWorkerStart` of every worker process, in this order:
 *
 *   runtime::forked()    adopt the child identity, so the connections the master built are dropped
 *                        rather than written into from two processes at once
 *   process::enter()     say which worker this is, which is what makes `worker::owns()` answer
 *                        truthfully -- an adapter that skips it leaves every worker believing it is
 *                        alone, and a timer meant for one of them runs in all of them
 *   re-share             put this instance back under the key `server::driver()` looks in, because
 *                        the fork emptied the registry and a rebuilt driver would hold no
 *                        connections at all
 *
 * The listener name that last step needs is not in the settings -- `configure()` is handed one entry
 * of `servers` without its key -- so it is found by matching the `listen` value, which two listeners
 * of one application cannot share anyway.
 *
 * **It rewrites `$argv` around `Worker::runAll()`**, and there is no way around it: Workerman parses
 * the command line itself and exits with its own usage text unless it finds `start`, `stop`,
 * `reload`, `restart`, `status` or `connections` there. The console command in this package is
 * called `server:start`, so the argument vector is replaced for the duration of the call and put
 * back afterwards.
 */
class driver implements contract
{
    /**
     * Connection attribute holding the websocket handshake, see _handshake().
     *
     * The open hook is where an application authenticates a client, and on a websocket everything
     * it has to go on -- a token in the query string, a cookie, an Origin -- is in the handshake and
     * nowhere else, because the frames that follow carry no headers.
     */
    public const HANDSHAKE = 'handshake';

    /**
     * Verbs Workerman answers to, see command().
     */
    public const COMMANDS = ['start', 'stop', 'restart', 'reload', 'status', 'connections'];

    /**
     * Settings this adapter reads, and their defaults.
     *
     * Everything the framework does not own in `config/server.php` arrives here untouched, so this
     * is the whole vocabulary of a listener served by Workerman.
     */
    public const DEFAULTS = [
        // Where to listen, and in which protocol. See the class docblock for which schemes carry
        // message boundaries and which do not
        'listen' => 'websocket://127.0.0.1:8282',

        // Name the process carries in `ps`, and the stem of the pid, status and log file names
        'name' => 'platophp-server',

        // Worker processes. One process serves one message at a time
        'processes' => 4,

        // Drop privileges after binding
        'user'  => '',
        'group' => '',

        // Under data_path()/server and log_path() when left empty, so a host application needs no
        // extra writable directory
        'pid_file'    => '',
        'status_file' => '',
        'log_file'    => '',
        'stdout_file' => '',

        // Detach from the terminal. The console command's --daemon sets it for one run
        'daemonize' => false,

        // Let stop, restart and reload wait for the workers to finish what they are doing
        'graceful' => true,

        // SO_REUSEPORT, so a restart does not drop the listening socket
        'reuse_port' => false,

        // Workerman protocol class supplying the framing of a raw `tcp://`, `ssl://` or `unix://`
        // listener. Ignored by a scheme that already names a protocol
        'protocol' => '',

        // Largest packet Workerman will assemble off the socket, in bytes; 0 keeps its own default
        // of 10 MB. This is the limit on what the process reads, where `dispatch.max_payload` is
        // the limit on what the dispatcher accepts -- set both
        'max_package_size' => 0,

        // Seconds a worker gets to finish after a graceful stop before it is killed; 0 keeps
        // Workerman's default of 2
        'stop_timeout' => 0,

        // Terminating tls here instead of at a proxy. Paths, never key material
        'ssl' => [],

        // Extra stream context for the listening socket, merged under the `ssl` key above
        'context' => [],

        // Idle connections: `interval` is how often to look, `timeout` is how long a connection may
        // say nothing before it is closed. Either at 0 turns the sweep off
        'heartbeat' => [],

        // Workerman event loop class, empty for the one it picks itself. A coroutine loop is
        // refused, see the class docblock
        'event_loop' => '',

        // fn(int $index, int $count): void, run in each worker process once it knows which one it
        // is. Where a per worker timer belongs, since a controller only ever sees a message
        'on_worker_start' => null,
        'on_worker_stop'  => null,
    ];

    /**
     * Schemes that hand over whole messages without help.
     */
    private const FRAMED = ['websocket', 'text', 'frame'];

    /**
     * Schemes that are a raw byte stream until a protocol class says otherwise.
     */
    private const RAW = ['tcp', 'ssl', 'unix'];

    /**
     * Workerman event loops that run several callbacks at once, which the framework cannot serve.
     */
    private const COROUTINE_LOOPS = ['Fiber', 'Swoole', 'Swow'];

    /**
     * Active settings, DEFAULTS until configure() replaces them.
     *
     * @var array<string, mixed>
     */
    private $_config = self::DEFAULTS;

    /**
     * The Workerman worker, null until the first start() or command() builds it.
     *
     * @var Worker|null
     */
    private $_worker = null;

    /**
     * Connections this process holds, id => the object the application sees.
     *
     * A Workerman connection id is a number, and php turns a decimal string key back into an int on
     * the way into an array -- so the key type here is whatever php made of it, and the ids leaving
     * this class through connections() are cast back to the strings the contract promises.
     *
     * @var array<int|string, connection>
     */
    private $_conns = [];

    /**
     * The same connections, id => Workerman's own object. Kept apart so that nothing outside this
     * class can reach a TcpConnection and write a controller that only runs under Workerman.
     *
     * @var array<int|string, TcpConnection>
     */
    private $_sockets = [];

    /**
     * When each connection last said something, id => float unix time. Only the heartbeat sweep
     * reads it, and it is private rather than a connection attribute because it is bookkeeping of
     * the transport, which the application has no business seeing.
     *
     * @var array<int|string, float>
     */
    private $_seen = [];

    /**
     * Whether the listener speaks websocket, which decides when a connection is opened and how it
     * is closed. Derived from `listen` by configure().
     *
     * @var bool
     */
    private $_websocket = false;

    /**
     * Whether runAll() is running, so that stop() knows there is a loop to stop.
     *
     * @var bool
     */
    private $_running = false;

    /**
     * Hand the driver its settings.
     *
     * Cheap, repeatable, and it binds nothing: the adapter may be configured in a php-fpm request
     * that will never start a server, and a listening socket in there would be a bug rather than an
     * optimisation. The whole entry of `config/server.php` arrives, framework keys included, and
     * what this class does not know about it ignores.
     *
     * @param array<string, mixed> $config One entry of the `servers` array
     *
     * @return void
     */
    public function configure(array $config): void
    {
        $this->_config = $config + self::DEFAULTS;

        // Derived state, dropped and rebuilt with the settings that produced it
        $this->_worker    = null;
        $this->_websocket = $this->_scheme() === 'websocket';
    }

    /**
     * The active settings.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public function config(?string $key = null)
    {
        return $key === null ? $this->_config : ($this->_config[$key] ?? null);
    }

    /**
     * Check that this listener can be served as configured, without binding anything.
     *
     * start() calls it before it builds anything. It is public because "would this configuration
     * come up" is a question worth being able to ask from a deployment check, and because finding
     * out should not require opening a socket.
     *
     * @return void
     * @throws server_exception When the protocol cannot deliver whole messages, or the event loop
     *                          cannot serve one message at a time
     */
    public function verify(): void
    {
        $this->_check_listen((string) $this->_config['listen']);
        $this->_check_loop();

        $protocol = (string) $this->_config['protocol'];

        if ( $protocol !== '' )
        {
            $this->_check_protocol($protocol);
        }
    }

    /**
     * Bind the listening socket, fork the workers and run the loop.
     *
     * Returns when a signal or stop() ends the loop, which is why the console command calls it last
     * and nothing else in the framework calls it at all.
     *
     * @return void
     * @throws server_exception When the listener cannot be served as configured
     */
    public function start(): void
    {
        // Before the flush below, so that a listener that cannot come up at all does not take the
        // caller's connections with it on the way to saying so
        $this->verify();

        // Nothing this process opened belongs in the children Workerman is about to fork: two
        // processes writing into one connection interleave, and a child that inherits a handle
        // holds its parent's descriptor open for its whole life. This is what plato\pool does
        // before its own first fork, for the same reason
        runtime::flush();

        $this->command('start');
    }

    /**
     * Stop the loop and let start() return.
     *
     * A stop that arrives before the loop is running is not an error and does nothing: the runtime
     * registry closes what it holds when a test or a bootstrap failure flushes it, and stopping a
     * server that was never started would take down the one running in this process instead.
     *
     * @return void
     */
    public function stop(): void
    {
        if ( !$this->_running )
        {
            return;
        }

        $this->_running = false;

        Worker::stopAll();
    }

    /**
     * Run one of Workerman's own verbs against this listener.
     *
     * Only `start` and `restart` return: Workerman answers `stop`, `reload`, `status` and
     * `connections` inside its command parser and ends the process there, so a caller cannot rely
     * on getting control back.
     *
     * @param string $verb One of COMMANDS
     *
     * @return void
     * @throws server_exception When the verb or the listener configuration is not serviceable
     */
    public function command(string $verb): void
    {
        if ( !in_array($verb, self::COMMANDS, true) )
        {
            throw new server_exception(sprintf(
                'workerman has no "%s" command; it is one of %s',
                $verb,
                implode(', ', self::COMMANDS)
            ));
        }

        $this->_build();

        $argv = isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : [];

        // See the class docblock: Workerman reads the command line rather than an argument
        $replaced = [$argv[0] ?? 'plato', $verb];

        if ( $this->_config['graceful'] && in_array($verb, ['stop', 'restart', 'reload'], true) )
        {
            $replaced[] = '-g';
        }

        $GLOBALS['argv'] = $replaced;
        $this->_running  = in_array($verb, ['start', 'restart'], true);

        try
        {
            Worker::runAll();
        }
        finally
        {
            $GLOBALS['argv'] = $argv;
            $this->_running  = false;
        }
    }

    /**
     * Send a payload on one connection of this process.
     *
     * @param string $id      Connection id
     * @param string $payload Encoded message
     *
     * @return bool  False when this process does not hold the connection, or the write failed
     */
    public function send(string $id, string $payload): bool
    {
        $socket = $this->_sockets[$id] ?? null;

        if ( $socket === null )
        {
            return false;
        }

        // Workerman answers null when it buffered the payload because the socket was not writable
        // yet, which is a send that has not failed
        return $socket->send($payload) !== false;
    }

    /**
     * Close one connection of this process.
     *
     * On a websocket the code and the reason go out as a close frame, which is what a browser
     * reports to `onclose`. On any other protocol both are dropped: a framed tcp listener has no
     * place to put them, and inventing one would be a message the client cannot read.
     *
     * @param string $id     Connection id
     * @param int    $code   Websocket close code: 1000 normal, 1008 policy violation, 1011 internal
     * @param string $reason Short text
     *
     * @return bool  False when this process does not hold the connection
     */
    public function close(string $id, int $code = 1000, string $reason = ''): bool
    {
        $socket = $this->_sockets[$id] ?? null;

        if ( $socket === null )
        {
            return false;
        }

        if ( $this->_websocket )
        {
            // Raw, because the close frame is not a message the websocket codec should encode
            $socket->close($this->_close_frame($code, $reason), true);
        }
        else
        {
            $socket->close();
        }

        return true;
    }

    /**
     * The connection registered under $id, or null when this process does not hold it.
     *
     * @param string $id Connection id
     *
     * @return connection|null
     */
    public function connection(string $id)
    {
        return $this->_conns[$id] ?? null;
    }

    /**
     * Ids of the connections this process holds, and never those of the whole server.
     *
     * @return array<int, string>
     */
    public function connections(): array
    {
        return array_map('strval', array_keys($this->_conns));
    }

    /**
     * Build the Workerman worker and hang the callbacks on it.
     *
     * @return Worker
     * @throws server_exception When the listener cannot be served as configured
     */
    private function _build(): Worker
    {
        // A Worker registers itself with Workerman when it is constructed, so building a second one
        // for the same listener would bind the address twice
        if ( $this->_worker !== null )
        {
            return $this->_worker;
        }

        $this->verify();

        $listen  = (string) $this->_config['listen'];
        $context = (array) $this->_config['context'];
        $ssl     = $this->_ssl();

        if ( $ssl !== [] )
        {
            $context['ssl'] = $ssl + ($context['ssl'] ?? []);
        }

        $worker = new Worker($listen, $context);

        if ( isset($context['ssl']) )
        {
            // The scheme says which protocol is spoken, the transport says whether it is wrapped in
            // tls; `websocket://` plus this is wss
            $worker->transport = 'ssl';
        }

        $protocol = (string) $this->_config['protocol'];

        if ( $protocol !== '' )
        {
            $worker->protocol = $this->_check_protocol($protocol);
        }

        $worker->name       = (string) $this->_config['name'];
        $worker->count      = max(1, (int) $this->_config['processes']);
        $worker->user       = (string) $this->_config['user'];
        $worker->group      = (string) $this->_config['group'];
        $worker->reusePort  = (bool) $this->_config['reuse_port'];

        $this->_apply_paths();

        Worker::$daemonize = (bool) $this->_config['daemonize'];

        $loop = (string) $this->_config['event_loop'];

        if ( $loop !== '' )
        {
            Worker::$eventLoopClass = $loop;
        }

        if ( (int) $this->_config['stop_timeout'] > 0 )
        {
            Worker::$stopTimeout = (int) $this->_config['stop_timeout'];
        }

        if ( (int) $this->_config['max_package_size'] > 0 )
        {
            TcpConnection::$defaultMaxPackageSize = (int) $this->_config['max_package_size'];
        }

        $worker->onWorkerStart = function (Worker $worker): void
        {
            $this->_worker_start($worker);
        };

        $worker->onWorkerStop = function (Worker $worker): void
        {
            $this->_worker_stop($worker);
        };

        $worker->onConnect = function (TcpConnection $socket): void
        {
            $this->_accept($socket);
        };

        $worker->onMessage = function (TcpConnection $socket, $payload): void
        {
            $this->_message($socket, $payload);
        };

        $worker->onClose = function (TcpConnection $socket): void
        {
            $this->_disconnect($socket);
        };

        $worker->onError = function (TcpConnection $socket, $code, $message): void
        {
            log::error(sprintf('workerman connection %s: [%s] %s', $socket->id, (string) $code, (string) $message));
        };

        return $this->_worker = $worker;
    }

    /**
     * Enter a worker process, in the child and before it serves anything.
     *
     * The `on_worker_start` hook is deliberately not wrapped: a worker that could not set itself up
     * should not go on to serve messages, and Workerman answers an exception here by stopping the
     * server -- which is louder, and better, than a listener that is up and half configured.
     *
     * @param Worker $worker
     *
     * @return void
     */
    private function _worker_start(Worker $worker): void
    {
        // Order matters, see the class docblock
        runtime::forked();
        process::enter((int) $worker->id, (int) $worker->count);
        $this->_reshare();
        $this->_heartbeat();

        $hook = $this->_config['on_worker_start'];

        if ( is_callable($hook) )
        {
            $hook((int) $worker->id, (int) $worker->count);
        }
    }

    /**
     * Leave a worker process.
     *
     * @param Worker $worker
     *
     * @return void
     */
    private function _worker_stop(Worker $worker): void
    {
        $hook = $this->_config['on_worker_stop'];

        if ( is_callable($hook) )
        {
            try
            {
                $hook((int) $worker->id, (int) $worker->count);
            }
            catch ( Throwable $e )
            {
                // The process is on its way out; the log is the only place left to say so
                log::error('workerman on_worker_stop: ' . $e->getMessage());
            }
        }
    }

    /**
     * Put this instance back where server::driver() looks for it.
     *
     * The fork emptied the runtime registry, so the next `server::send()` in an action would build
     * a second driver -- correctly configured, holding no connections, and quietly unable to reach
     * the client that is on the other end of this very process. No closer is registered: a flush in
     * a worker must not stop the loop the worker is inside.
     *
     * @return void
     */
    private function _reshare(): void
    {
        $name = $this->_listener();

        if ( $name === '' )
        {
            return;
        }

        runtime::share('server.' . $name, function (): contract
        {
            return $this;
        });
    }

    /**
     * The key this listener is configured under, or '' when it is not in the configuration.
     *
     * `configure()` is handed one entry of `servers` without its key, so the entry is found again by
     * the one setting that cannot repeat: two listeners of one application cannot both bind the same
     * address.
     *
     * @return string
     */
    private function _listener(): string
    {
        $listen = (string) $this->_config['listen'];

        try
        {
            $servers = (array) server::config('servers');
        }
        catch ( Throwable )
        {
            return '';
        }

        foreach ( $servers as $name => $settings )
        {
            if ( is_array($settings) && ($settings['listen'] ?? null) === $listen )
            {
                return (string) $name;
            }
        }

        return '';
    }

    /**
     * Start the idle connection sweep of this worker.
     *
     * @return void
     */
    private function _heartbeat(): void
    {
        $heartbeat = (array) $this->_config['heartbeat'];
        $interval  = (int) ($heartbeat['interval'] ?? 0);
        $timeout   = (int) ($heartbeat['timeout'] ?? 0);

        if ( $interval < 1 || $timeout < 1 )
        {
            return;
        }

        Timer::add($interval, function () use ($timeout): void
        {
            $deadline = microtime(true) - $timeout;

            foreach ( $this->_seen as $id => $at )
            {
                if ( $at < $deadline )
                {
                    // 1001 going away: the client did nothing wrong, the connection went quiet
                    $this->close((string) $id, 1001, 'idle timeout');
                }
            }
        });
    }

    /**
     * Take a new socket.
     *
     * On a websocket this is the tcp connect, before the handshake: the headers an open hook
     * authenticates on have not arrived yet, so the connection is opened from the handshake callback
     * instead. On every other protocol there is nothing else coming and it is opened here.
     *
     * @param TcpConnection $socket
     *
     * @return void
     */
    private function _accept(TcpConnection $socket): void
    {
        if ( !$this->_websocket )
        {
            $this->_open($socket, []);

            return;
        }

        $socket->onWebSocketConnect = function (TcpConnection $socket, $handshake): void
        {
            $this->_open($socket, $this->_handshake($handshake));
        };
    }

    /**
     * Build the connection object and offer it to the application.
     *
     * Never throws: Workerman answers an exception in a callback by stopping the whole worker, and
     * one client's bad handshake is not a reason to drop everybody else's connection.
     *
     * @param TcpConnection        $socket
     * @param array<string, mixed> $handshake Websocket handshake, empty on any other protocol
     *
     * @return connection|null  Null when the connection was refused or could not be set up
     */
    private function _open(TcpConnection $socket, array $handshake)
    {
        $id = (string) $socket->id;

        try
        {
            $conn = new connection($id, $this, (string) $socket->getRemoteAddress());

            if ( $handshake !== [] )
            {
                $conn->set(self::HANDSHAKE, $handshake);
            }

            $this->_conns[$id]   = $conn;
            $this->_sockets[$id] = $socket;
            $this->_seen[$id]    = microtime(true);

            if ( !dispatcher::open($conn) )
            {
                // Dropped from the map before the socket goes, so that the close hooks do not run
                // for a client the application never accepted. The socket is closed plainly rather
                // than with a close frame: on a websocket this is still the handshake, and the
                // client is waiting for an http response, not for a frame it cannot parse yet
                unset($this->_conns[$id], $this->_sockets[$id], $this->_seen[$id]);

                $conn->mark_closed();
                $socket->close();

                return null;
            }

            return $conn;
        }
        catch ( Throwable $e )
        {
            log::error('workerman open on ' . $id . ': ' . $e->getMessage());

            $socket->close();

            return null;
        }
    }

    /**
     * Serve one message.
     *
     * @param TcpConnection $socket
     * @param mixed         $payload What the protocol assembled, a string for every protocol this
     *                               adapter allows
     *
     * @return void
     */
    private function _message(TcpConnection $socket, $payload): void
    {
        $id   = (string) $socket->id;
        $conn = $this->_conns[$id] ?? null;

        // A message before the connection was opened means the handshake callback refused it and
        // the close is still on its way out
        if ( $conn === null )
        {
            return;
        }

        $this->_seen[$id] = microtime(true);

        if ( !is_string($payload) )
        {
            log::error(sprintf(
                'workerman protocol %s handed the dispatcher a %s; a protocol class has to produce'
                    . ' a string payload',
                is_object($socket->worker) ? (string) $socket->worker->protocol : '-',
                gettype($payload)
            ));

            return;
        }

        try
        {
            // Never throws by contract: a failed message answers the client with an error reply
            // rather than taking the worker down
            $reply = dispatcher::handle($conn, $payload);
        }
        catch ( Throwable $e )
        {
            log::error('workerman dispatch on ' . $id . ': ' . $e->getMessage());

            return;
        }

        if ( $reply !== null && $conn->is_open() )
        {
            $socket->send($reply);
        }
    }

    /**
     * Note that a socket is gone.
     *
     * @param TcpConnection $socket
     *
     * @return void
     */
    private function _disconnect(TcpConnection $socket): void
    {
        $id   = (string) $socket->id;
        $conn = $this->_conns[$id] ?? null;

        unset($this->_conns[$id], $this->_sockets[$id], $this->_seen[$id]);

        if ( $conn === null )
        {
            return;
        }

        try
        {
            dispatcher::close($conn);
        }
        catch ( Throwable $e )
        {
            log::error('workerman close on ' . $id . ': ' . $e->getMessage());
        }
    }

    /**
     * Normalise a websocket handshake into something an open hook can read.
     *
     * Workerman 4 hands over the raw request buffer and Workerman 5 a request object; both become
     * the same three keys, so an application that authenticates on a query parameter does not have
     * to know which major version it is running under.
     *
     * @param mixed $handshake Raw header buffer, or Workerman\Protocols\Http\Request
     *
     * @return array{path: string, query: array<string, mixed>, headers: array<string, string>}
     */
    private function _handshake($handshake): array
    {
        $empty = ['path' => '', 'query' => [], 'headers' => []];

        if ( is_object($handshake) && method_exists($handshake, 'header') )
        {
            $query = method_exists($handshake, 'get') ? $handshake->get() : [];

            return [
                'path'    => method_exists($handshake, 'path') ? (string) $handshake->path() : '',
                'query'   => is_array($query) ? $query : [],
                'headers' => (array) $handshake->header(),
            ];
        }

        if ( !is_string($handshake) || $handshake === '' )
        {
            return $empty;
        }

        $lines   = preg_split("/\r\n|\n/", $handshake) ?: [];
        $request = (string) array_shift($lines);
        $target  = (string) (explode(' ', $request)[1] ?? '');
        $query   = [];

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $headers = [];

        foreach ( $lines as $line )
        {
            if ( strpos($line, ':') === false )
            {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);

            $headers[strtolower(trim($name))] = trim($value);
        }

        return [
            'path'    => (string) (parse_url($target, PHP_URL_PATH) ?: ''),
            'query'   => $query,
            'headers' => $headers,
        ];
    }

    /**
     * A websocket close frame, unmasked as a frame from a server has to be.
     *
     * @param int    $code   Close code
     * @param string $reason Short text; the frame has 125 bytes for both, so the reason is cut
     *
     * @return string
     */
    private function _close_frame(int $code, string $reason): string
    {
        $payload = pack('n', $code) . substr($reason, 0, 123);

        return chr(0x88) . chr(strlen($payload)) . $payload;
    }

    /**
     * Refuse a listener whose protocol cannot deliver whole messages.
     *
     * @param string $listen
     *
     * @return void
     * @throws server_exception
     */
    private function _check_listen(string $listen): void
    {
        $scheme = $this->_scheme();

        if ( $scheme === '' || strpos($listen, '://') === false )
        {
            throw new server_exception(sprintf(
                'workerman `listen` is scheme://host:port, "%s" given',
                $listen
            ));
        }

        if ( $scheme === 'udp' )
        {
            throw new server_exception(
                'workerman cannot serve `udp://` here: a datagram carries no connection, so there is'
                    . ' nothing for the dispatcher to authenticate once and nothing to send a reply'
                    . ' back on'
            );
        }

        if ( $scheme === 'http' || $scheme === 'https' )
        {
            throw new server_exception(
                'workerman cannot serve `http://` here: this adapter dispatches messages on a long'
                    . ' lived connection, and an http request is a different shape of request.'
                    . ' Serve http through php-fpm or the framework\'s own entry point'
            );
        }

        if ( $scheme === 'ws' || $scheme === 'wss' )
        {
            throw new server_exception(sprintf(
                'workerman speaks `%s://` as a client, not as a listener; use `websocket://` and'
                    . ' set the ssl settings for wss',
                $scheme
            ));
        }

        if ( in_array($scheme, self::RAW, true) && (string) $this->_config['protocol'] === '' )
        {
            throw new server_exception(sprintf(
                '`%s://` is a byte stream, and the dispatcher takes one whole message: name a'
                    . ' Workerman protocol class in the `protocol` setting, or listen on one of %s',
                $scheme,
                implode('://, ', self::FRAMED) . '://'
            ));
        }
    }

    /**
     * Refuse a coroutine event loop.
     *
     * Only an explicitly configured one can be seen from here: Workerman picks its own default
     * inside runAll(), and the ones it picks -- Event and Select -- run one callback at a time.
     *
     * @return void
     * @throws server_exception
     */
    private function _check_loop(): void
    {
        $loop = (string) ($this->_config['event_loop'] ?: Worker::$eventLoopClass);

        if ( $loop === '' )
        {
            return;
        }

        $short = substr((string) strrchr('\\' . ltrim($loop, '\\'), '\\'), 1);

        if ( in_array($short, self::COROUTINE_LOOPS, true) )
        {
            throw new server_exception(sprintf(
                'the %s event loop runs several dispatches inside one process, and the framework'
                    . ' keeps request state in static properties: two messages served at once read'
                    . ' each other\'s. Use the default loop, or ext-event',
                $short
            ));
        }
    }

    /**
     * Validate a configured protocol class.
     *
     * @param string $class
     *
     * @return string  The class, as Workerman wants it
     * @throws server_exception
     */
    private function _check_protocol(string $class): string
    {
        if ( !class_exists($class) )
        {
            throw new server_exception(sprintf('workerman protocol class "%s" does not exist', $class));
        }

        // Workerman calls a protocol statically and does not require the interface -- its own
        // Frame, Text and Websocket classes implement none -- so the check is for the three methods
        // ProtocolInterface describes rather than for the interface itself
        foreach ( ['input', 'decode', 'encode'] as $method )
        {
            if ( !method_exists($class, $method) )
            {
                throw new server_exception(sprintf(
                    'workerman protocol class "%s" has no %s(); a protocol answers the three static'
                        . ' methods of %s',
                    $class,
                    $method,
                    ProtocolInterface::class
                ));
            }
        }

        return $class;
    }

    /**
     * The scheme of the configured listen address, lower case.
     *
     * @return string
     */
    private function _scheme(): string
    {
        $listen = (string) ($this->_config['listen'] ?? '');
        $scheme = strstr($listen, ':', true);

        return $scheme === false ? '' : strtolower($scheme);
    }

    /**
     * The tls settings, without the empty entries a configuration file leaves behind.
     *
     * @return array<string, mixed>
     */
    private function _ssl(): array
    {
        $ssl = (array) $this->_config['ssl'];

        if ( (string) ($ssl['local_cert'] ?? '') === '' )
        {
            return [];
        }

        return array_filter($ssl, function ($value): bool
        {
            return $value !== '' && $value !== null;
        });
    }

    /**
     * Point Workerman's own files at the application's writable directory.
     *
     * @return void
     * @throws server_exception When a directory cannot be created
     */
    private function _apply_paths(): void
    {
        $stem = preg_replace('/[^0-9a-z_.-]/i', '_', (string) $this->_config['name']) ?: 'platophp-server';

        Worker::$pidFile    = $this->_path('pid_file', plato::data_path('server/' . $stem . '.pid'));
        Worker::$statusFile = $this->_path('status_file', plato::data_path('server/' . $stem . '.status'));
        Worker::$logFile    = $this->_path('log_file', plato::log_path($stem . '-workerman.log'));
        Worker::$stdoutFile = $this->_path('stdout_file', plato::log_path($stem . '-stdout.log'));
    }

    /**
     * One configured file path, with its directory in place.
     *
     * @param string $key      Setting holding the path
     * @param string $fallback Path to use when the setting is empty
     *
     * @return string
     * @throws server_exception When the directory cannot be created
     */
    private function _path(string $key, string $fallback): string
    {
        $path = (string) ($this->_config[$key] ?: $fallback);
        $dir  = dirname($path);

        // is_dir() again: another worker of the same application may have won the race
        if ( !is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir) )
        {
            throw new server_exception(sprintf('workerman cannot create %s for `%s`', $dir, $key));
        }

        return $path;
    }
}
