<?php

namespace App\Services\Extraction;

use Throwable;

/**
 * Reads a scanned or photographed copy of the DVI post-mortem field
 * examination form (docs/dvi-post-mortem-field-form.pdf, sections A–K) and
 * returns a payload shaped like BodyController::store()'s request body, so
 * the frontend can spread it directly into the intake form's state for the
 * examiner to review and correct before saving.
 *
 * Schema and prompt live in FormSchema — the same canonical definition
 * AnthropicFormScanExtractor uses, converted to Gemini's dialect.
 */
class GeminiFormScanExtractor
{
    public function __construct(
        protected GeminiClient $client,
    ) {}

    /**
     * @return array{ai_available: bool, fields: array<string, mixed>|null, confidence: array<string, float>|null, reason: string|null}
     */
    public function extract(string $base64Pdf): array
    {
        try {
            $result = $this->client->generateStructured(
                (string) config('gemini.model'),
                FormSchema::postMortemFormPrompt(),
                'application/pdf',
                $base64Pdf,
                FormSchema::toGeminiSchema(FormSchema::postMortemForm()),
                (string) config('gemini.prompt_version').'-pdf',
            );
        } catch (Throwable $e) {
            // Any failure here — no credentials, a transient 503, a network
            // blip — must degrade to "AI unavailable", never a 500: the
            // intake form has to stay usable regardless of what Gemini
            // (or Anthropic, upstream in FormScanCoordinator) is doing.
            return ['ai_available' => false, 'fields' => null, 'confidence' => null, 'reason' => $e->getMessage()];
        }

        $fields = $result['json'];
        $confidence = $fields['_confidence'] ?? null;
        unset($fields['_confidence']);

        return ['ai_available' => true, 'fields' => $fields, 'confidence' => $confidence, 'reason' => null];
    }
}
