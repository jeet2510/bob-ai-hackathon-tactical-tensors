<?php

namespace App\Services\Matching;

/**
 * The normalised items belonging to one record, grouped by category and
 * carrying provenance back to the form box each came from.
 *
 * Provenance is not decoration: a reviewer told "the scar matches" will ask
 * which line of which form said so, and the answer has to be one click away.
 */
class RecordItems
{
    /**
     * @param  array<string, list<array{item: array<string, mixed>, obs_id: string, confidence: float|null, reviewed: bool}>>  $byCategory
     */
    public function __construct(
        public readonly string $recordId,
        protected array $byCategory = [],
    ) {}

    /**
     * @param  iterable<object{obs_id: string, norm_json: array, confidence: float|null, reviewed_by: string|null}>  $rows
     */
    public static function fromNormRows(string $recordId, iterable $rows): self
    {
        $grouped = [];

        foreach ($rows as $row) {
            $item = $row->norm_json;

            if (! is_array($item) || ! isset($item['category'])) {
                continue;
            }

            $grouped[$item['category']][] = [
                'item' => $item,
                'obs_id' => $row->obs_id,
                'confidence' => $row->confidence,
                'reviewed' => $row->reviewed_by !== null,
            ];
        }

        return new self($recordId, $grouped);
    }

    /**
     * @return list<array{item: array<string, mixed>, obs_id: string, confidence: float|null, reviewed: bool}>
     */
    public function category(string $category): array
    {
        return $this->byCategory[$category] ?? [];
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return array_keys($this->byCategory);
    }

    public function has(string $category): bool
    {
        return ($this->byCategory[$category] ?? []) !== [];
    }

    /**
     * Whether every item in a category says the feature could not be assessed
     * — the burnt or decomposed case, where absence of a finding carries no
     * information at all.
     */
    public function isCategoryUnassessable(string $category): bool
    {
        $entries = $this->category($category);

        if ($entries === []) {
            return false;
        }

        foreach ($entries as $entry) {
            if (($entry['item']['assessable'] ?? true) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Items asserting the feature is genuinely absent ("no tattoos"), as
     * opposed to unexamined.
     */
    public function isCategoryNegated(string $category): bool
    {
        foreach ($this->category($category) as $entry) {
            if (($entry['item']['negated'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assessable, non-negated items — the ones that can actually be compared.
     *
     * @return list<array{item: array<string, mixed>, obs_id: string, confidence: float|null, reviewed: bool}>
     */
    public function comparable(string $category): array
    {
        return array_values(array_filter(
            $this->category($category),
            fn (array $entry) => ($entry['item']['assessable'] ?? true) !== false
                && ($entry['item']['negated'] ?? false) !== true,
        ));
    }

    public function totalItems(): int
    {
        return array_sum(array_map('count', $this->byCategory));
    }
}
