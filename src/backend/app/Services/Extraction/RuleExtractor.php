<?php

namespace App\Services\Extraction;

use App\Models\Observation;

/**
 * Deterministic Stage-1 extractor.
 *
 * Reads English, Hindi and Marathi form boxes into the INTERPOL item codebook
 * using the lexicon, with no model call. It is the offline path — the whole
 * pipeline runs from a clean checkout with no credentials and no network — and
 * it is also the control the Granite extractor is measured against.
 *
 * Its confidences are deliberately modest: a lexicon hit on a phrase the
 * lexicon knows is worth less than a model that has read the whole sentence,
 * and anything a reviewer should look at twice is scored low enough to
 * surface in the review queue.
 */
class RuleExtractor implements ExtractorInterface
{
    public const VERSION = 'rules-v1';

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    public function extract(Observation $observation): array
    {
        $text = $observation->raw_text;

        return match ($observation->category) {
            'marks' => $this->marks($text),
            'tattoo' => $this->tattoos($text),
            'appearance' => $this->appearance($text),
            'build' => $this->build($text),
            'clothing' => $this->clothing($text),
            'jewellery' => $this->jewellery($text),
            'belongings' => $this->belongings($text),
            'id_document' => $this->idDocuments($text),
            'implant' => $this->implants($text),
            default => [],
        };
    }

    /**
     * Distinguishing marks. One clause usually describes one mark, and a box
     * may hold several ("Left back: surgery scar. Right knee: scar.").
     *
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function marks(string $text): array
    {
        if (Matcher::isNegated($text)) {
            return [$this->item(['category' => 'mark', 'negated' => true], 0.9)];
        }

        $items = [];

        foreach (Matcher::clauses($text) as $clause) {
            $region = Matcher::first($clause, Lexicon::REGION);

            // "Trunk and thighs charred; marks in these regions not assessable"
            // records that an area could not be examined at all.
            if (Matcher::isNotAssessable($clause)) {
                $items[] = $this->item([
                    'category' => 'mark',
                    'negated' => false,
                    'type' => null,
                    'region' => $region,
                    'assessable' => false,
                ], 0.75);

                continue;
            }

            $type = Matcher::first($clause, Lexicon::MARK_TYPE);

            if ($type === null) {
                continue;
            }

            $items[] = $this->item([
                'category' => 'mark',
                'negated' => false,
                'type' => $type,
                'region' => $region,
                'body_region' => $region ? (Lexicon::BODY_REGION[$region] ?? null) : null,
                'laterality' => Matcher::first($clause, Lexicon::LATERALITY),
                'size_mm' => Matcher::sizeMm($clause),
                'colour' => Matcher::first($clause, Lexicon::MARK_COLOUR),
            ], $region ? 0.8 : 0.6);
        }

        return $items;
    }

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function tattoos(string $text): array
    {
        if (Matcher::isNegated($text)) {
            return [$this->item(['category' => 'tattoo', 'negated' => true], 0.9)];
        }

        $items = [];

        foreach (Matcher::clauses($text) as $clause) {
            $notAssessable = Matcher::isNotAssessable($clause);
            $design = Matcher::first($clause, Lexicon::TATTOO_DESIGN);

            // Skip clauses that are pure scene-setting ("Forearm and upper limb
            // skin slippage") unless they carry the not-assessable finding.
            if ($design === null && ! $notAssessable) {
                continue;
            }

            $region = Matcher::first($clause, Lexicon::REGION);

            if ($notAssessable) {
                $items[] = $this->item([
                    'category' => 'tattoo',
                    'negated' => false,
                    'design' => null,
                    'region' => $region,
                    'laterality' => Matcher::first($clause, Lexicon::LATERALITY),
                    'legible' => false,
                    'assessable' => false,
                ], 0.75);

                continue;
            }

            $items[] = $this->item([
                'category' => 'tattoo',
                'negated' => false,
                'design' => $design,
                'region' => $region,
                'body_region' => $region ? (Lexicon::BODY_REGION[$region] ?? null) : null,
                'laterality' => Matcher::first($clause, Lexicon::LATERALITY),
                'legible' => true,
            ], 0.85);
        }

        return $items;
    }

    /**
     * Hair, eyes, skin tone and facial hair arrive together in one box, each
     * independently assessable — decomposition can destroy eye colour while
     * leaving hair perfectly readable.
     *
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function appearance(string $text): array
    {
        $items = [];
        $clauses = Matcher::clauses($text);

        // Attribute each clause to the feature it is about, so "Eyes not
        // assessable" marks only the eyes.
        $segmentFor = function (array $keywords) use ($clauses, $text): string {
            foreach ($clauses as $clause) {
                if (Matcher::containsAny($clause, $keywords)) {
                    return $clause;
                }
            }

            return $text;
        };

        $hairText = $segmentFor(['hair', 'केस', 'बाल']);

        // "Hair and skin not assessable (burns)" covers hair even though the
        // clause also mentions skin.
        if (Matcher::isNotAssessable($hairText) && Matcher::containsAny($hairText, ['hair', 'केस', 'बाल'])) {
            $items[] = $this->item([
                'category' => 'hair', 'colour' => null, 'length' => null, 'assessable' => false,
            ], 0.8);
        } else {
            $colour = Matcher::first($hairText, Lexicon::HAIR_COLOUR);
            $length = Matcher::first($hairText, Lexicon::HAIR_LENGTH);

            if ($colour !== null || $length !== null) {
                $items[] = $this->item([
                    'category' => 'hair', 'colour' => $colour, 'length' => $length,
                ], 0.85);
            }
        }

        $eyeText = $segmentFor(['eyes', 'eye', 'डोळे', 'आँख', 'आंख']);

        if (Matcher::isNotAssessable($eyeText)) {
            $items[] = $this->item(['category' => 'eyes', 'colour' => null, 'assessable' => false], 0.8);
        } elseif ($colour = Matcher::first($eyeText, Lexicon::EYE_COLOUR)) {
            $items[] = $this->item(['category' => 'eyes', 'colour' => $colour], 0.85);
        }

        $skinText = $segmentFor(['skin', 'complexion', 'रंग', 'त्वचा']);

        if (Matcher::isNotAssessable($skinText)) {
            $items[] = $this->item(['category' => 'skin_tone', 'value' => null, 'assessable' => false], 0.8);
        } elseif ($value = Matcher::first($skinText, Lexicon::SKIN_TONE)) {
            $items[] = $this->item(['category' => 'skin_tone', 'value' => $value], 0.8);
        }

        if ($facial = Matcher::first($text, Lexicon::FACIAL_HAIR)) {
            $items[] = $this->item(['category' => 'facial_hair', 'value' => $facial], 0.85);
        }

        return $items;
    }

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function build(string $text): array
    {
        $value = Matcher::first($text, Lexicon::BUILD);

        if ($value === null) {
            return [];
        }

        return [$this->item([
            'category' => 'build',
            'value' => $value,
            'weight_kg' => Matcher::weightKg($text),
        ], 0.85)];
    }

    /**
     * Clothing, one item per slot.
     *
     * The family's wording and the examiner's differ completely in structure
     * ("Last seen wearing blue t-shirt and red lungi" versus "Clothing
     * recovered: t-shirt (blue); lungi (sky blue)"), so colours are bound to
     * garments positionally: the colour nearest before a garment word is that
     * garment's, and a colour in parentheses after it also binds to it.
     *
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function clothing(string $text): array
    {
        $haystack = Matcher::normalise($text);
        $items = [];
        $seenSlots = [];
        $consumed = [];

        foreach (Lexicon::GARMENT as $garment => $forms) {
            $position = $this->earliestPosition($haystack, $forms, $consumed);

            if ($position === null) {
                continue;
            }

            $slot = Lexicon::GARMENT_SLOT[$garment] ?? null;

            // One garment per slot: the first mention wins, so "salwar top"
            // does not get overwritten by a later generic mention.
            if ($slot === null || isset($seenSlots[$slot])) {
                continue;
            }

            $seenSlots[$slot] = true;
            $consumed[] = $position;

            $items[] = $this->item([
                'category' => 'clothing',
                'slot' => $slot,
                'garment' => $garment,
                'colour' => $this->colourNear($haystack, $position[0], $position[1]),
            ], 0.8);
        }

        return $items;
    }

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function jewellery(string $text): array
    {
        if (Matcher::isNegated($text)) {
            return [];
        }

        $items = [];

        // Each jewellery item usually sits in its own comma-separated phrase
        // carrying its own metal and side: "silver toe ring (left foot), gold
        // earrings".
        foreach ($this->commaPhrases($text) as $phrase) {
            foreach (Lexicon::JEWELLERY as $item => $spec) {
                if (! Matcher::containsAny($phrase, $spec['forms'])) {
                    continue;
                }

                $items[] = $this->item([
                    'category' => 'jewellery',
                    'item' => $item,
                    // A watch is never precious metal in this codebook.
                    'metal' => $item === 'watch'
                        ? 'other'
                        : (Matcher::first($phrase, Lexicon::METAL) ?? 'other'),
                    'site' => $spec['site'],
                    'laterality' => Matcher::first($phrase, Lexicon::LATERALITY),
                ], 0.8);

                break;
            }
        }

        return $items;
    }

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function belongings(string $text): array
    {
        return array_map(
            fn (string $item) => $this->item(['category' => 'belonging', 'item' => $item], 0.85),
            Matcher::allInOrder($text, Lexicon::BELONGING),
        );
    }

    /**
     * Identity documents.
     *
     * Recorded, but weighted as weak evidence downstream: a card found on a
     * body says where the card was, not who the body is.
     *
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function idDocuments(string $text): array
    {
        $kind = Matcher::first($text, Lexicon::ID_KIND);

        if ($kind === null) {
            return [];
        }

        preg_match('/ending\s+(\d{3,4})/i', $text, $digits);
        preg_match('/name\s+([^,.;]+)/i', $text, $name);

        // "found in trouser/shirt pocket" is the examiner recording recovery
        // from the body; the family's "carried a card" is not.
        $onBody = Matcher::containsAny($text, ['found in', 'found on', 'recovered from', 'pocket'])
            ? true
            : (Matcher::containsAny($text, ['carried', 'had with']) ? null : null);

        return [$this->item([
            'category' => 'id_document',
            'kind' => $kind,
            'name' => isset($name[1]) ? trim($name[1]) : null,
            'id_last4' => $digits[1] ?? null,
            'on_body' => $onBody,
        ], 0.85)];
    }

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    protected function implants(string $text): array
    {
        $items = [];

        foreach (Matcher::clauses($text) as $clause) {
            foreach (Lexicon::IMPLANT as $type => $spec) {
                if (! Matcher::containsAny($clause, $spec['forms'])) {
                    continue;
                }

                // A serial may be stated in a following clause ("... Device
                // serial SN-650703."), so search the whole box for it.
                preg_match('/\b(SN-[A-Z0-9]+)\b/i', $text, $serial);

                $items[] = $this->item([
                    'category' => 'implant',
                    'type' => $type,
                    'region' => Matcher::first($clause, Lexicon::REGION) ?? $spec['region'],
                    'laterality' => Matcher::first($clause, Lexicon::LATERALITY),
                    'serial' => isset($serial[1]) ? strtoupper($serial[1]) : null,
                ], 0.9);

                break 2;
            }
        }

        return $items;
    }

    /**
     * Earliest [start, end] offset at which any of these surface forms occurs,
     * ignoring spans already claimed by a more specific garment.
     *
     * Without the exclusion, "salwar top" would also be read as a "salwar" —
     * one garment becoming two, in two different slots.
     *
     * @param  list<string>  $forms
     * @param  list<array{0: int, 1: int}>  $consumed
     * @return array{0: int, 1: int}|null
     */
    protected function earliestPosition(string $haystack, array $forms, array $consumed = []): ?array
    {
        $best = null;

        foreach ($forms as $form) {
            $needle = Matcher::normalise($form);
            $offset = 0;

            // Walk every occurrence: an earlier one may sit inside a consumed
            // span while a later one is free.
            while (($at = mb_strpos($haystack, $needle, $offset)) !== false) {
                $span = [$at, $at + mb_strlen($needle)];
                $offset = $at + 1;

                if ($this->overlaps($span, $consumed)) {
                    continue;
                }

                if ($best === null || $span[0] < $best[0]) {
                    $best = $span;
                }

                break;
            }
        }

        return $best;
    }

    /**
     * @param  array{0: int, 1: int}  $span
     * @param  list<array{0: int, 1: int}>  $spans
     */
    protected function overlaps(array $span, array $spans): bool
    {
        foreach ($spans as $other) {
            if ($span[0] < $other[1] && $other[0] < $span[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The colour belonging to a garment at [$start, $end].
     *
     * Prefers a colour in parentheses immediately after the garment (the PM
     * form's style), then the nearest colour before it within the same clause
     * (the family's style). Colours further away than that belong to a
     * different garment and are left alone — a wrong colour is worse than a
     * missing one, because the scorer would read it as a conflict.
     */
    protected function colourNear(string $haystack, int $start, int $end): ?string
    {
        $after = mb_substr($haystack, $end, 24);

        if (preg_match('/^\s*\(([^)]+)\)/u', $after, $m)) {
            if ($colour = Matcher::first($m[1], Lexicon::CLOTHING_COLOUR)) {
                return $colour;
            }
        }

        // Look back only as far as the start of this garment's own clause: a
        // colour belonging to the previous garment must not leak forward.
        $before = mb_substr($haystack, 0, $start);
        $clauseStart = 0;

        foreach ([';', ',', ':', '.', ' and ', ' ani ', ' aur ', ' with '] as $separator) {
            $at = mb_strrpos($before, $separator);

            // mb_strrpos returns false when absent; coercing that to 0 and
            // adding one would silently eat the first character of the text,
            // losing a colour that begins the sentence.
            if ($at !== false) {
                $clauseStart = max($clauseStart, $at + mb_strlen($separator));
            }
        }

        return $this->lastColourIn(mb_substr($before, $clauseStart));
    }

    /**
     * The colour closest to the end of a window — "blue t-shirt and red lungi"
     * binds "red" to the lungi, not "blue".
     */
    protected function lastColourIn(string $window): ?string
    {
        $best = null;
        $bestAt = -1;

        foreach (Lexicon::CLOTHING_COLOUR as $colour => $forms) {
            foreach ($forms as $form) {
                $at = mb_strrpos($window, Matcher::normalise($form));

                if ($at !== false && $at > $bestAt) {
                    $bestAt = $at;
                    $best = $colour;
                }
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    protected function commaPhrases(string $text): array
    {
        // Strip the leading label ("Wearing:", "Jewellery/accessories recovered:").
        $text = preg_replace('/^[^:]{0,60}:/u', '', $text) ?? $text;

        $parts = preg_split('/[,;.।]+(?![^(]*\))/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{item: array<string, mixed>, confidence: float}
     */
    protected function item(array $item, float $confidence): array
    {
        return ['item' => $item, 'confidence' => $confidence];
    }
}
