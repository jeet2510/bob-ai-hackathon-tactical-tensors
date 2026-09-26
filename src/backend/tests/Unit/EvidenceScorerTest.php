<?php

namespace Tests\Unit;

use App\Models\AmFile;
use App\Models\MatchEvidence;
use App\Models\PmCase;
use App\Services\Matching\EvidenceScorer;
use App\Services\Matching\EvidenceTotal;
use App\Services\Matching\RecordItems;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The scorer reads model attributes and item arrays and returns a breakdown,
 * so it is exercised against unsaved models without touching the database.
 */
class EvidenceScorerTest extends TestCase
{
    protected EvidenceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new EvidenceScorer;
    }

    #[Test]
    public function a_shared_distinguishing_mark_outweighs_general_description(): void
    {
        $body = new PmCase(['sex' => 'M', 'height_cm' => 170, 'age_min' => 30, 'age_max' => 40]);

        $withMark = $this->scorer->score(
            $body,
            new AmFile(['sex' => 'M', 'height_cm_reported' => 170, 'age' => 35]),
            $this->items('PM-1', ['mark' => [$this->mark('mole', 'cheek', 'left')]]),
            $this->items('AM-1', ['mark' => [$this->mark('mole', 'cheek', 'left')]]),
        );

        $withoutMark = $this->scorer->score(
            $body,
            new AmFile(['sex' => 'M', 'height_cm_reported' => 170, 'age' => 35]),
            $this->items('PM-1', []),
            $this->items('AM-2', []),
        );

        $this->assertGreaterThan(
            EvidenceTotal::of($withoutMark)->score + 1.5,
            EvidenceTotal::of($withMark)->score,
        );
    }

    #[Test]
    public function a_flipped_side_is_a_conflict_but_never_disqualifying(): void
    {
        $evidence = $this->scorer->score(
            new PmCase(['sex' => 'M']),
            new AmFile(['sex' => 'M']),
            $this->items('PM-1', ['mark' => [$this->mark('burn_scar', 'shin', 'left')]]),
            $this->items('AM-1', ['mark' => [$this->mark('burn_scar', 'shin', 'right')]]),
        );

        $mark = $this->find($evidence, 'mark');

        $this->assertSame(MatchEvidence::VERDICT_CONFLICT, $mark['verdict']);

        // Families mirror left and right constantly; the penalty must be a
        // fraction of what agreement is worth, or true matches get rejected.
        $this->assertGreaterThan(-1.0, $mark['llr']);
    }

    #[Test]
    public function an_unassessable_feature_is_excluded_rather_than_treated_as_absent(): void
    {
        $evidence = $this->scorer->score(
            new PmCase(['sex' => 'M', 'body_condition' => 'Burnt']),
            new AmFile(['sex' => 'M']),
            $this->items('PM-1', ['tattoo' => [['category' => 'tattoo', 'assessable' => false, 'legible' => false]]]),
            $this->items('AM-1', ['tattoo' => [[
                'category' => 'tattoo', 'design' => 'om', 'region' => 'forearm', 'laterality' => 'left',
            ]]]),
        );

        $tattoo = $this->find($evidence, 'tattoo');

        $this->assertSame(MatchEvidence::VERDICT_EXCLUDED, $tattoo['verdict']);
        $this->assertSame(0.0, $tattoo['llr'], 'Evidence that could not be gathered must not move the score.');
    }

    #[Test]
    public function a_garment_match_with_a_misremembered_colour_still_supports_the_pairing(): void
    {
        $evidence = $this->scorer->score(
            new PmCase(['sex' => 'F']),
            new AmFile(['sex' => 'F']),
            $this->items('PM-1', ['clothing' => [$this->clothing('lower', 'saree', 'blue')]]),
            $this->items('AM-1', ['clothing' => [$this->clothing('lower', 'saree', 'grey')]]),
        );

        $clothing = $this->find($evidence, 'clothing');

        $this->assertSame(MatchEvidence::VERDICT_MATCH, $clothing['verdict']);
        $this->assertGreaterThan(0, $clothing['llr']);
    }

    #[Test]
    public function a_matching_device_serial_is_treated_as_a_primary_identifier(): void
    {
        $evidence = $this->scorer->score(
            new PmCase(['sex' => 'M']),
            new AmFile(['sex' => 'M']),
            $this->items('PM-1', ['implant' => [[
                'category' => 'implant', 'type' => 'knee_replacement', 'region' => 'knee', 'serial' => 'SN-650703',
            ]]]),
            $this->items('AM-1', ['implant' => [[
                'category' => 'implant', 'type' => 'knee_replacement', 'region' => 'knee', 'serial' => 'SN-650703',
            ]]]),
        );

        $serial = $this->find($evidence, 'implant_serial');

        $this->assertSame('A', $serial['tier']);
        $this->assertGreaterThan(5.0, $serial['llr']);
    }

    #[Test]
    public function a_planted_identity_document_cannot_outrank_physical_evidence(): void
    {
        // The card on the body names someone else entirely — the document trap.
        $documentOnly = $this->scorer->score(
            new PmCase(['sex' => 'M']),
            new AmFile(['sex' => 'M', 'reported_name' => 'Someone Else']),
            $this->items('PM-1', ['id_document' => [[
                'category' => 'id_document', 'kind' => 'office', 'id_last4' => '4821',
                'name' => 'Vikas Wagh', 'on_body' => true,
            ]]]),
            $this->items('AM-1', ['id_document' => [[
                'category' => 'id_document', 'kind' => 'office', 'id_last4' => '4821',
            ]]]),
        );

        $physical = $this->scorer->score(
            new PmCase(['sex' => 'M']),
            new AmFile(['sex' => 'M']),
            $this->items('PM-2', ['mark' => [$this->mark('surgical_scar', 'abdomen', 'right')]]),
            $this->items('AM-2', ['mark' => [$this->mark('surgical_scar', 'abdomen', 'right')]]),
        );

        $this->assertGreaterThan(
            EvidenceTotal::of($documentOnly)->score,
            EvidenceTotal::of($physical)->score,
            'A document in a pocket must never outweigh a mark on the body.',
        );

        $document = $this->find($documentOnly, 'id_document');
        $this->assertStringContainsString('documents travel', $document['rationale']);
    }

    #[Test]
    public function a_sex_mismatch_sinks_a_pairing(): void
    {
        $evidence = $this->scorer->score(
            new PmCase(['sex' => 'M', 'height_cm' => 165]),
            new AmFile(['sex' => 'F', 'height_cm_reported' => 165]),
            $this->items('PM-1', ['mark' => [$this->mark('mole', 'cheek', 'left')]]),
            $this->items('AM-1', ['mark' => [$this->mark('mole', 'cheek', 'left')]]),
        );

        $this->assertLessThan(0, EvidenceTotal::of($evidence)->score);
    }

    #[Test]
    public function a_demographic_only_pairing_is_never_a_credible_candidate(): void
    {
        $evidence = $this->scorer->score(
            new PmCase(['sex' => 'M', 'height_cm' => 170, 'age_min' => 30, 'age_max' => 40]),
            new AmFile(['sex' => 'M', 'height_cm_reported' => 170, 'age' => 35]),
            $this->items('PM-1', ['build' => [['category' => 'build', 'value' => 'medium']]]),
            $this->items('AM-1', ['build' => [['category' => 'build', 'value' => 'medium']]]),
        );

        $total = EvidenceTotal::of($evidence);

        $this->assertFalse($total->hasIndividuating);
        $this->assertSame('no_credible_candidate', $total->band);
    }

    #[Test]
    public function supportive_evidence_alone_cannot_reach_the_high_band(): void
    {
        // Every soft feature agreeing, and nothing individuating at all.
        $evidence = [];

        foreach (['hair', 'eyes', 'skin_tone', 'build', 'facial_hair', 'sex', 'age', 'height'] as $category) {
            $evidence[] = [
                'category' => $category, 'tier' => 'C', 'verdict' => MatchEvidence::VERDICT_MATCH,
                'llr' => 0.8, 'rationale' => '', 'pm_obs_id' => null, 'am_obs_id' => null,
                'pm_item' => null, 'am_item' => null,
            ];
        }

        $this->assertNotSame('high', EvidenceTotal::of($evidence)->band);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $byCategory
     */
    protected function items(string $recordId, array $byCategory): RecordItems
    {
        $rows = [];

        foreach ($byCategory as $category => $items) {
            foreach ($items as $index => $item) {
                $rows[] = (object) [
                    'obs_id' => "{$recordId}-O".($index + 1),
                    'norm_json' => $item,
                    'confidence' => 1.0,
                    'reviewed_by' => null,
                ];
            }
        }

        return RecordItems::fromNormRows($recordId, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mark(string $type, string $region, ?string $laterality): array
    {
        return [
            'category' => 'mark', 'negated' => false, 'type' => $type,
            'region' => $region, 'laterality' => $laterality,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function clothing(string $slot, string $garment, ?string $colour): array
    {
        return ['category' => 'clothing', 'slot' => $slot, 'garment' => $garment, 'colour' => $colour];
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @return array<string, mixed>
     */
    protected function find(array $evidence, string $category): array
    {
        foreach ($evidence as $row) {
            if ($row['category'] === $category) {
                return $row;
            }
        }

        $this->fail("No evidence row for category '{$category}'.");
    }
}
