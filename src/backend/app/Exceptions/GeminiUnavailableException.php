<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Gemini has no credentials configured and the request is not in the replay
 * cache. Callers on the intake form's AI-assist path catch this and fall
 * back to `ai_available: false` rather than letting it become a 500 — the
 * form must stay fully usable with no AI credentials at all.
 */
class GeminiUnavailableException extends RuntimeException {}
