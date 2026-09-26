<?php

namespace App\Services\Matching;

use App\Models\Candidate;
use App\Models\MatchEvidence;

/**
 * Turns a list of evidence rows into a score and a confidence band.
 *
 * Two rules here do most of the work of keeping the output honest.
 *
 * First, supportive evidence is capped. Sex, age, height, build, hair, eyes
 * and skin tone are each individually weak — most people in a given population
 * are of medium build with brown eyes — and a record that happens to have more
 * of those fields filled in should not be able to out-accumulate one that
 * matches on a scar. INTERPOL is explicit that secondary and supportive
 * features cannot establish identity; the cap encodes that rather than leaving
 * it to the arithmetic.
 *
 * Second, a pairing with no individuating evidence is not a candidate at all,
 * whatever it scores. Agreeing that a body is a man in his thirties of medium
 * build describes thousands of people. Calling that a "low confidence match"
 * would invite a reviewer to treat a demographic coincidence as a lead.
 */
class EvidenceTotal
{
    /**
     * Ceiling on the summed contribution of Tier-C findings.
     *
     * Roughly: general description can take a pairing as far as "worth
     * looking at", never further.
     */
    public const TIER_C_CAP = 12.0;

    /**
     * Full-outfit agreement is Tier C, but several slots agreeing on both
     * garment and colour is specific enough to count as a genuine lead.
     */
    protected const CLOTHING_MATCHES_FOR_LEAD = 2;

    public function __construct(
        public readonly float $score,
        public readonly string $band,
        public readonly int $coverage,
        public readonly bool $hasIndividuating,
        public readonly float $tierCRaw,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $evidence
     */
    public static function of(array $evidence): self
    {
        $strong = 0.0;
        $supportive = 0.0;
        $informative = 0;
        $hasStrongMatch = false;
        $clothingMatches = 0;
        $idDocumentMatch = false;

        foreach ($evidence as $row) {
            $llr = (float) $row['llr'];
            $isMatch = $row['verdict'] === MatchEvidence::VERDICT_MATCH;

            if (in_array($row['verdict'], [MatchEvidence::VERDICT_MATCH, MatchEvidence::VERDICT_CONFLICT], true)
                && abs($llr) > 0.0) {
                $informative++;
            }

            if (in_array($row['tier'], [EvidenceWeights::TIER_A, EvidenceWeights::TIER_B], true)) {
                $strong += $llr;
                $hasStrongMatch = $hasStrongMatch || ($isMatch && $llr > 0);
            } else {
                $supportive += $llr;

                if ($isMatch && $row['category'] === 'clothing' && $llr >= EvidenceWeights::match('clothing')) {
                    $clothingMatches++;
                }

                if ($isMatch && $row['category'] === 'id_document') {
                    $idDocumentMatch = true;
                }
            }
        }

        $score = $strong + self::dampen($supportive);

        $individuating = $hasStrongMatch
            || $clothingMatches >= self::CLOTHING_MATCHES_FOR_LEAD
            || $idDocumentMatch;

        return new self(
            round($score, 3),
            self::bandFor($score, $individuating, $hasStrongMatch),
            $informative,
            $individuating,
            round($supportive, 3),
        );
    }

    /**
     * Diminishing returns on supportive evidence, approaching but never
     * reaching the cap.
     *
     * A hard ceiling was tried first and was worse: once several pairings hit
     * the ceiling they scored identically, and the ordering among them — which
     * is exactly what Recall@3 measures — collapsed. Damping keeps the
     * ordering intact, so more agreement still ranks higher, while ensuring no
     * quantity of general description can ever add up to a scar.
     *
     * Only the positive side is damped. Conflicting supportive evidence counts
     * in full, because a system that muted disagreement would be telling
     * reviewers what they want to hear.
     */
    protected static function dampen(float $supportive): float
    {
        if ($supportive <= 0) {
            return $supportive;
        }

        return self::TIER_C_CAP * tanh($supportive / self::TIER_C_CAP);
    }

    protected static function bandFor(float $score, bool $individuating, bool $hasStrongMatch): string
    {
        if (! $individuating) {
            return Candidate::BAND_NONE;
        }

        return match (true) {
            $score >= EvidenceWeights::BAND_HIGH && $hasStrongMatch => Candidate::BAND_HIGH,
            $score >= EvidenceWeights::BAND_MODERATE => Candidate::BAND_MODERATE,
            $score >= EvidenceWeights::BAND_LOW => Candidate::BAND_LOW,
            default => Candidate::BAND_NONE,
        };
    }
}
