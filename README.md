# lumnd/plato-workerman

Workerman adapter for [PlatoPHP](https://platophp.com) WebSocket and TCP servers. It supplies the
event loop, protocol handling, worker processes, heartbeat checks, CLI commands, and connection
lifecycle around PlatoPHP's protocol-neutral `plato\server\driver` contract.

[简体中文](README.zh-CN.md)

Use it when a PlatoPHP application needs:

- WebSocket services for chat, realtime dashboards, device links, push channels, or game/session
  coordination
- TCP, line, length-prefixed frame, or custom Workerman protocol listeners
- Multi-process resident workers controlled through `php vendor/bin/plato server:*` commands
- Connection-scoped authentication established once at open and reused for later messages
- A clear boundary between socket transport concerns and normal controller dispatch

Install it in an existing PlatoPHP application:

```bash
composer require lumnd/plato-workerman
```

Then register the commands and start the default WebSocket listener:

```bash
php vendor/bin/plato server:start
```

`lumnd/platophp` owns what happens between an inbound message and a controller — one whole message,
one `ct` / `ac` dispatch, clean request state, an identity that was established once and belongs to
the connection. It owns none of the socket: an event loop, a process manager and a protocol codec
are not a framework's business, and a protocol parser is the part of the stack where a mistake is a
remote memory exhaustion rather than a wrong page. This package is the other half of that seam, and
nothing above `plato\server\driver` knows it is here.

## Requirements

| | |
| --- | --- |
| PHP | 8.0+ |
| Workerman | `^4.1.5 \|\| ^5.0` — both majors, one code path |
| Extensions | `pcntl` and `posix`, which Workerman needs for the master process |
| Suggested | `ext-event`, for a loop that scales past a thousand connections per worker |

## Default driver

The shipped `config/server.php` already names `workerman` as its driver, so a default installation
starts after the one line that registers the commands, below.

## Configure

Everything the framework does not own in `config/server.php` is passed to this adapter untouched.
Copy the file into your application's `config/` and change what you need:

```php
return [
    'default' => 'default',

    'servers' => [
        'default' => [
            // 'workerman' is registered by this package; the class name works just as well and
            // needs no bootstrap file at all
            'driver' => 'workerman',

            'listen'    => 'websocket://127.0.0.1:8282',
            'name'      => 'platophp-server',
            'processes' => 4,

            'heartbeat' => ['interval' => 30, 'timeout' => 120],

            // Read by plato\server\dispatcher, not by this adapter
            'dispatch' => ['max_payload' => 65536],
        ],
    ],
];
```

| Setting | Default | What it is |
| --- | --- | --- |
| `listen` | `websocket://127.0.0.1:8282` | Where to listen, and in which protocol. See below |
| `name` | `platophp-server` | Process name in `ps`, and the stem of the pid, status and log file names |
| `processes` | `4` | Worker processes. One serves one message at a time |
| `user` / `group` | `''` | Drop privileges after binding |
| `pid_file` | `data_path()/server/<name>.pid` | Where the master keeps its pid |
| `status_file` | `data_path()/server/<name>.status` | Where `server:status` collects its report |
| `log_file` | `log_path()/<name>-workerman.log` | Workerman's own operational log |
| `stdout_file` | `log_path()/<name>-stdout.log` | Where a daemonized process sends its output |
| `daemonize` | `false` | Detach from the terminal; `--daemon` sets it for one run |
| `graceful` | `true` | Let stop, restart and reload wait for the workers to finish |
| `reuse_port` | `false` | `SO_REUSEPORT`, so a restart does not drop the listening socket |
| `protocol` | `''` | Workerman protocol class framing a raw `tcp://` listener |
| `max_package_size` | `0` | Largest packet assembled off the socket; `0` keeps Workerman's 10 MB |
| `stop_timeout` | `0` | Seconds a worker gets after a graceful stop; `0` keeps Workerman's 2 |
| `ssl` | `[]` | `local_cert`, `local_pk`, `verify_peer`, `allow_self_signed`. Paths, never key material |
| `context` | `[]` | Extra stream context for the listening socket |
| `heartbeat` | `[]` | `interval` and `timeout`, in seconds. Either at `0` turns the sweep off |
| `event_loop` | `''` | Workerman event loop class; a coroutine loop is refused |
| `on_worker_start` | `null` | `fn(int $index, int $count)`, in each worker once it knows which it is |
| `on_worker_stop` | `null` | `fn(int $index, int $count)`, on the way out |

TLS is `ssl.local_cert` plus a `websocket://` listener — that combination is `wss`. Terminating TLS
at a reverse proxy and binding `127.0.0.1` is the usual arrangement, and the one to prefer.

## Protocols

`dispatcher::handle()` takes **one whole application message**. Which protocol delivers it is this
adapter's business and not the framework's, so several do:

| Listen value | |
| --- | --- |
| `websocket://host:port` | What most clients speak, and what the shipped configuration defaults to |
| `text://host:port` | One message per line |
| `frame://host:port` | Four bytes of total length, big endian, then the payload |
| `tcp://host:port` + `protocol` | Any Workerman protocol class, including one you wrote |

and some do not:

| Refused | Why |
| --- | --- |
| `tcp://`, `ssl://`, `unix://` without `protocol` | A byte stream has no message boundaries, and a dispatcher handed half a message cannot tell |
| `udp://` | A datagram carries no connection, so there is nothing to authenticate once and nothing to answer on |
| `http://` | A different shape of request; serve HTTP through php-fpm or the framework's own entry point |
| `ws://`, `wss://` | Workerman speaks those as a *client*; a listener is `websocket://` |

Each is refused at start with a message saying which of these it is, rather than with a connection
that half works.

## Run it

Register the commands once, in `plato.config.php` or under `console.commands` in
`config/config.php`:

```php
'commands' => [plato\workerman\console::class],
```

```bash
php vendor/bin/plato server:start                    # foreground, until a signal
php vendor/bin/plato server:start --daemon           # detached
php vendor/bin/plato server:start --server=chat --processes=8
php vendor/bin/plato server:reload                   # new code, same listening socket
php vendor/bin/plato server:stop                     # --force to skip the graceful wait
php vendor/bin/plato server:status
php vendor/bin/plato server:connections
```

`server:start` is a foreground process on purpose, and belongs under something that keeps it
running:

```ini
[Service]
ExecStart=/usr/bin/php /srv/app/vendor/bin/plato server:start
Restart=always
KillSignal=SIGTERM
TimeoutStopSec=40
```

An application that would rather not use the console calls the facade itself, which is all the
command does:

```php
plato\server\server::start();
```

## Write the application

An action reached over a socket is an ordinary action. It reads its input through `req`, asks who
the caller is through `plato::$auth`, and returns a `plato\http\reply`:

```php
namespace control;

use plato\http\resp;
use plato\plato;
use plato\server\dispatcher;

class ctl_chat
{
    public function say()
    {
        // The identity was established at open and belongs to the connection
        $user = plato::$auth;

        // Reach this client again from anywhere in the process
        dispatcher::current()->send(['code' => 0, 'msg' => 'delivered']);

        return resp::json(['code' => 0, 'seq' => dispatcher::seq()]);
    }
}
```

Authentication happens **once, at open**. On a websocket the only thing a client has to
authenticate with is the handshake — the frames that follow carry no headers — so this adapter puts
it on the connection under `driver::HANDSHAKE`:

```php
use plato\server\connection;
use plato\server\dispatcher;
use plato\workerman\driver;

dispatcher::on('open', function (connection $conn)
{
    $handshake = (array) $conn->get(driver::HANDSHAKE, []);
    $user      = my_auth((string) ($handshake['query']['token'] ?? ''));

    if ( $user === null )
    {
        // The driver closes the connection
        return false;
    }

    // Every later message of this client is dispatched as this identity
    $conn->set(connection::AUTH, $user);

    return true;
});
```

`handshake` is `['path' => string, 'query' => array, 'headers' => array]`, with lower case header
names, on both Workerman majors. A protocol that has no handshake — a framed `tcp://` listener —
sets no attribute at all.

## Processes

Every worker calls `plato\worker::enter()` before it serves anything, so an application shards work
the same way it does under `plato\pool`:

```php
'on_worker_start' => function (int $index, int $count)
{
    // Exactly one worker of this listener runs the sweep
    if ( plato\worker::owns() )
    {
        Workerman\Timer::add(60, 'my_sweep');
    }
},
```

Two things the framework says out loud, and this adapter keeps to:

- **One message at a time per process.** Request state lives in static properties, so a coroutine
  scheduler running two dispatches inside one pid corrupts it. Workerman 5's `Fiber`, `Swoole` and
  `Swow` loops are refused rather than half supported.
- **`send()` reaches this process only.** Workers do not share memory, and neither
  `driver::connections()` nor `server::send()` pretends otherwise. Fanning out to every worker needs
  a backend both can see — Redis pub/sub, or another external bus.

## Tests

```bash
composer test          # Unit + Feature
composer analyse       # phpstan level 5, no baseline
composer style         # phpcs, zero errors
```

The feature suite starts real listeners in child processes and talks to them over real sockets with
a hand written websocket handshake and hand written frames — a client that shared its framing code
with the server would prove nothing about the wire.

## License

MIT. Security reports go to the address in [SECURITY.md](SECURITY.md).
