<?php

namespace App\Services\Extraction;

use App\Exceptions\GeminiUnavailableException;
use App\Models\LlmCache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Gemini's multimodal generateContent API.
 *
 * Mirrors WatsonxClient's shape — cache-first via the same replay store, a
 * configured() guard — with two differences the intake form's AI-assist
 * panels need: it sends a file (PDF or image) alongside the prompt, and it
 * asks Gemini to return schema-constrained JSON directly rather than parsing
 * prose. And where WatsonxClient throws on a cache miss with no credentials,
 * this throws GeminiUnavailableException specifically, so a controller
 * running synchronously inside a form-filling request can catch just that
 * and degrade to "AI unavailable" instead of a 500.
 */
class GeminiClient
{
    public function configured(): bool
    {
        return filled(config('gemini.api_key'));
    }

    /**
     * @param  array<string, mixed>  $responseSchema  Gemini's OpenAPI-subset JSON schema
     * @return array{json: array<string, mixed>, cached: bool}
     */
    public function generateStructured(
        string $model,
        string $prompt,
        string $mimeType,
        string $base64Data,
        array $responseSchema,
        string $promptVersion,
    ): array {
        $cacheInput = $promptVersion.'|'.$model.'|'.$mimeType.'|'.$prompt.'|'.hash('sha256', $base64Data);

        $cached = LlmCache::lookup($cacheInput, $promptVersion);

        if ($cached !== null) {
            return ['json' => (array) ($cached['json'] ?? []), 'cached' => true];
        }

        if (! $this->configured()) {
            throw new GeminiUnavailableException(
                'Gemini is not configured. Set GEMINI_API_KEY, or fill this section of the form manually.',
            );
        }

        $url = rtrim((string) config('gemini.url'), '/')
            ."/models/{$model}:generateContent?key=".urlencode((string) config('gemini.api_key'));

        $response = Http::timeout((int) config('gemini.timeout'))
            ->post($url, [
                'contents' => [[
                    'parts' => [
                        ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Data]],
                        ['text' => $prompt],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => config('gemini.temperature'),
                    'maxOutputTokens' => (int) config('gemini.max_tokens'),
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $responseSchema,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini request failed: '.$response->status().' '.$response->body());
        }

        $text = (string) ($response->json('candidates.0.content.parts.0.text') ?? '');
        $json = json_decode($text, true);
        $json = is_array($json) ? $json : [];

        LlmCache::remember($cacheInput, $promptVersion, ['json' => $json], $model);

        return ['json' => $json, 'cached' => false];
    }

    /**
     * Same contract as generateStructured(), but for a request built from
     * several ordered text/image parts rather than one prompt plus one file —
     * what the match-refinement matcher needs to hand Gemini a body's photos
     * alongside several candidates' photos in a single call, each clearly
     * labelled by the text part immediately before it.
     *
     * @param  list<array{type: 'text', text: string}|array{type: 'image', mimeType: string, data: string}>  $parts
     * @param  array<string, mixed>  $responseSchema  Gemini's OpenAPI-subset JSON schema
     * @return array{json: array<string, mixed>, cached: bool}
     */
    public function generateStructuredMultipart(
        string $model,
        array $parts,
        array $responseSchema,
        string $promptVersion,
    ): array {
        $fingerprint = collect($parts)
            ->map(fn (array $part) => $part['type'] === 'text'
                ? 'text:'.$part['text']
                : 'image:'.$part['mimeType'].':'.hash('sha256', $part['data']))
            ->implode('||');

        $cacheInput = $promptVersion.'|'.$model.'|'.hash('sha256', $fingerprint);

        $cached = LlmCache::lookup($cacheInput, $promptVersion);

        if ($cached !== null) {
            return ['json' => (array) ($cached['json'] ?? []), 'cached' => true];
        }

        if (! $this->configured()) {
            throw new GeminiUnavailableException(
                'Gemini is not configured. Set GEMINI_API_KEY to use AI-assisted matching.',
            );
        }

        $url = rtrim((string) config('gemini.url'), '/')
            ."/models/{$model}:generateContent?key=".urlencode((string) config('gemini.api_key'));

        $contentParts = collect($parts)->map(fn (array $part) => $part['type'] === 'text'
            ? ['text' => $part['text']]
            : ['inline_data' => ['mime_type' => $part['mimeType'], 'data' => $part['data']]])->all();

        $response = Http::timeout((int) config('gemini.timeout'))
            ->post($url, [
                'contents' => [['parts' => $contentParts]],
                'generationConfig' => [
                    'temperature' => config('gemini.temperature'),
                    'maxOutputTokens' => (int) config('gemini.max_tokens'),
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $responseSchema,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini request failed: '.$response->status().' '.$response->body());
        }

        $text = (string) ($response->json('candidates.0.content.parts.0.text') ?? '');
        $json = json_decode($text, true);
        $json = is_array($json) ? $json : [];

        LlmCache::remember($cacheInput, $promptVersion, ['json' => $json], $model);

        return ['json' => $json, 'cached' => false];
    }
}
