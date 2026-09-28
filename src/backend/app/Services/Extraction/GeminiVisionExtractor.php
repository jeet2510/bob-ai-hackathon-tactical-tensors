<?php

namespace App\Services\Extraction;

use Throwable;

/**
 * Reads a "live scan" photograph of a recovered body and returns the subset
 * of the post-mortem form that can actually be judged from a photograph.
 * Schema and prompt live in FormSchema, shared with AnthropicVisionExtractor.
 */
class GeminiVisionExtractor
{
    public function __construct(
        protected GeminiClient $client,
    ) {}

    /**
     * @return array{ai_available: bool, fields: array<string, mixed>|null, confidence: array<string, float>|null, reason: string|null}
     */
    public function extract(string $base64Image, string $mimeType): array
    {
        try {
            $result = $this->client->generateStructured(
                (string) config('gemini.vision_model'),
                FormSchema::bodyPhotoPrompt(),
                $mimeType,
                $base64Image,
                FormSchema::toGeminiSchema(FormSchema::bodyPhoto()),
                (string) config('gemini.prompt_version').'-vision',
            );
        } catch (Throwable $e) {
            return ['ai_available' => false, 'fields' => null, 'confidence' => null, 'reason' => $e->getMessage()];
        }

        $fields = $result['json'];
        $confidence = $fields['_confidence'] ?? null;
        unset($fields['_confidence']);

        return ['ai_available' => true, 'fields' => $fields, 'confidence' => $confidence, 'reason' => null];
    }
}
