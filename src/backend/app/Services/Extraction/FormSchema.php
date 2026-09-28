<?php

namespace App\Services\Extraction;

/**
 * The single source of truth for what the two AI-assist scan paths ask a
 * model to return — one canonical JSON Schema per scan kind, shared by every
 * provider, so Claude and Gemini are structurally guaranteed to describe the
 * same fields in the same vocabulary. A provider that drifted from this
 * would silently produce data the intake form's merge logic doesn't expect.
 *
 * The canonical schema is standard JSON Schema (used as-is for Anthropic's
 * tool `input_schema`). `toGeminiSchema()` mechanically converts it to
 * Gemini's OpenAPI-subset dialect (uppercase type keywords, `nullable` on
 * every property that isn't `required`) — there is exactly one place that
 * knows the field list, and exactly one place that knows the dialect
 * difference.
 */
class FormSchema
{
    public static function postMortemFormPrompt(): string
    {
        return <<<PROMPT
        This is a scanned or photographed copy (one or two pages) of a DVI
        Coordinator "Post-Mortem Field Examination Form", filled in by hand.

        Transcribe only what is checked or written on the form. Rules:
        - Leave a field out (or null) if the box is blank or illegible. Never guess.
        - If a checkbox section states a feature could not be examined (the
          body condition is Advanced decomposition or Burnt, or a box is
          explicitly marked "not examined"), still record what IS legible;
          do not invent values to fill gaps.
        - Dates go in the found_at field as an ISO-ish "YYYY-MM-DD HH:MM" if
          both a date and time box are filled, else date only.
        - body_condition, dna_status, dental_status and print_status must be
          one of exactly the enum values given in the schema — map the
          paper's plain-English checkbox label to the matching enum value.
        - Section E (dental chart): emit one entry per tooth box that has a
          letter written in it (M, F, C or R); the tooth is the FDI number
          printed above/below that box. Leave sound/blank teeth out entirely.
        - Section F (distinguishing features): one entry per numbered row
          with anything written in it, up to 6.
        - Section G (clothing): one entry per one of the five fixed slots
          (Headwear, Upper body, Lower body, Footwear, Other) that has
          anything written in its Garment or Colour column.
        - Section H (jewellery/effects): one entry per row with an item
          written in it, up to 3, with kind set to whichever of the
          Jewellery/Belonging checkboxes is ticked.
        - Section I (identity documents): one entry per row with anything
          written in it.
        - Section J (photo evidence log): one entry per row with a modality
          checkbox ticked.
        - notes is the free text from section K, verbatim.

        Return only the structured data described by the schema — nothing else.
        PROMPT;
    }

    public static function bodyPhotoPrompt(): string
    {
        return <<<PROMPT
        This is a photograph taken during a disaster-victim-identification
        field examination, for a "live scan" prefill of a post-mortem intake
        form. Read only what is visually evident.

        Rules:
        - This is evidence documentation for identification purposes, not a
          medical diagnosis — describe only what is visible.
        - Never estimate sex, exact age, DNA/dental/fingerprint status, or
          anything that requires physical examination rather than a
          photograph — leave those fields out entirely.
        - body_condition, build, skin_tone, hair_colour, hair_length and
          facial_hair must each be one of exactly the enum values given, and
          each carries its own 0–1 confidence in the _confidence object
          (keyed by field name). Omit a field entirely rather than guess if
          the photo does not show it clearly.
        - List every visible tattoo and every visible distinguishing mark
          (scar, mole, birthmark, burn, deformity, amputation, piercing)
          separately, each with your best guess at body region and side.
        - List visible clothing by slot (upper/lower/footwear) with garment
          and colour, only for what is actually visible in the photo.
        - If the image does not show a body clearly enough to assess
          anything, return every field empty rather than guessing.

        Return only the structured data described by the schema — nothing else.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function postMortemForm(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'examiner_name' => ['type' => 'string'],
                'examiner_role' => ['type' => 'string'],
                'found_at' => ['type' => 'string'],
                'found_place' => ['type' => 'string'],
                'lat' => ['type' => 'number'],
                'lon' => ['type' => 'number'],
                'body_condition' => ['type' => 'string',
                    'enum' => ['Fresh', 'Slight decomp.', 'Moderate decomp.', 'Advanced decomp.', 'Burnt']],
                'sex' => ['type' => 'string', 'enum' => ['M', 'F']],
                'age_min' => ['type' => 'integer'],
                'age_max' => ['type' => 'integer'],
                'height_cm' => ['type' => 'integer'],
                'build' => ['type' => 'string', 'enum' => ['Slim', 'Medium', 'Heavy', 'Muscular', 'Obese']],
                'skin_tone' => ['type' => 'string', 'enum' => ['Fair', 'Wheatish', 'Dark', 'Other']],
                'skin_tone_other' => ['type' => 'string'],
                'hair_colour' => ['type' => 'string', 'enum' => ['Black', 'Brown', 'Grey', 'White', 'Dyed']],
                'hair_length' => ['type' => 'string', 'enum' => ['Bald', 'Short', 'Medium', 'Long']],
                'eye_colour' => ['type' => 'string', 'enum' => ['Black', 'Brown', 'Hazel', 'Grey']],
                'facial_hair' => ['type' => 'string',
                    'enum' => ['Clean-shaven', 'Moustache', 'Beard', 'Stubble', 'Not applicable']],
                'dna_status' => ['type' => 'string', 'enum' => ['sample_taken', 'degraded', 'not_collected']],
                'dental_status' => ['type' => 'string', 'enum' => ['chart_completed', 'not_examined', 'unsuitable']],
                'print_status' => ['type' => 'string', 'enum' => ['usable', 'unusable', 'not_taken']],
                'dental_chart' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'tooth' => ['type' => 'integer'],
                            'code' => ['type' => 'string', 'enum' => ['M', 'F', 'C', 'R']],
                        ],
                        'required' => ['tooth', 'code'],
                    ],
                ],
                'distinguishing_features' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => [
                                'tattoo', 'mark', 'scar', 'mole', 'birthmark', 'burn', 'deformity', 'amputation', 'piercing', 'implant', 'other',
                            ]],
                            'description' => ['type' => 'string'],
                            'region' => ['type' => 'string'],
                            'side' => ['type' => 'string', 'enum' => ['L', 'R', 'C']],
                            'serial_no' => ['type' => 'string'],
                            'photo_ref' => ['type' => 'string'],
                        ],
                        'required' => ['type'],
                    ],
                ],
                'clothing' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'slot' => ['type' => 'string', 'enum' => ['Headwear', 'Upper body', 'Lower body', 'Footwear', 'Other']],
                            'garment' => ['type' => 'string'],
                            'colour' => ['type' => 'string'],
                        ],
                        'required' => ['slot'],
                    ],
                ],
                'jewellery_effects' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['jewellery', 'belonging']],
                            'item' => ['type' => 'string'],
                            'material_description' => ['type' => 'string'],
                        ],
                        'required' => ['kind'],
                    ],
                ],
                'id_documents' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'document_type' => ['type' => 'string'],
                            'id_last4' => ['type' => 'string'],
                            'name_on_document' => ['type' => 'string'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
                'photo_log' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'modality' => ['type' => 'string', 'enum' => ['Body diagram', 'Tattoo/mark', 'Clothing', 'Face (restr.)', 'Other']],
                            'view' => ['type' => 'string'],
                            'quality_flags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => ['Blur', 'Low light', 'Noise', 'None']],
                            ],
                        ],
                        'required' => ['modality'],
                    ],
                ],
                'notes' => ['type' => 'string'],
                'signature_note' => ['type' => 'string'],
                'completed_at' => ['type' => 'string'],
                'chain_of_custody_hash' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function bodyPhoto(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'body_condition' => ['type' => 'string',
                    'enum' => ['Fresh', 'Slight decomp.', 'Moderate decomp.', 'Advanced decomp.', 'Burnt']],
                'build' => ['type' => 'string', 'enum' => ['Slim', 'Medium', 'Heavy', 'Muscular', 'Obese']],
                'skin_tone' => ['type' => 'string', 'enum' => ['Fair', 'Wheatish', 'Dark']],
                'hair_colour' => ['type' => 'string', 'enum' => ['Black', 'Brown', 'Grey', 'White', 'Dyed']],
                'hair_length' => ['type' => 'string', 'enum' => ['Bald', 'Short', 'Medium', 'Long']],
                'facial_hair' => ['type' => 'string', 'enum' => ['Clean-shaven', 'Moustache', 'Beard', 'Stubble']],
                'distinguishing_features' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => [
                                'tattoo', 'mark', 'scar', 'mole', 'birthmark', 'burn', 'deformity', 'amputation', 'piercing', 'other',
                            ]],
                            'description' => ['type' => 'string'],
                            'region' => ['type' => 'string'],
                            'side' => ['type' => 'string', 'enum' => ['L', 'R', 'C']],
                        ],
                        'required' => ['type'],
                    ],
                ],
                'clothing' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'slot' => ['type' => 'string', 'enum' => ['Upper body', 'Lower body', 'Footwear']],
                            'garment' => ['type' => 'string'],
                            'colour' => ['type' => 'string'],
                        ],
                        'required' => ['slot'],
                    ],
                ],
                '_confidence' => [
                    'type' => 'object',
                    'properties' => [
                        'body_condition' => ['type' => 'number'],
                        'build' => ['type' => 'number'],
                        'skin_tone' => ['type' => 'number'],
                        'hair_colour' => ['type' => 'number'],
                        'hair_length' => ['type' => 'number'],
                        'facial_hair' => ['type' => 'number'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Standard JSON Schema -> Gemini's OpenAPI-subset dialect: uppercase
     * `type`, and `nullable: true` on every property not listed in its
     * parent's `required` (Gemini otherwise treats every declared property
     * as mandatory and can refuse to omit it).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function toGeminiSchema(array $schema): array
    {
        $out = [];

        if (isset($schema['type'])) {
            $out['type'] = strtoupper((string) $schema['type']);
        }

        if (isset($schema['enum'])) {
            $out['enum'] = $schema['enum'];
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $out['items'] = self::toGeminiSchema($schema['items']);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $required = $schema['required'] ?? [];
            $props = [];

            foreach ($schema['properties'] as $name => $propSchema) {
                $converted = self::toGeminiSchema($propSchema);

                if (! in_array($name, $required, true)) {
                    $converted['nullable'] = true;
                }

                $props[$name] = $converted;
            }

            $out['properties'] = $props;
        }

        return $out;
    }
}
