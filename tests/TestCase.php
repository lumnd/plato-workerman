<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for both suites.
 *
 * The framework is a static facade and the driver is an ordinary object, so there is nothing to
 * build per test. This exists to give the test closures a $this to hang per test state on.
 */
abstract class TestCase extends BaseTestCase
{
}
