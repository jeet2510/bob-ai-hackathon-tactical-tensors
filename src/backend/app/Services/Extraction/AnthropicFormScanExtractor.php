<?php

namespace App\Services\Extraction;

use Throwable;

/**
 * Reads a scanned/photographed copy of the DVI post-mortem field form via
 * Claude. Same contract and same canonical schema (FormSchema) as
 * GeminiFormScanExtractor — the two are interchangeable, which is what lets
 * FormScanCoordinator try one and fall back to the other.
 */
class AnthropicFormScanExtractor
{
    public function __construct(
        protected AnthropicClient $client,
    ) {}

    /**
     * @return array{ai_available: bool, fields: array<string, mixed>|null, confidence: array<string, float>|null, reason: string|null}
     */
    public function extract(string $base64Pdf): array
    {
        try {
            $result = $this->client->generateStructured(
                (string) config('anthropic.model'),
                FormSchema::postMortemFormPrompt(),
                'application/pdf',
                $base64Pdf,
                FormSchema::postMortemForm(),
                'extract_pm_form',
                (string) config('anthropic.prompt_version').'-pdf',
            );
        } catch (Throwable $e) {
            // Any failure — no credentials, no credit balance, a transient
            // overload, a network blip — must degrade to "unavailable" here
            // so FormScanCoordinator can fall back to Gemini instead of the
            // whole request 500ing.
            return ['ai_available' => false, 'fields' => null, 'confidence' => null, 'reason' => $e->getMessage()];
        }

        $fields = $result['json'];
        $confidence = $fields['_confidence'] ?? null;
        unset($fields['_confidence']);

        return ['ai_available' => true, 'fields' => $fields, 'confidence' => $confidence, 'reason' => null];
    }
}
