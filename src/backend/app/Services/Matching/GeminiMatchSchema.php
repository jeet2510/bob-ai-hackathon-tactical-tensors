<?php

namespace App\Services\Matching;

use App\Services\Extraction\FormSchema;

/**
 * What Gemini is asked to do when refining a body's deterministic shortlist,
 * and the shape it must answer in. Written as standard JSON Schema and
 * converted through FormSchema::toGeminiSchema() — the one place in this app
 * that knows Gemini's OpenAPI-subset dialect — rather than hand-writing a
 * second dialect here.
 */
class GeminiMatchSchema
{
    public static function instructions(): string
    {
        return <<<PROMPT
        You are assisting a disaster-victim-identification (DVI) reviewer.
        A deterministic rules-based scorer has already ranked a shortlist of
        missing-person reports against one recovered body, using structured
        text evidence (dental, tattoos, marks, clothing, jewellery, and
        similar features). You are given that shortlist, the rules engine's
        own evidence rationale for each candidate, and — where available —
        photographs from the post-mortem examination and from each family's
        report. Your job is to re-rank and validate the TOP THREE using
        visual evidence the text-only scorer cannot see: does a tattoo,
        mark, or clothing item visible in a photograph actually match what
        is described or photographed for a given candidate?

        Rules, strictly:
        - This is advisory triage only. Under DVI procedure, an
          identification requires fingerprints, dental records or DNA — you
          are never confirming an identity, only helping decide which
          report is worth testing first.
        - You may ONLY choose from the am_id values given to you below.
          Never invent, guess, or return an am_id that was not provided.
        - Base your ranking on physical, visual and forensic evidence only:
          marks, tattoos, scars, build, clothing, injuries, dental
          features, and how well a photograph agrees or disagrees with the
          rules engine's own text evidence for that candidate. Names are
          never evidence and are not given to you — do not reason about
          identity from anything but appearance and the evidence provided.
        - If a photograph contradicts a candidate's other evidence (for
          example, a tattoo location that does not match), say so in your
          rationale — a visual conflict is as important to report as a
          visual match.
        - Return at most 3 candidates, ranked strongest first. If none of
          the given candidates has any credible visual or evidentiary
          support, return an empty list rather than forcing three.
        - rationale must cite specific evidence (what agrees, what
          conflicts, what is simply consistent). visual_notes is only for
          what you can see in the photographs — leave it out if no photos
          were given or none showed anything relevant.
        - summary is a short (2-3 sentence) plain-language executive
          summary for a reviewer scanning quickly: which candidate the
          combined evidence most supports and why, and anything notable
          about the others (e.g. tied on evidence, or ruled down by a
          visual conflict). Write it as a standalone note, not a
          restatement of each candidate's own rationale.

        Return only the structured data described by the schema — nothing else.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return FormSchema::toGeminiSchema([
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'am_id' => ['type' => 'string'],
                            'confidence' => ['type' => 'string', 'enum' => ['high', 'moderate', 'low']],
                            'rationale' => ['type' => 'string'],
                            'visual_notes' => ['type' => 'string'],
                        ],
                        'required' => ['am_id', 'confidence', 'rationale'],
                    ],
                ],
            ],
            'required' => ['candidates'],
        ]);
    }
}
