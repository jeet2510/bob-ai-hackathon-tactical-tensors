<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Claude has no credentials configured and the request is not in the replay
 * cache. Mirrors GeminiUnavailableException — callers catch this (and any
 * other failure from this provider) to fall back to the next provider
 * rather than failing the whole scan.
 */
class AnthropicUnavailableException extends RuntimeException {}
