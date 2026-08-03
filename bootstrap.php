<?php

/**
 * Package bootstrap: make `'driver' => 'workerman'` resolve to this adapter
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

use plato\server\server;
use plato\workerman\driver;

/*
 * The shipped config/server.php names its driver `workerman`, and the framework resolves a short
 * name through a map only this package can fill -- so without these two lines the default
 * configuration installs correctly and then fails to start, telling the reader to install a package
 * that is already installed.
 *
 * It is a registration and nothing else: one entry in an array, no configuration read, no socket, no
 * connection. An application that would rather have no bootstrap file at all names the class in
 * config/server.php instead, which needs none of this:
 *
 *     'driver' => plato\workerman\driver::class,
 */
server::register_driver('workerman', driver::class);
