<?php

namespace App\Services\Extraction;

use App\Models\LlmCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for watsonx.ai text generation.
 *
 * Every completion is written to the replay cache, so a pipeline run can be
 * reproduced exactly with no credentials and no network — which matters both
 * for a demo in a room with bad wifi and for an audit that has to re-derive
 * how a candidate list was produced months later.
 */
class WatsonxClient
{
    public function configured(): bool
    {
        return filled(config('watsonx.api_key')) && filled(config('watsonx.project_id'));
    }

    /**
     * Run a prompt, preferring a cached completion.
     *
     * @return array{text: string, cached: bool}
     */
    public function complete(string $prompt, string $promptVersion): array
    {
        $cached = LlmCache::lookup($prompt, $promptVersion);

        if ($cached !== null) {
            return ['text' => (string) ($cached['text'] ?? ''), 'cached' => true];
        }

        if (! $this->configured()) {
            throw new RuntimeException(
                'watsonx is not configured and this prompt is not in the replay cache. '
                .'Set WATSONX_API_KEY and WATSONX_PROJECT_ID, or use the rules-v1 extractor.',
            );
        }

        $response = Http::withToken($this->accessToken())
            ->timeout((int) config('watsonx.timeout'))
            ->post(rtrim((string) config('watsonx.url'), '/').'/ml/v1/text/generation?version='.config('watsonx.version'), [
                'model_id' => config('watsonx.model'),
                'project_id' => config('watsonx.project_id'),
                'input' => $prompt,
                'parameters' => [
                    'decoding_method' => 'greedy',
                    'temperature' => config('watsonx.temperature'),
                    'max_new_tokens' => (int) config('watsonx.max_tokens'),
                    'repetition_penalty' => 1.0,
                    // The model's job is to emit one JSON array and stop.
                    'stop_sequences' => ["\n\n\n"],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('watsonx request failed: '.$response->status().' '.$response->body());
        }

        $text = (string) ($response->json('results.0.generated_text') ?? '');

        LlmCache::remember($prompt, $promptVersion, ['text' => $text], (string) config('watsonx.model'));

        return ['text' => $text, 'cached' => false];
    }

    /**
     * IBM Cloud IAM exchanges an API key for a short-lived bearer token.
     * Cached just inside its lifetime so a full incident run needs one
     * exchange rather than one per form box.
     */
    protected function accessToken(): string
    {
        return Cache::remember('watsonx.iam_token', now()->addMinutes(50), function () {
            $response = Http::asForm()
                ->timeout((int) config('watsonx.timeout'))
                ->post((string) config('watsonx.iam_url'), [
                    'grant_type' => 'urn:ibm:params:oauth:grant-type:apikey',
                    'apikey' => config('watsonx.api_key'),
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Could not obtain an IBM Cloud IAM token: '.$response->status());
            }

            return (string) $response->json('access_token');
        });
    }
}
