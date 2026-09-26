<?php

namespace App\Services\Matching;

/**
 * Compares two FDI dental charts.
 *
 * Dentition is a primary identifier: restorations are effectively a record of
 * one person's dental history, and a chart recorded post-mortem can be set
 * against a chart from the family's dentist. This is the only comparison in
 * the system strong enough to settle a case on its own, and it is what
 * separates the near-identical decoy pairs that every other feature ties on.
 *
 * Charts are {"missing":[13,16],"filled":[36,43],"crown":[32],"root_canal":[]}
 * keyed by FDI tooth number.
 */
class DentalChart
{
    /**
     * Treatment states compared. A tooth's state is the evidence; a tooth
     * absent from every list is simply unrecorded.
     */
    protected const STATES = ['missing', 'filled', 'crown', 'root_canal'];

    /**
     * Below this many recorded teeth on either side, a chart is too sparse to
     * carry a primary-identifier verdict on its own.
     */
    protected const MIN_TEETH = 2;

    /**
     * @param  array<string, list<int>>|null  $pm
     * @param  array<string, list<int>>|null  $am
     * @return array{
     *     verdict: string,
     *     llr: float,
     *     agreements: int,
     *     disagreements: int,
     *     compared: int,
     *     rationale: string
     * }|null
     */
    public static function compare(?array $pm, ?array $am): ?array
    {
        if ($pm === null || $am === null) {
            return null;
        }

        $pmTeeth = self::byTooth($pm);
        $amTeeth = self::byTooth($am);

        if (count($pmTeeth) < self::MIN_TEETH || count($amTeeth) < self::MIN_TEETH) {
            return null;
        }

        // Only teeth charted on both sides carry information. A tooth the
        // examiner recorded but the family's dentist never saw is not a
        // discrepancy, and must not be scored as one.
        $shared = array_intersect_key($pmTeeth, $amTeeth);

        if ($shared === []) {
            return [
                'verdict' => 'missing',
                'llr' => 0.0,
                'agreements' => 0,
                'disagreements' => 0,
                'compared' => 0,
                'rationale' => 'Both charts exist but describe no tooth in common.',
            ];
        }

        $agree = 0;
        $disagree = 0;
        $disagreeing = [];

        foreach ($shared as $tooth => $pmState) {
            if ($pmState === $amTeeth[$tooth]) {
                $agree++;
            } else {
                $disagree++;
                $disagreeing[] = "{$tooth} ({$amTeeth[$tooth]} ante-mortem, {$pmState} post-mortem)";
            }
        }

        $compared = $agree + $disagree;
        $weight = EvidenceWeights::match('dental');

        /*
         * A single contradicted tooth is not disqualifying: charts are
         * transcribed by hand, and treatment can happen between the family's
         * last record and death. Several contradictions are another matter.
         */
        if ($disagree > 1 && $disagree >= $agree) {
            return [
                'verdict' => 'conflict',
                'llr' => EvidenceWeights::conflict('dental'),
                'agreements' => $agree,
                'disagreements' => $disagree,
                'compared' => $compared,
                'rationale' => sprintf(
                    'Dental charts contradict on %d of %d shared teeth: %s.',
                    $disagree,
                    $compared,
                    implode('; ', array_slice($disagreeing, 0, 3)),
                ),
            ];
        }

        // Scale with how much of the chart actually agreed: four concordant
        // restorations is a far stronger claim than one.
        $strength = min(1.0, $agree / 4);
        $llr = ($weight * $strength) + ($disagree * EvidenceWeights::conflict('dental') * 0.15);

        if ($agree === 0) {
            return [
                'verdict' => 'conflict',
                'llr' => EvidenceWeights::conflict('dental') * 0.5,
                'agreements' => 0,
                'disagreements' => $disagree,
                'compared' => $compared,
                'rationale' => sprintf('No shared tooth agrees across %d compared.', $compared),
            ];
        }

        return [
            'verdict' => 'match',
            'llr' => round($llr, 3),
            'agreements' => $agree,
            'disagreements' => $disagree,
            'compared' => $compared,
            'rationale' => sprintf(
                'Dental charts agree on %d of %d shared teeth%s.',
                $agree,
                $compared,
                $disagree > 0 ? ", with {$disagree} discrepancy" : '',
            ),
        ];
    }

    /**
     * Flatten a chart into tooth number => treatment state.
     *
     * @param  array<string, list<int>>  $chart
     * @return array<int, string>
     */
    protected static function byTooth(array $chart): array
    {
        $teeth = [];

        foreach (self::STATES as $state) {
            foreach ($chart[$state] ?? [] as $tooth) {
                $teeth[(int) $tooth] = $state;
            }
        }

        ksort($teeth);

        return $teeth;
    }
}
