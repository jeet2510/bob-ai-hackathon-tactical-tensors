<?php

namespace App\Services\Extraction;

use App\Models\Observation;
use RuntimeException;

/**
 * Stage-1 extraction with IBM Granite on watsonx.ai.
 *
 * The rules extractor knows the words it was taught. A model can read the
 * sentence — which is what this data actually demands, because families were
 * interviewed in Marathi and Hindi, sometimes transliterated into Latin script
 * and mixed with English mid-clause, and no lexicon covers how people
 * genuinely speak about the worst day of their lives.
 *
 * The prompt pins the model to the same closed INTERPOL codebook the rules
 * extractor emits, so the two are directly comparable in the evaluation lab
 * and either can drive the pipeline.
 */
class GraniteExtractor implements ExtractorInterface
{
    public const VERSION = 'granite-v1';

    public function __construct(
        protected WatsonxClient $client,
    ) {}

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    public function extract(Observation $observation): array
    {
        $prompt = $this->prompt($observation);

        $result = $this->client->complete($prompt, (string) config('watsonx.prompt_version'));

        $items = $this->parse($result['text']);

        return array_map(
            // A model's own confidence is not calibrated, so a fixed value is
            // used and reviewers are pointed at the source text instead. What
            // the interface promises is traceability, not a probability.
            fn (array $item) => ['item' => $item, 'confidence' => 0.85],
            $items,
        );
    }

    protected function prompt(Observation $observation): string
    {
        $schema = $this->schemaFor($observation->category);

        return <<<PROMPT
        You convert one box of a disaster-victim-identification form into structured items.

        Rules:
        - Reply with a JSON array only. No prose, no markdown fence.
        - Use only the values listed for each field. If a value is not in the list, use null.
        - The text may be English, Hindi or Marathi, in Devanagari or Latin script, or a mix. Read all of them.
        - Record only what the text states. Never infer a detail that is not written.
        - If the text says a feature could not be examined (decomposition, burns, skin slippage),
          emit the item with "assessable": false rather than omitting it. Unexamined is not absent.
        - If the text says a feature is genuinely absent ("no tattoos"), emit {"category": "<type>", "negated": true}.
        - One box may describe several findings. Emit one item per finding.

        {$schema}

        Form field: {$observation->form_field}
        Language: {$observation->lang}
        Text: {$observation->raw_text}

        JSON:
        PROMPT;
    }

    /**
     * The closed vocabulary for a category, built from the lexicon so the
     * prompt and the rules extractor can never drift apart.
     */
    protected function schemaFor(string $category): string
    {
        $regions = implode('|', array_keys(Lexicon::REGION));
        $colours = implode('|', array_keys(Lexicon::CLOTHING_COLOUR));

        return match ($category) {
            'marks' => 'Schema: {"category":"mark","type":"'.implode('|', array_keys(Lexicon::MARK_TYPE))
                .'","region":"'.$regions.'","laterality":"left|right|null","size_mm":number|null,'
                .'"colour":"black|brown|dark_brown|null","negated":false}',

            'tattoo' => 'Schema: {"category":"tattoo","design":"'.implode('|', array_keys(Lexicon::TATTOO_DESIGN))
                .'","region":"'.$regions.'","laterality":"left|right|null","legible":true|false,"negated":false}',

            'clothing' => 'Schema: {"category":"clothing","slot":"upper|lower|footwear",'
                .'"garment":"'.implode('|', array_keys(Lexicon::GARMENT)).'","colour":"'.$colours.'|null"}',

            'jewellery' => 'Schema: {"category":"jewellery","item":"'.implode('|', array_keys(Lexicon::JEWELLERY))
                .'","metal":"yellow_metal|white_metal|other","site":"neck|wrist|ear|hand|foot|nose",'
                .'"laterality":"left|right|null"}',

            'belongings' => 'Schema: {"category":"belonging","item":"'
                .implode('|', array_keys(Lexicon::BELONGING)).'"}',

            'id_document' => 'Schema: {"category":"id_document","kind":"'.implode('|', array_keys(Lexicon::ID_KIND))
                .'","name":string|null,"id_last4":string|null,"on_body":true|false|null}',

            'implant' => 'Schema: {"category":"implant","type":"'.implode('|', array_keys(Lexicon::IMPLANT))
                .'","region":"'.$regions.'","laterality":"left|right|null","serial":string|null}',

            'build' => 'Schema: {"category":"build","value":"slight|medium|large","weight_kg":[min,max]|null}',

            'appearance' => 'Schemas (emit each that the text mentions): '
                .'{"category":"hair","colour":"black|grey|brown|null","length":"short|medium|long|null"}, '
                .'{"category":"eyes","colour":"brown|dark_brown|black|null"}, '
                .'{"category":"skin_tone","value":"fair|medium|dark|null"}, '
                .'{"category":"facial_hair","value":"clean_shaven|moustache|beard"}',

            default => 'Schema: {"category":"'.$category.'"}',
        };
    }

    /**
     * Pull the JSON array out of the completion.
     *
     * Models wrap output in prose or fences however firmly you ask them not
     * to, so the first bracketed array is taken rather than trusting the
     * whole response to parse.
     *
     * @return list<array<string, mixed>>
     */
    protected function parse(string $text): array
    {
        $text = trim($text);
        $start = strpos($text, '[');
        $end = strrpos($text, ']');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            fn ($item) => is_array($item) && isset($item['category']) && is_string($item['category']),
        ));
    }

    /**
     * Fail early and clearly rather than part-way through an incident.
     */
    public function assertUsable(): void
    {
        if (! $this->client->configured()) {
            throw new RuntimeException(
                'watsonx is not configured. Set WATSONX_API_KEY and WATSONX_PROJECT_ID in .env, '
                .'or run with --extractor=rules-v1, which needs no credentials.',
            );
        }
    }
}
