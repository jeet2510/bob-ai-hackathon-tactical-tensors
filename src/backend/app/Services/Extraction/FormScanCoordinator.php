<?php

namespace App\Services\Extraction;

/**
 * Runs the intake form's two AI-assist scans through Claude first, falling
 * back to Gemini if Claude doesn't come back with usable fields — not
 * configured, or a transient failure (a 503 "high demand" has been the
 * common case in practice). Both providers implement the same
 * extract(...): {ai_available, fields, confidence, reason} contract against
 * the same FormSchema, so the fallback is a straight swap with no special
 * casing at the call site.
 *
 * Only a provider that actually returned usable fields is reported back;
 * if both fail, the combined reason names both, and the intake form simply
 * falls back to manual entry — the one constant across every AI path in
 * this app.
 */
class FormScanCoordinator
{
    public function __construct(
        protected AnthropicFormScanExtractor $anthropicForm,
        protected GeminiFormScanExtractor $geminiForm,
        protected AnthropicVisionExtractor $anthropicVision,
        protected GeminiVisionExtractor $geminiVision,
    ) {}

    /**
     * @return array{ai_available: bool, provider: string|null, fields: array<string, mixed>|null, confidence: array<string, float>|null, reason: string|null}
     */
    public function scanPdf(string $base64Pdf): array
    {
        return $this->attempt([
            'claude' => fn () => $this->anthropicForm->extract($base64Pdf),
            'gemini' => fn () => $this->geminiForm->extract($base64Pdf),
        ]);
    }

    /**
     * @return array{ai_available: bool, provider: string|null, fields: array<string, mixed>|null, confidence: array<string, float>|null, reason: string|null}
     */
    public function scanPhoto(string $base64Image, string $mimeType): array
    {
        return $this->attempt([
            'claude' => fn () => $this->anthropicVision->extract($base64Image, $mimeType),
            'gemini' => fn () => $this->geminiVision->extract($base64Image, $mimeType),
        ]);
    }

    /**
     * @param  array<string, callable(): array{ai_available: bool, fields: array<string, mixed>|null, confidence: array<string, float>|null, reason: string|null}>  $providers
     * @return array{ai_available: bool, provider: string|null, fields: array<string, mixed>|null, confidence: array<string, float>|null, reason: string|null}
     */
    protected function attempt(array $providers): array
    {
        $reasons = [];

        foreach ($providers as $name => $run) {
            $result = $run();

            if ($result['ai_available']) {
                return $result + ['provider' => $name];
            }

            $reasons[] = "{$name}: ".($result['reason'] ?? 'unavailable');
        }

        return [
            'ai_available' => false,
            'provider' => null,
            'fields' => null,
            'confidence' => null,
            'reason' => 'No AI provider is available right now ('.implode('; ', $reasons).'). Fill the form manually.',
        ];
    }
}
