<?php

namespace App\Services\Extraction;

use App\Exceptions\AnthropicUnavailableException;
use App\Models\LlmCache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Claude's Messages API, used as a document/vision
 * extractor. Mirrors GeminiClient's shape (cache-first via the shared replay
 * store, a configured() guard, one exception type for "not usable right
 * now" that a caller can catch to fall back to another provider) but gets
 * structured output through forced tool use rather than a response schema:
 * Claude is given one tool matching FormSchema's shape and `tool_choice`
 * forces it to call that tool, so the tool call's `input` *is* the
 * structured JSON — no prose to strip.
 */
class AnthropicClient
{
    public function configured(): bool
    {
        return filled(config('anthropic.api_key'));
    }

    /**
     * @param  array<string, mixed>  $inputSchema  standard JSON Schema (FormSchema::postMortemForm()/bodyPhoto())
     * @return array{json: array<string, mixed>, cached: bool}
     */
    public function generateStructured(
        string $model,
        string $prompt,
        string $mimeType,
        string $base64Data,
        array $inputSchema,
        string $toolName,
        string $promptVersion,
    ): array {
        $cacheInput = $promptVersion.'|'.$model.'|'.$mimeType.'|'.$prompt.'|'.hash('sha256', $base64Data);

        $cached = LlmCache::lookup($cacheInput, $promptVersion);

        if ($cached !== null) {
            return ['json' => (array) ($cached['json'] ?? []), 'cached' => true];
        }

        if (! $this->configured()) {
            throw new AnthropicUnavailableException(
                'Claude is not configured. Set ANTHROPIC_API_KEY, or fill this section of the form manually.',
            );
        }

        $blockType = $mimeType === 'application/pdf' ? 'document' : 'image';

        $response = Http::withHeaders([
            'x-api-key' => config('anthropic.api_key'),
            'anthropic-version' => config('anthropic.version'),
            'content-type' => 'application/json',
        ])
            ->timeout((int) config('anthropic.timeout'))
            ->post((string) config('anthropic.url'), [
                'model' => $model,
                'max_tokens' => (int) config('anthropic.max_tokens'),
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => $blockType,
                            'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64Data],
                        ],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ]],
                'tools' => [[
                    'name' => $toolName,
                    'description' => 'Record the structured fields read from the input.',
                    'input_schema' => $inputSchema,
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => $toolName],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Claude request failed: '.$response->status().' '.$response->body());
        }

        $toolUse = collect($response->json('content', []))->firstWhere('type', 'tool_use');
        $json = is_array($toolUse['input'] ?? null) ? $toolUse['input'] : [];

        LlmCache::remember($cacheInput, $promptVersion, ['json' => $json], $model);

        return ['json' => $json, 'cached' => false];
    }
}
