<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the SET scraper cannot produce a usable reading: the browser/
 * Incapsula step failed, the Node process errored or timed out, or the output
 * was not valid JSON.
 */
class SetScraperException extends RuntimeException {}
