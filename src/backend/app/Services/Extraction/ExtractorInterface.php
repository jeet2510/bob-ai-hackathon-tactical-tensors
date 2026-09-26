<?php

namespace App\Services\Extraction;

use App\Models\Observation;

/**
 * Stage 1: turn one free-text form box into normalised items.
 *
 * Implementations are interchangeable — a deterministic rule engine and a
 * watsonx Granite model both satisfy this contract — so the evaluation can
 * measure them against each other on identical input.
 */
interface ExtractorInterface
{
    /**
     * Identifier recorded against every item produced, so two extractors'
     * output can coexist in the database and be compared.
     */
    public function version(): string;

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    public function extract(Observation $observation): array;
}
