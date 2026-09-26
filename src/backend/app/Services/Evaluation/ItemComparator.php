<?php

namespace App\Services\Evaluation;

/**
 * Decides when two normalised items describe the same finding.
 *
 * Used both by the Stage-1 scorer (extracted item versus gold item) and by the
 * evidence scorer (post-mortem item versus ante-mortem item), so "same finding"
 * means one thing throughout the system.
 */
class ItemComparator
{
    /**
     * The fields that identify a finding, per category.
     *
     * Deliberately excludes measurements and incidental colour: a family
     * saying "about 2 cm" and an examiner measuring 18 mm are describing one
     * scar, and requiring them to agree would punish a correct reading.
     * Laterality is *included* — a left scar and a right scar are different
     * findings, and treating them as one is the error the flipped-mark cases
     * are built to catch.
     *
     * @var array<string, list<string>>
     */
    public const KEY_FIELDS = [
        'mark' => ['type', 'region', 'laterality'],
        'tattoo' => ['design', 'region', 'laterality'],
        'clothing' => ['slot', 'garment', 'colour'],
        'jewellery' => ['item', 'metal', 'site'],
        'belonging' => ['item'],
        'id_document' => ['kind', 'id_last4'],
        'implant' => ['type', 'region'],
        'hair' => ['colour', 'length'],
        'eyes' => ['colour'],
        'skin_tone' => ['value'],
        'build' => ['value'],
        'facial_hair' => ['value'],
    ];

    /**
     * Strict equality on the identifying fields, plus the flags that change
     * what an item means: an absent tattoo and an unreadable one are not the
     * same claim.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function same(array $a, array $b): bool
    {
        $category = $a['category'] ?? null;

        if ($category !== ($b['category'] ?? null)) {
            return false;
        }

        if (self::flag($a, 'negated') !== self::flag($b, 'negated')) {
            return false;
        }

        if (self::flag($a, 'assessable', true) !== self::flag($b, 'assessable', true)) {
            return false;
        }

        // A negated item carries no further detail to compare.
        if (self::flag($a, 'negated')) {
            return true;
        }

        foreach (self::KEY_FIELDS[$category] ?? [] as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Greedy one-to-one alignment between two item lists.
     *
     * @param  list<array<string, mixed>>  $predicted
     * @param  list<array<string, mixed>>  $gold
     * @return array{matched: int, predicted: int, gold: int}
     */
    public static function align(array $predicted, array $gold): array
    {
        $used = [];
        $matched = 0;

        foreach ($predicted as $item) {
            foreach ($gold as $i => $goldItem) {
                if (isset($used[$i])) {
                    continue;
                }

                if (self::same($item, $goldItem)) {
                    $used[$i] = true;
                    $matched++;
                    break;
                }
            }
        }

        return [
            'matched' => $matched,
            'predicted' => count($predicted),
            'gold' => count($gold),
        ];
    }

    /**
     * Absent flags take their default: most items are neither negated nor
     * unassessable, and the gold omits the key rather than writing it false.
     *
     * @param  array<string, mixed>  $item
     */
    protected static function flag(array $item, string $key, bool $default = false): bool
    {
        return (bool) ($item[$key] ?? $default);
    }
}
