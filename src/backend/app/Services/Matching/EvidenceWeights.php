<?php

namespace App\Services\Matching;

/**
 * How much each kind of agreement is worth, in log-likelihood-ratio units.
 *
 * A weight is read as: how many times more likely is this agreement if the two
 * records describe the same person than if they describe different people. A
 * shared tattoo design on the same limb is strong; a shared "medium build" is
 * almost worthless, because most people are medium build. The numbers follow
 * INTERPOL's reliability tiers rather than intuition about what "feels"
 * convincing, and are ordinal evidence strength — not calibrated probabilities.
 *
 * Conflicts are weighted far more gently than matches. Ante-mortem data comes
 * from relatives recalling a person under the worst circumstances of their
 * lives: in this dataset roughly a fifth of clothing reports and one in twelve
 * mark descriptions are wrong or side-flipped. A system that punished those
 * errors symmetrically would reject true matches, which is the one failure
 * mode a DVI tool must not have.
 */
class EvidenceWeights
{
    /**
     * Tier A — primary identifiers. INTERPOL accepts identification on these
     * alone; nothing else here is sufficient on its own.
     */
    public const TIER_A = 'A';

    /** Tier B — strong secondary: marks, tattoos, implants, unique effects. */
    public const TIER_B = 'B';

    /** Tier C — supportive: general description and clothing. */
    public const TIER_C = 'C';

    /**
     * @var array<string, array{tier: string, match: float, conflict: float, partial?: float}>
     */
    public const CATEGORY = [
        // A dental chart agreeing across many teeth is decisive, and this
        // dataset provides charts on both sides for around half the cases.
        'dental' => ['tier' => self::TIER_A, 'match' => 9.0, 'conflict' => -9.0],

        // A device serial is effectively unique to one implant in one person.
        'implant_serial' => ['tier' => self::TIER_A, 'match' => 8.0, 'conflict' => -6.0],

        'implant' => ['tier' => self::TIER_B, 'match' => 3.2, 'conflict' => -1.0],
        'tattoo' => ['tier' => self::TIER_B, 'match' => 3.4, 'conflict' => -1.2, 'partial' => 1.4],
        'mark' => ['tier' => self::TIER_B, 'match' => 2.0, 'conflict' => -0.7, 'partial' => 0.8],

        'jewellery' => ['tier' => self::TIER_C, 'match' => 0.7, 'conflict' => -0.25],
        // A full outfit agreeing is reasonably individuating; the partial
        // credit below is for the garment agreeing while the colour does not.
        'clothing' => ['tier' => self::TIER_C, 'match' => 0.9, 'conflict' => -0.25, 'partial' => 0.35],
        'belonging' => ['tier' => self::TIER_C, 'match' => 0.4, 'conflict' => -0.1],

        'hair' => ['tier' => self::TIER_C, 'match' => 0.45, 'conflict' => -0.3, 'partial' => 0.2],
        'eyes' => ['tier' => self::TIER_C, 'match' => 0.2, 'conflict' => -0.25],
        'skin_tone' => ['tier' => self::TIER_C, 'match' => 0.35, 'conflict' => -0.3],
        'build' => ['tier' => self::TIER_C, 'match' => 0.3, 'conflict' => -0.3],
        'facial_hair' => ['tier' => self::TIER_C, 'match' => 0.3, 'conflict' => -0.2],

        // Scalar fields from the form headers rather than the free-text boxes.
        'sex' => ['tier' => self::TIER_C, 'match' => 0.6, 'conflict' => -7.0],
        'age' => ['tier' => self::TIER_C, 'match' => 0.7, 'conflict' => -0.9],
        'height' => ['tier' => self::TIER_C, 'match' => 0.8, 'conflict' => -0.8],

        /*
         * An identity document is a lead, not evidence about a body.
         *
         * A card in a pocket says where the card ended up. Bodies are moved,
         * bags are swapped, and people carry other people's documents — this
         * dataset includes cases where the card on the body belongs to someone
         * else entirely, one of them a person of the opposite sex. The weight
         * is therefore small enough that a document can never outrank physical
         * evidence, and the *name* on it is never scored at all.
         */
        'id_document' => ['tier' => self::TIER_C, 'match' => 0.5, 'conflict' => 0.0],
    ];

    /**
     * Agreement that a feature is absent — both sides recording no tattoos.
     * Real but weak: most people have no tattoos, so agreeing on it says little.
     */
    public const NEGATIVE_AGREEMENT = 0.25;

    /**
     * Total evidence needed for each confidence band, in the same units.
     *
     * Bands are gated on more than the total: a high band additionally
     * requires at least one Tier A or B finding, so a pile of clothing
     * agreements can never present as a strong identification.
     *
     * BAND_LOW is the floor for being a credible candidate at all — below it
     * the system says there is none. It was chosen on the dev split by
     * trading correct refusals against *false* refusals, and 3.0 is where the
     * first stops improving: it catches four of the twelve bodies that have no
     * partner, at the cost of wrongly dismissing two of sixty-four that do.
     * Pushing higher bought no further refusals and only cost true pairs.
     */
    public const BAND_HIGH = 8.0;

    public const BAND_MODERATE = 5.0;

    public const BAND_LOW = 3.0;

    public static function tierFor(string $category): string
    {
        return self::CATEGORY[$category]['tier'] ?? self::TIER_C;
    }

    public static function match(string $category): float
    {
        return self::CATEGORY[$category]['match'] ?? 0.0;
    }

    public static function conflict(string $category): float
    {
        return self::CATEGORY[$category]['conflict'] ?? 0.0;
    }

    /**
     * Credit for agreeing on the substance but not every detail — the right
     * tattoo design on the right limb, but one side never recorded which side.
     */
    public static function partial(string $category): float
    {
        return self::CATEGORY[$category]['partial'] ?? (self::match($category) * 0.4);
    }
}
