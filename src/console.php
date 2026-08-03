<?php

/**
 * Console commands: start, stop and inspect a listener served by this adapter
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\workerman;

use plato\console\command;
use plato\console\console as kernel;
use plato\server\server;
use Throwable;

/**
 * The `server:` verbs, for a listener whose driver is this adapter.
 *
 * Register it once, in `plato.config.php` or under `console.commands` in `config/config.php`:
 *
 *     'commands' => [plato\workerman\console::class],
 *
 * and the framework's console answers `server:start` and its siblings:
 *
 *     php vendor/bin/plato server:start --server=chat --daemon
 *
 * `server:start` is a foreground process unless `--daemon` says otherwise, and it is meant to be run
 * by something that keeps it running -- systemd, supervisord, a container runtime -- exactly like
 * `queue:work`:
 *
 *     [Service]
 *     ExecStart=/usr/bin/php /srv/app/vendor/bin/plato server:start
 *     Restart=always
 *     KillSignal=SIGTERM
 *
 * The other verbs are Workerman's own, and they reach the running master through the pid file rather
 * than through this process: `server:reload` replaces the workers without dropping the listening
 * socket, `server:status` and `server:connections` print what the master reports. Workerman answers
 * them inside its command parser and ends the process there, so nothing after the call runs.
 */
class console implements command
{
    /**
     * Prefix every name of this class carries.
     */
    private const PREFIX = 'server:';

    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'server:start'       => 'Run a resident listener until a signal stops it',
            'server:stop'        => 'Stop the running listener',
            'server:restart'     => 'Stop the running listener and start it again',
            'server:reload'      => 'Replace the workers without dropping the listening socket',
            'server:status'      => 'Print what the running master reports about its workers',
            'server:connections' => 'Print the connections the running workers hold',
        ];
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public static function usage(string $name): string
    {
        $common = '  --server=NAME          Listener from config/server.php, default the configured one';

        if ( $name === 'server:start' )
        {
            return $common
                . PHP_EOL . '  --daemon               Detach from the terminal'
                . PHP_EOL . '  --processes=N          Worker processes, default the configured count';
        }

        if ( in_array($name, ['server:stop', 'server:restart', 'server:reload'], true) )
        {
            return $common
                . PHP_EOL . '  --force                Do not wait for the workers to finish what'
                . ' they are doing'
                . ($name === 'server:restart'
                    ? PHP_EOL . '  --daemon               Detach from the terminal'
                    : '');
        }

        return $common;
    }

    /**
     * @return array<int, string>
     */
    public static function requires(): array
    {
        return [];
    }

    /**
     * @param string $name
     *
     * @return int
     */
    public static function handle(string $name): int
    {
        $verb     = substr($name, strlen(self::PREFIX));
        $listener = kernel::option('server');
        $listener = is_string($listener) && $listener !== '' ? $listener : null;

        try
        {
            // Reads config/server.php without resolving the adapter, so a listener that is spelled
            // wrong is reported as that rather than as a missing class
            $settings = server::settings($listener);
            $driver   = server::driver($listener);
        }
        catch ( Throwable $e )
        {
            kernel::fail($e->getMessage());

            return kernel::FAILURE;
        }

        if ( !$driver instanceof driver )
        {
            kernel::fail(sprintf(
                'server "%s" is served by %s, and these commands drive %s',
                $listener ?? (string) server::config('default'),
                get_class($driver),
                driver::class
            ));

            return kernel::FAILURE;
        }

        $overrides = self::_overrides();

        if ( $overrides !== [] )
        {
            // A driver's configure() replaces its settings as a whole, so the overrides go on top of
            // the file rather than on top of whatever the instance already held
            $driver->configure($overrides + $settings);
        }

        try
        {
            if ( $verb === 'start' )
            {
                kernel::line(sprintf(
                    'Listening on %s in %d process%s',
                    (string) $driver->config('listen'),
                    max(1, (int) $driver->config('processes')),
                    (int) $driver->config('processes') === 1 ? '' : 'es'
                ));

                // Applies the dispatch settings of this listener, then blocks in the event loop
                server::start($listener);

                return kernel::OK;
            }

            $driver->command($verb);
        }
        catch ( Throwable $e )
        {
            kernel::fail($name . ': ' . $e->getMessage());

            return kernel::FAILURE;
        }

        return kernel::OK;
    }

    /**
     * Settings the command line overrides for this one run.
     *
     * @return array<string, mixed>
     */
    private static function _overrides(): array
    {
        $overrides = [];

        if ( kernel::option('daemon', false) )
        {
            $overrides['daemonize'] = true;
        }

        if ( kernel::option('force', false) )
        {
            $overrides['graceful'] = false;
        }

        $processes = kernel::option('processes');

        if ( is_string($processes) && $processes !== '' )
        {
            $overrides['processes'] = max(1, (int) $processes);
        }

        return $overrides;
    }
}
