<?php

/**
 * The application half of the fixture: the hooks an application binds and the controller the
 * feature suite talks to.
 *
 * Required by both entry points -- tests/Fixtures/server.php, which configures a listener itself,
 * and tests/Fixtures/console.php, which lets `plato server:start` and the shipped config/server.php
 * do it -- so that both suites drive exactly the same application.
 */

namespace control;

use plato\http\req;
use plato\http\resp;
use plato\plato;
use plato\server\connection;
use plato\server\dispatcher;
use plato\server\server;
use plato\worker;
use plato\workerman\driver;

/*
 * Authentication happens once, at open, and is stored on the connection: every later message of this
 * client is dispatched as that identity without the payload having to carry it.
 */
dispatcher::on('open', function (connection $conn)
{
    $handshake = (array) $conn->get(driver::HANDSHAKE, []);
    $token     = (string) ($handshake['query']['token'] ?? '');

    if ( $token === 'no' )
    {
        return false;
    }

    $conn->set(connection::AUTH, ['token' => $token === '' ? 'anonymous' : $token]);

    return true;
});

/*
 * An application level ping, answered without reaching a controller.
 */
dispatcher::on('message', function (connection $conn, array $msg)
{
    return ($msg['ct'] ?? '') === 'ping' ? ['code' => 0, 'msg' => 'pong'] : null;
});

/**
 * The controller the suite talks to.
 */
class ctl_echo
{
    /**
     * Report everything the framework knows about this dispatch.
     */
    public function index()
    {
        $conn = dispatcher::current();

        return resp::raw((string) json_encode([
            'code'   => 0,
            'said'   => req::get('say', ''),
            'auth'   => plato::$auth,
            'seq'    => dispatcher::seq(),
            'pid'    => getmypid(),
            'worker' => ['index' => worker::index(), 'count' => worker::count()],
            'conn'   => [
                'id'        => $conn === null ? '' : $conn->id(),
                'remote'    => $conn === null || $conn->remote() === '' ? '' : 'set',
                'handshake' => $conn === null ? null : $conn->get(driver::HANDSHAKE),
                'count'     => count(server::connections()),
            ],
        ]), 'application/json');
    }

    /**
     * Push to a connection of this process by id, which is the facade path rather than the
     * connection object the dispatch already holds.
     */
    public function push()
    {
        $sent = server::send((string) req::get('id', ''), (string) json_encode(['code' => 0, 'msg' => 'pushed']));

        return resp::raw((string) json_encode(['code' => 0, 'sent' => $sent]), 'application/json');
    }

    /**
     * Close the connection from inside the action.
     */
    public function bye()
    {
        $conn = dispatcher::current();
        $conn === null || $conn->close(1000, 'bye');

        return resp::raw((string) json_encode(['code' => 0]), 'application/json');
    }

    /**
     * Fail, so that the suite can prove one bad message takes neither the connection nor the worker
     * with it.
     */
    public function boom()
    {
        throw new \RuntimeException('deliberate failure');
    }
}
