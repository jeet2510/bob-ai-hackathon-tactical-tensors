<?php

namespace App\Services\Extraction;

/**
 * Surface-form lookup against the lexicon tables.
 *
 * Matching is substring-based rather than token-based because the text mixes
 * scripts and scripts mix with English mid-sentence; Devanagari also inflects
 * by suffix ("नडगी" → "नडगीवर"), so a prefix appearing anywhere in the clause
 * is the right unit, not a whitespace-delimited word.
 */
class Matcher
{
    /**
     * First code whose surface forms appear in the text. Map order is
     * significant: list longer, more specific forms before shorter ones they
     * contain.
     *
     * @param  array<string, list<string>>  $map
     */
    public static function first(string $text, array $map): ?string
    {
        $haystack = self::normalise($text);

        foreach ($map as $code => $forms) {
            foreach ($forms as $form) {
                if (str_contains($haystack, self::normalise($form))) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Every code present, in the order its earliest surface form occurs in the
     * text — so "backpack, earphones, spectacles" comes back in that order.
     *
     * @param  array<string, list<string>>  $map
     * @return list<string>
     */
    public static function allInOrder(string $text, array $map): array
    {
        $haystack = self::normalise($text);
        $found = [];

        foreach ($map as $code => $forms) {
            foreach ($forms as $form) {
                $position = mb_strpos($haystack, self::normalise($form));

                if ($position !== false) {
                    // Keep the earliest mention; a garment named twice is one garment.
                    $found[$code] = min($found[$code] ?? PHP_INT_MAX, $position);
                    break;
                }
            }
        }

        asort($found);

        return array_keys($found);
    }

    public static function contains(string $text, string $form): bool
    {
        return str_contains(self::normalise($text), self::normalise($form));
    }

    /**
     * @param  list<string>  $forms
     */
    public static function containsAny(string $text, array $forms): bool
    {
        foreach ($forms as $form) {
            if (self::contains($text, $form)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, collapse whitespace, and strip the punctuation that separates
     * clauses, so "S.P." and "sp" compare equal.
     */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['।', '॰'], '.', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Split a form box into the clauses that each describe one finding.
     *
     * Devanagari sentences end in a danda (।); English ones in a full stop.
     * Semicolons separate findings in clinical PM text.
     *
     * A full stop between two digits is a decimal point, not a sentence end —
     * splitting "about 0.5 cm" in two loses the measurement entirely.
     *
     * @return list<string>
     */
    public static function clauses(string $text): array
    {
        $parts = preg_split('/(?:(?<!\d)\.(?!\d)|[;।\n])+/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    /**
     * A size in millimetres, from "2 mm", "about 11 cm" or "0.5 cm".
     */
    public static function sizeMm(string $text): ?int
    {
        if (preg_match('/([\d]+(?:\.[\d]+)?)\s*(mm|cm)\b/i', $text, $m)) {
            $value = (float) $m[1];

            return (int) round(strtolower($m[2]) === 'cm' ? $value * 10 : $value);
        }

        return null;
    }

    /**
     * Weight as a [min, max] range. "44-54 kg" is a range; "about 50 kg" is a
     * point value, which the codebook still stores as a range.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function weightKg(string $text): ?array
    {
        if (preg_match('/(\d+)\s*[-–]\s*(\d+)\s*kg/i', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        if (preg_match('/(\d+)\s*kg/i', $text, $m)) {
            return [(int) $m[1], (int) $m[1]];
        }

        return null;
    }

    public static function isNotAssessable(string $text): bool
    {
        return self::containsAny($text, Lexicon::NOT_ASSESSABLE);
    }

    /**
     * True when the text asserts a feature is genuinely absent.
     *
     * Checked only after not-assessable, because "tattoo, if any, not
     * assessable" contains no negation but must never read as "no tattoo".
     */
    public static function isNegated(string $text): bool
    {
        if (self::isNotAssessable($text)) {
            return false;
        }

        return self::containsAny($text, Lexicon::NEGATION);
    }
}
