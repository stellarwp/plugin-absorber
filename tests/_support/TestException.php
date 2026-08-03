<?php
/**
 * Exception used to halt execution in place of exit().
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use Exception;

/**
 * Thrown from a stubbed function to stop a code path right before it calls exit().
 *
 * Mocking exit() itself lets a test keep running past the point production would
 * have halted, which can report a failing test as passing. Throwing instead stops
 * execution at a point the test controls. See tests/README.md.
 *
 * @since 1.0.0
 */
class TestException extends Exception {
}
