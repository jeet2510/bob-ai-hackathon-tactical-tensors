<?php

namespace App\Services\Matching;

use App\Models\AmFile;
use App\Models\MatchEvidence;
use App\Models\PmCase;

/**
 * Stage 2 — weigh one body against one missing-person report, category by
 * category, and say why.
 *
 * Every point of score is attributable to a named finding with a sentence a
 * reviewer can read and a form box they can open. Nothing is averaged; a
 * conflict stays visible as a conflict even when twenty other things agree.
 *
 * Three distinctions run through all of it and are the reason the output can
 * be trusted:
 *
 *   - *Missing* is not *conflicting*. A feature one side never recorded is
 *     silence, and silence is scored as zero, not as disagreement.
 *   - *Unassessable* is not *absent*. A trunk too badly burnt to examine tells
 *     you nothing about whether it bore a scar.
 *   - A family's error is not a person's difference. Relatives misremember
 *     clothing and flip left for right, so conflicts on soft features are
 *     weighted a fraction of what agreements are worth.
 */
class EvidenceScorer
{
    public const VERSION = 'evidence-v1';

    /** Height agreement within this many cm is treated as agreement. */
    protected const HEIGHT_MATCH_CM = 5;

    /** Beyond this, a reported height and a measured one genuinely disagree. */
    protected const HEIGHT_CONFLICT_CM = 10;

    /**
     * Categories compared as sets of findings, where a record may hold several.
     */
    protected const SET_CATEGORIES = [
        'mark', 'tattoo', 'clothing', 'jewellery', 'belonging', 'implant', 'id_document',
    ];

    /**
     * Categories where a record holds at most one value.
     */
    protected const SINGLE_CATEGORIES = ['hair', 'eyes', 'skin_tone', 'build', 'facial_hair'];

    /**
     * @return list<array<string, mixed>> evidence rows, ready to persist
     */
    public function score(PmCase $pm, AmFile $am, RecordItems $pmItems, RecordItems $amItems): array
    {
        $evidence = [];

        foreach ($this->scalarEvidence($pm, $am) as $row) {
            $evidence[] = $row;
        }

        $dental = DentalChart::compare($pm->dentalChart(), $am->dentalChart());

        if ($dental !== null) {
            $evidence[] = $this->row('dental', $dental['verdict'], $dental['llr'], $dental['rationale']);
        }

        foreach (self::SET_CATEGORIES as $category) {
            foreach ($this->setEvidence($category, $pmItems, $amItems, $am) as $row) {
                $evidence[] = $row;
            }
        }

        foreach (self::SINGLE_CATEGORIES as $category) {
            $row = $this->singleEvidence($category, $pmItems, $amItems);

            if ($row !== null) {
                $evidence[] = $row;
            }
        }

        return $evidence;
    }

    /**
     * Sex, age and height, taken from the form headers rather than free text.
     *
     * @return list<array<string, mixed>>
     */
    protected function scalarEvidence(PmCase $pm, AmFile $am): array
    {
        $rows = [];

        if ($pm->sex && $am->sex) {
            $same = $pm->sex === $am->sex;

            $rows[] = $this->row(
                'sex',
                $same ? MatchEvidence::VERDICT_MATCH : MatchEvidence::VERDICT_CONFLICT,
                $same ? EvidenceWeights::match('sex') : EvidenceWeights::conflict('sex'),
                $same
                    ? "Sex agrees ({$pm->sex})."
                    : "Sex differs: {$pm->sex} post-mortem against {$am->sex} reported.",
            );
        }

        if ($am->age !== null && ($pm->age_min !== null || $pm->age_max !== null)) {
            $min = $pm->age_min ?? $pm->age_max;
            $max = $pm->age_max ?? $pm->age_min;

            if ($am->age >= $min && $am->age <= $max) {
                $rows[] = $this->row(
                    'age',
                    MatchEvidence::VERDICT_MATCH,
                    EvidenceWeights::match('age'),
                    "Reported age {$am->age} falls within the estimated range {$min}–{$max}.",
                );
            } else {
                $gap = $am->age < $min ? $min - $am->age : $am->age - $max;

                // Skeletal age estimation is a range with soft edges; a couple
                // of years outside it is routine, not disqualifying.
                $llr = $gap <= 3
                    ? 0.0
                    : EvidenceWeights::conflict('age') * min(1.0, ($gap - 3) / 7);

                $rows[] = $this->row(
                    'age',
                    $gap <= 3 ? MatchEvidence::VERDICT_MISSING : MatchEvidence::VERDICT_CONFLICT,
                    $llr,
                    "Reported age {$am->age} lies {$gap} year(s) outside the estimated range {$min}–{$max}.",
                );
            }
        }

        if ($pm->height_cm && $am->height_cm_reported) {
            $gap = abs($pm->height_cm - $am->height_cm_reported);
            $stated = $am->height_text ? " (family stated {$am->height_text})" : '';

            if ($gap <= self::HEIGHT_MATCH_CM) {
                $rows[] = $this->row('height', MatchEvidence::VERDICT_MATCH, EvidenceWeights::match('height'),
                    "Height agrees within {$gap} cm: {$pm->height_cm} cm measured, {$am->height_cm_reported} cm reported{$stated}.");
            } elseif ($gap <= self::HEIGHT_CONFLICT_CM) {
                $rows[] = $this->row('height', MatchEvidence::VERDICT_MATCH, EvidenceWeights::partial('height'),
                    "Height close: {$gap} cm apart ({$pm->height_cm} cm measured, {$am->height_cm_reported} cm reported{$stated}).");
            } else {
                $rows[] = $this->row('height', MatchEvidence::VERDICT_CONFLICT,
                    EvidenceWeights::conflict('height') * min(1.0, ($gap - self::HEIGHT_CONFLICT_CM) / 10),
                    "Height differs by {$gap} cm ({$pm->height_cm} cm measured, {$am->height_cm_reported} cm reported{$stated}).");
            }
        }

        return $rows;
    }

    /**
     * Categories holding a set of findings.
     *
     * @return list<array<string, mixed>>
     */
    protected function setEvidence(string $category, RecordItems $pmItems, RecordItems $amItems, AmFile $am): array
    {
        // The examiner could not look. This is the single most important
        // branch in the scorer: it must produce "no information", never
        // "nothing found".
        if ($pmItems->isCategoryUnassessable($category)) {
            return [$this->row($category, MatchEvidence::VERDICT_EXCLUDED, 0.0,
                ucfirst($category).' could not be assessed post-mortem; this pairing is neither supported nor weakened by it.')];
        }

        $pmSet = $pmItems->comparable($category);
        $amSet = $amItems->comparable($category);

        // Both sides state the feature is absent. Real, if modest, agreement.
        if ($pmSet === [] && $amSet === []) {
            if ($pmItems->isCategoryNegated($category) && $amItems->isCategoryNegated($category)) {
                return [$this->row($category, MatchEvidence::VERDICT_MATCH, EvidenceWeights::NEGATIVE_AGREEMENT,
                    'Both records state there are none.')];
            }

            return [];
        }

        if ($pmSet === [] || $amSet === []) {
            // One side has findings, the other explicitly none.
            if ($pmSet !== [] && $amItems->isCategoryNegated($category)) {
                return [$this->row($category, MatchEvidence::VERDICT_CONFLICT,
                    EvidenceWeights::conflict($category),
                    'Found post-mortem, but the family reported none — families are often unaware of small findings.')];
            }

            return [$this->row($category, MatchEvidence::VERDICT_MISSING, 0.0, 'Recorded on only one side.')];
        }

        return $this->pairSets($category, $pmSet, $amSet, $am);
    }

    /**
     * Greedily pair each post-mortem finding with its best ante-mortem
     * counterpart, strongest pairings first.
     *
     * @param  list<array<string, mixed>>  $pmSet
     * @param  list<array<string, mixed>>  $amSet
     * @return list<array<string, mixed>>
     */
    protected function pairSets(string $category, array $pmSet, array $amSet, AmFile $am): array
    {
        $scored = [];

        foreach ($pmSet as $p => $pmEntry) {
            foreach ($amSet as $a => $amEntry) {
                $comparison = $this->compareItems($category, $pmEntry['item'], $amEntry['item'], $am);

                if ($comparison !== null) {
                    $scored[] = ['p' => $p, 'a' => $a] + $comparison;
                }
            }
        }

        usort($scored, fn ($x, $y) => $y['llr'] <=> $x['llr']);

        $rows = [];
        $usedPm = [];
        $usedAm = [];

        foreach ($scored as $pairing) {
            if (isset($usedPm[$pairing['p']]) || isset($usedAm[$pairing['a']])) {
                continue;
            }

            $usedPm[$pairing['p']] = true;
            $usedAm[$pairing['a']] = true;

            $rows[] = $this->row(
                $pairing['category'] ?? $category,
                $pairing['verdict'],
                $pairing['llr'],
                $pairing['rationale'],
                $pmSet[$pairing['p']]['obs_id'],
                $amSet[$pairing['a']]['obs_id'],
                $pmSet[$pairing['p']]['item'],
                $amSet[$pairing['a']]['item'],
            );
        }

        /*
         * Findings left unpaired are recorded but never charged against the
         * pairing. A family cannot be expected to know about a two-millimetre
         * mole, and a body recovered from a landslide may have lost the shoe
         * they remember. Treating either as evidence of difference is how a
         * correct identification gets rejected.
         */
        $unpaired = (count($pmSet) - count($usedPm)) + (count($amSet) - count($usedAm));

        if ($unpaired > 0) {
            $rows[] = $this->row($category, MatchEvidence::VERDICT_MISSING, 0.0,
                "{$unpaired} further ".str($category)->plural($unpaired).' on one side with no counterpart; not counted either way.');
        }

        return $rows;
    }

    /**
     * How two findings of the same category relate.
     *
     * @param  array<string, mixed>  $pm
     * @param  array<string, mixed>  $amItem
     * @return array{verdict: string, llr: float, rationale: string, category?: string}|null
     */
    protected function compareItems(string $category, array $pm, array $amItem, AmFile $am): ?array
    {
        return match ($category) {
            'mark' => $this->compareMark($pm, $amItem),
            'tattoo' => $this->compareTattoo($pm, $amItem),
            'clothing' => $this->compareClothing($pm, $amItem),
            'jewellery' => $this->compareJewellery($pm, $amItem),
            'belonging' => $this->compareBelonging($pm, $amItem),
            'implant' => $this->compareImplant($pm, $amItem),
            'id_document' => $this->compareIdDocument($pm, $amItem, $am),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $pm
     * @param  array<string, mixed>  $am
     * @return array{verdict: string, llr: float, rationale: string}|null
     */
    protected function compareMark(array $pm, array $am): ?array
    {
        if (($pm['type'] ?? null) !== ($am['type'] ?? null) || $pm['type'] === null) {
            return null;
        }

        if (($pm['region'] ?? null) !== ($am['region'] ?? null) || $pm['region'] === null) {
            return null;
        }

        $label = str_replace('_', ' ', (string) $pm['type'])." on the {$pm['region']}";
        $pmSide = $pm['laterality'] ?? null;
        $amSide = $am['laterality'] ?? null;

        if ($pmSide !== null && $amSide !== null && $pmSide !== $amSide) {
            /*
             * Same mark, opposite side. Relatives mirror left and right
             * routinely — picture yourself facing someone and describing their
             * scar. It is recorded honestly as a conflict so a reviewer sees
             * it, but weighted far too lightly to reject a true match on.
             */
            return [
                'verdict' => MatchEvidence::VERDICT_CONFLICT,
                'llr' => EvidenceWeights::conflict('mark'),
                'rationale' => "{$label} on both records, but sides disagree ({$amSide} reported, {$pmSide} found) — commonly mirrored in family accounts.",
            ];
        }

        if ($pmSide === null || $amSide === null) {
            return [
                'verdict' => MatchEvidence::VERDICT_MATCH,
                'llr' => EvidenceWeights::partial('mark'),
                'rationale' => "{$label} on both records; side recorded on only one.",
            ];
        }

        return [
            'verdict' => MatchEvidence::VERDICT_MATCH,
            'llr' => EvidenceWeights::match('mark'),
            'rationale' => "{$label}, {$pmSide} side, on both records.",
        ];
    }

    /**
     * @param  array<string, mixed>  $pm
     * @param  array<string, mixed>  $am
     * @return array{verdict: string, llr: float, rationale: string}|null
     */
    protected function compareTattoo(array $pm, array $am): ?array
    {
        $pmDesign = $pm['design'] ?? null;
        $amDesign = $am['design'] ?? null;

        if ($pmDesign === null || $amDesign === null) {
            return null;
        }

        $design = str_replace('_', ' ', (string) $amDesign);

        if ($pmDesign !== $amDesign) {
            return [
                'verdict' => MatchEvidence::VERDICT_CONFLICT,
                'llr' => EvidenceWeights::conflict('tattoo'),
                'rationale' => 'Different tattoo designs: '.str_replace('_', ' ', (string) $pmDesign)." found, {$design} reported.",
            ];
        }

        $sameRegion = ($pm['region'] ?? null) === ($am['region'] ?? null) && ($pm['region'] ?? null) !== null;
        $pmSide = $pm['laterality'] ?? null;
        $amSide = $am['laterality'] ?? null;

        if ($sameRegion && $pmSide !== null && $amSide !== null && $pmSide === $amSide) {
            return [
                'verdict' => MatchEvidence::VERDICT_MATCH,
                'llr' => EvidenceWeights::match('tattoo'),
                'rationale' => "Tattoo '{$design}' on the {$pmSide} {$pm['region']} on both records.",
            ];
        }

        return [
            'verdict' => MatchEvidence::VERDICT_MATCH,
            'llr' => EvidenceWeights::partial('tattoo'),
            'rationale' => "Tattoo '{$design}' on both records; site details differ or are incomplete.",
        ];
    }

    /**
     * @param  array<string, mixed>  $pm
     * @param  array<string, mixed>  $am
     * @return array{verdict: string, llr: float, rationale: string}|null
     */
    protected function compareClothing(array $pm, array $am): ?array
    {
        if (($pm['slot'] ?? null) !== ($am['slot'] ?? null) || ($pm['slot'] ?? null) === null) {
            return null;
        }

        $slot = $pm['slot'];
        $sameGarment = ($pm['garment'] ?? null) === ($am['garment'] ?? null);
        $pmColour = $pm['colour'] ?? null;
        $amColour = $am['colour'] ?? null;
        $sameColour = $pmColour !== null && $pmColour === $amColour;

        if ($sameGarment && $sameColour) {
            return [
                'verdict' => MatchEvidence::VERDICT_MATCH,
                'llr' => EvidenceWeights::match('clothing'),
                'rationale' => "{$slot}: {$pmColour} {$pm['garment']} on both records.",
            ];
        }

        if ($sameGarment && ($pmColour === null || $amColour === null)) {
            return [
                'verdict' => MatchEvidence::VERDICT_MATCH,
                'llr' => EvidenceWeights::partial('clothing'),
                'rationale' => "{$slot}: {$pm['garment']} on both records; colour recorded on only one.",
            ];
        }

        if ($sameGarment) {
            /*
             * The garment agrees and only the colour does not.
             *
             * This is still evidence *for* the pairing, and reading it as a
             * conflict was the single biggest source of wrong top-ranked
             * answers. Colour is the most misremembered detail there is —
             * around a fifth of families in this dataset give the wrong one —
             * whereas garment type is recalled reliably and there are more
             * garments than colours to confuse. A relative who says "kurta and
             * salwar" and is wrong about the shade has still told you
             * something true.
             */
            return [
                'verdict' => MatchEvidence::VERDICT_MATCH,
                'llr' => EvidenceWeights::partial('clothing'),
                'rationale' => "{$slot}: {$pm['garment']} on both records, though colour differs ({$amColour} reported, {$pmColour} recovered) — colour is commonly misremembered.",
            ];
        }

        if ($sameColour) {
            // Sharing a colour across different garments is close to
            // coincidence: there are only a handful of colours in use.
            return [
                'verdict' => MatchEvidence::VERDICT_MATCH,
                'llr' => EvidenceWeights::partial('clothing') * 0.3,
                'rationale' => "{$slot}: both {$pmColour}, but garment differs ({$am['garment']} reported, {$pm['garment']} recovered).",
            ];
        }

        return [
            'verdict' => MatchEvidence::VERDICT_CONFLICT,
            'llr' => EvidenceWeights::conflict('clothing'),
            'rationale' => "{$slot}: neither garment nor colour agrees ({$amColour} {$am['garment']} reported, {$pmColour} {$pm['garment']} recovered).",
        ];
    }

    /**
     * @param  array<string, mixed>  $pm
     * @param  array<string, mixed>  $am
     * @return array{verdict: string, llr: float, rationale: string}|null
     */
    protected function compareJewellery(array $pm, array $am): ?array
    {
        if (($pm['item'] ?? null) !== ($am['item'] ?? null) || ($pm['item'] ?? null) === null) {
            return null;
        }

        $item = str_replace('_', ' ', (string) $pm['item']);
        $sameMetal = ($pm['metal'] ?? null) === ($am['metal'] ?? null);
        $metal = str_replace('_', ' ', (string) ($pm['metal'] ?? 'unspecified'));

        return [
            'verdict' => MatchEvidence::VERDICT_MATCH,
            'llr' => $sameMetal ? EvidenceWeights::match('jewellery') : EvidenceWeights::partial('jewellery'),
            'rationale' => $sameMetal
                ? "{$metal} {$item} on both records."
                : "{$item} on both records; described as different metals.",
        ];
    }

    /**
     * @param  array<string, mixed>  $pm
     * @param  array<string, mixed>  $am
     * @return array{verdict: string, llr: float, rationale: string}|null
     */
    protected function compareBelonging(array $pm, array $am): ?array
    {
        if (($pm['item'] ?? null) !== ($am['item'] ?? null) || ($pm['item'] ?? null) === null) {
            return null;
        }

        $item = str_replace('_', ' ', (string) $pm['item']);

        return [
            'verdict' => MatchEvidence::VERDICT_MATCH,
            'llr' => EvidenceWeights::match('belonging'),
            'rationale' => ucfirst($item).' recorded on both records.',
        ];
    }

    /**
     * @param  array<string, mixed>  $pm
     * @param  array<string, mixed>  $am
     * @return array{verdict: string, llr: float, rationale: string, category?: string}|null
     */
    protected function compareImplant(array $pm, array $am): ?array
    {
        $pmSerial = $pm['serial'] ?? null;
        $amSerial = $am['serial'] ?? null;

        // A device serial recovered from the body and present in the family's
        // medical paperwork is as close to unique as secondary evidence gets.
        if ($pmSerial !== null && $amSerial !== null) {
            $same = strcasecmp((string) $pmSerial, (string) $amSerial) === 0;

            return [
                'category' => 'implant_serial',
                'verdict' => $same ? MatchEvidence::VERDICT_MATCH : MatchEvidence::VERDICT_CONFLICT,
                'llr' => $same ? EvidenceWeights::match('implant_serial') : EvidenceWeights::conflict('implant_serial'),
                'rationale' => $same
                    ? "Implant device serial {$pmSerial} matches the ante-mortem record exactly."
                    : "Implant serials differ ({$amSerial} on file, {$pmSerial} recovered).",
            ];
        }

        if (($pm['type'] ?? null) !== ($am['type'] ?? null) || ($pm['type'] ?? null) === null) {
            return null;
        }

        $type = str_replace('_', ' ', (string) $pm['type']);

        return [
            'verdict' => MatchEvidence::VERDICT_MATCH,
            'llr' => EvidenceWeights::match('implant'),
            'rationale' => ucfirst($type).' recorded on both records.',
        ];
    }

    /**
     * Identity documents — recorded as a lead, weighted as almost nothing.
     *
     * @param  array<string, mixed>  $pm
     * @param  array<string, mixed>  $am
     * @return array{verdict: string, llr: float, rationale: string}|null
     */
    protected function compareIdDocument(array $pm, array $am, AmFile $amFile): ?array
    {
        $pmLast4 = $pm['id_last4'] ?? null;
        $amLast4 = $am['id_last4'] ?? null;

        if ($pmLast4 === null || $amLast4 === null || $pmLast4 !== $amLast4) {
            return null;
        }

        $name = $pm['name'] ?? null;

        /*
         * The name on a recovered document is never scored, and when it
         * disagrees with the missing-person report the discrepancy is put in
         * front of the reviewer rather than folded into a number. A card can
         * be carried, lent, or swept up beside the wrong body.
         */
        $warning = ($name !== null && $amFile->reported_name !== null
            && strcasecmp(trim((string) $name), trim($amFile->reported_name)) !== 0)
            ? " Note: the card names {$name}, not {$amFile->reported_name} — documents travel, and this is a lead to check, not evidence of identity."
            : '';

        return [
            'verdict' => MatchEvidence::VERDICT_MATCH,
            'llr' => EvidenceWeights::match('id_document'),
            'rationale' => "Identity document ending {$pmLast4} appears on both records; weighted as a lead only.{$warning}",
        ];
    }

    /**
     * Categories holding a single value per record.
     *
     * @return array<string, mixed>|null
     */
    protected function singleEvidence(string $category, RecordItems $pmItems, RecordItems $amItems): ?array
    {
        if ($pmItems->isCategoryUnassessable($category)) {
            return $this->row($category, MatchEvidence::VERDICT_EXCLUDED, 0.0,
                ucfirst(str_replace('_', ' ', $category)).' could not be assessed post-mortem.');
        }

        $pmSet = $pmItems->comparable($category);
        $amSet = $amItems->comparable($category);

        if ($pmSet === [] || $amSet === []) {
            return null;
        }

        $pm = $pmSet[0]['item'];
        $am = $amSet[0]['item'];
        $label = str_replace('_', ' ', $category);

        if ($category === 'hair') {
            $sameColour = ($pm['colour'] ?? null) === ($am['colour'] ?? null) && ($pm['colour'] ?? null) !== null;
            $sameLength = ($pm['length'] ?? null) === ($am['length'] ?? null) && ($pm['length'] ?? null) !== null;

            if ($sameColour && $sameLength) {
                return $this->row($category, MatchEvidence::VERDICT_MATCH, EvidenceWeights::match('hair'),
                    "Hair agrees: {$pm['colour']}, {$pm['length']}.", $pmSet[0]['obs_id'], $amSet[0]['obs_id'], $pm, $am);
            }

            if ($sameColour || $sameLength) {
                return $this->row($category, MatchEvidence::VERDICT_MATCH, EvidenceWeights::partial('hair'),
                    'Hair partly agrees: '.($sameColour ? "colour {$pm['colour']}" : "length {$pm['length']}").'.',
                    $pmSet[0]['obs_id'], $amSet[0]['obs_id'], $pm, $am);
            }

            return $this->row($category, MatchEvidence::VERDICT_CONFLICT, EvidenceWeights::conflict('hair'),
                "Hair differs: {$am['colour']}/{$am['length']} reported, {$pm['colour']}/{$pm['length']} found.",
                $pmSet[0]['obs_id'], $amSet[0]['obs_id'], $pm, $am);
        }

        $field = $category === 'eyes' ? 'colour' : 'value';
        $pmValue = $pm[$field] ?? null;
        $amValue = $am[$field] ?? null;

        if ($pmValue === null || $amValue === null) {
            return null;
        }

        $same = $pmValue === $amValue;

        return $this->row(
            $category,
            $same ? MatchEvidence::VERDICT_MATCH : MatchEvidence::VERDICT_CONFLICT,
            $same ? EvidenceWeights::match($category) : EvidenceWeights::conflict($category),
            $same
                ? ucfirst($label).' agrees ('.str_replace('_', ' ', (string) $pmValue).').'
                : ucfirst($label).' differs ('.str_replace('_', ' ', (string) $amValue).' reported, '.str_replace('_', ' ', (string) $pmValue).' found).',
            $pmSet[0]['obs_id'],
            $amSet[0]['obs_id'],
            $pm,
            $am,
        );
    }

    /**
     * @param  array<string, mixed>|null  $pmItem
     * @param  array<string, mixed>|null  $amItem
     * @return array<string, mixed>
     */
    protected function row(
        string $category,
        string $verdict,
        float $llr,
        string $rationale,
        ?string $pmObsId = null,
        ?string $amObsId = null,
        ?array $pmItem = null,
        ?array $amItem = null,
    ): array {
        return [
            'category' => $category,
            'tier' => EvidenceWeights::tierFor($category),
            'verdict' => $verdict,
            'llr' => round($llr, 3),
            'rationale' => $rationale,
            'pm_obs_id' => $pmObsId,
            'am_obs_id' => $amObsId,
            'pm_item' => $pmItem,
            'am_item' => $amItem,
        ];
    }
}
