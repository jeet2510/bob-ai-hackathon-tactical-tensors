<?php

namespace App\Services\Extraction;

use Throwable;

/**
 * Reads a live-scan body photograph via Claude. Same contract and same
 * canonical schema (FormSchema) as GeminiVisionExtractor.
 */
class AnthropicVisionExtractor
{
    public function __construct(
        protected AnthropicClient $client,
    ) {}

    /**
     * @return array{ai_available: bool, fields: array<string, mixed>|null, confidence: array<string, float>|null, reason: string|null}
     */
    public function extract(string $base64Image, string $mimeType): array
    {
        try {
            $result = $this->client->generateStructured(
                (string) config('anthropic.model'),
                FormSchema::bodyPhotoPrompt(),
                $mimeType,
                $base64Image,
                FormSchema::bodyPhoto(),
                'extract_body_photo',
                (string) config('anthropic.prompt_version').'-vision',
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
