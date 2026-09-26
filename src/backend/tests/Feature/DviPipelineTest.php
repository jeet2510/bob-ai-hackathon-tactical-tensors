<?php

namespace Tests\Feature;

use App\Models\AmFile;
use App\Models\Incident;
use App\Models\Observation;
use App\Models\ObservationNorm;
use App\Models\PhotoEvidence;
use App\Models\PmCase;
use App\Models\ReviewDecision;
use App\Models\User;
use App\Services\Extraction\RuleExtractor;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Walks the whole pipeline on a small hand-built incident: form boxes in three
 * languages, extraction, scoring, review, and the reconciliation report.
 *
 * The fixture is deliberately tiny and written here rather than loaded from
 * the dataset, so a failure points at the code rather than at a data change.
 */
class DviPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->incident = Incident::create([
            'incident_id' => 'TST',
            'name' => 'Test landslide',
            'incident_date' => '2026-07-26',
            'district' => 'Testville',
        ]);
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/incidents')->assertUnauthorized();
    }

    #[Test]
    public function it_runs_end_to_end_from_free_text_to_a_reconciliation_report(): void
    {
        $body = $this->body('TST-PM-001', [
            'sex' => 'M', 'age_min' => 30, 'age_max' => 40, 'height_cm' => 170,
            'dental_status' => 'chart_completed',
            'dental_chart_fdi' => json_encode(['missing' => [13], 'filled' => [36], 'crown' => [], 'root_canal' => []]),
        ]);

        $this->observation('TST-PM-001-O01', 'PM', 'TST-PM-001', 'marks',
            'Dark brown pigmented naevus, 4 mm, left cheek.');
        $this->observation('TST-PM-001-O02', 'PM', 'TST-PM-001', 'tattoo',
            'Tattoo - Om symbol, right wrist.');

        // The true partner, described by a relative in Marathi.
        $match = $this->profile('TST-AM-001', 'Rakesh Mohanty', [
            'sex' => 'M', 'age' => 34, 'height_cm_reported' => 171,
            'dental_records_available' => true,
            'dental_chart_fdi' => json_encode(['missing' => [13], 'filled' => [36], 'crown' => [], 'root_canal' => []]),
        ]);

        $this->observation('TST-AM-001-O01', 'AM', 'TST-AM-001', 'marks',
            'डाव्या गालावर तिळ आहे.', 'mr');
        $this->observation('TST-AM-001-O02', 'AM', 'TST-AM-001', 'tattoo',
            'उजव्या मनगटावर ॐ चा टॅटू आहे.', 'mr');

        // A decoy agreeing only on general description.
        $this->profile('TST-AM-002', 'Pradeep Sahu', [
            'sex' => 'M', 'age' => 33, 'height_cm_reported' => 170,
        ]);
        $this->observation('TST-AM-002-O01', 'AM', 'TST-AM-002', 'build', 'Medium build.');

        $this->artisan('dvi:extract', ['--extractor' => RuleExtractor::VERSION])->assertSuccessful();

        // Marathi went in; structured INTERPOL items came out.
        $this->assertDatabaseHas('observation_norm', ['obs_id' => 'TST-AM-001-O01']);

        app(MatchingService::class)->run($this->incident, RuleExtractor::VERSION);

        $response = $this->getJson("/api/incidents/TST/bodies/{$body->pm_id}")->assertOk();

        $candidates = $response->json('candidates');

        $this->assertNotEmpty($candidates);
        $this->assertSame($match->am_id, $candidates[0]['am_id'], 'The profile sharing a mole, a tattoo and a dental chart must rank first.');
        $this->assertSame('high', $candidates[0]['confidence_band']);

        // The dental chart is the decisive, primary-identifier evidence.
        $categories = array_column($candidates[0]['evidence'], 'category');
        $this->assertContains('dental', $categories);

        // And the system says which test to run, rather than asserting identity.
        $this->assertSame('dental', $candidates[0]['recommended_route']['route']);

        $this->postJson("/api/incidents/TST/bodies/{$body->pm_id}/decisions", [
            'decision' => 'recommend_confirm_test',
            'am_id' => $match->am_id,
            'note' => 'Referred for dental comparison.',
        ])->assertCreated();

        $report = $this->getJson('/api/incidents/TST/report')->assertOk()->json('report');

        $this->assertSame(1, $report['summary']['referred_for_confirmation']);
        $this->assertSame('referred_for_confirmation', $report['sections'][0]['outcome']);
        $this->assertStringContainsString('primary identifier', $report['disclaimer']);
    }

    #[Test]
    public function a_body_nobody_reported_yields_no_credible_candidate(): void
    {
        $body = $this->body('TST-PM-009', ['sex' => 'F', 'age_min' => 25, 'age_max' => 35, 'height_cm' => 155]);
        $this->observation('TST-PM-009-O01', 'PM', 'TST-PM-009', 'build', 'Build: medium.');

        // A woman of similar description, but no shared individuating feature.
        $this->profile('TST-AM-009', 'Unrelated Person', ['sex' => 'F', 'age' => 30, 'height_cm_reported' => 156]);
        $this->observation('TST-AM-009-O01', 'AM', 'TST-AM-009', 'build', 'Medium build.');

        $this->artisan('dvi:extract', ['--extractor' => RuleExtractor::VERSION])->assertSuccessful();
        app(MatchingService::class)->run($this->incident, RuleExtractor::VERSION);

        $candidates = $this->getJson("/api/incidents/TST/bodies/{$body->pm_id}")->assertOk()->json('candidates');

        foreach ($candidates as $candidate) {
            $this->assertSame('no_credible_candidate', $candidate['confidence_band'],
                'Agreeing only on demographics must never present as a lead.');
        }
    }

    #[Test]
    public function review_decisions_are_append_only(): void
    {
        $body = $this->body('TST-PM-002', ['sex' => 'M']);
        $profile = $this->profile('TST-AM-003', 'Someone', ['sex' => 'M']);

        foreach (['defer', 'reject'] as $decision) {
            $this->postJson("/api/incidents/TST/bodies/{$body->pm_id}/decisions", [
                'decision' => $decision,
                'am_id' => $profile->am_id,
            ])->assertCreated();
        }

        // Both survive; the later one is merely the current view.
        $this->assertSame(2, ReviewDecision::where('pm_id', $body->pm_id)->count());
        $this->assertSame('reject', ReviewDecision::currentFor($body->pm_id)->decision);
    }

    #[Test]
    public function a_refusal_may_not_carry_a_pairing(): void
    {
        $body = $this->body('TST-PM-003', ['sex' => 'M']);
        $profile = $this->profile('TST-AM-004', 'Someone', ['sex' => 'M']);

        $this->postJson("/api/incidents/TST/bodies/{$body->pm_id}/decisions", [
            'decision' => 'no_credible_candidate',
            'am_id' => $profile->am_id,
        ])->assertCreated();

        $this->assertNull(ReviewDecision::currentFor($body->pm_id)->am_id);
    }

    #[Test]
    public function a_decision_about_a_pairing_requires_one(): void
    {
        $body = $this->body('TST-PM-004', ['sex' => 'M']);

        $this->postJson("/api/incidents/TST/bodies/{$body->pm_id}/decisions", [
            'decision' => 'recommend_confirm_test',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_restricted_facial_photograph_is_never_served(): void
    {
        $this->body('TST-PM-005', ['sex' => 'F']);

        $photo = PhotoEvidence::create([
            'photo_id' => 'PH-TST-PM-005-01',
            'record_type' => 'PM',
            'record_id' => 'TST-PM-005',
            'modality' => PhotoEvidence::MODALITY_FACE,
            'use_policy' => PhotoEvidence::POLICY_RESTRICTED,
            'file_path' => null,
        ]);

        $this->get("/api/photos/{$photo->photo_id}/file")->assertForbidden();

        // It is still listed, so a reviewer knows the photograph exists.
        $photos = $this->getJson('/api/incidents/TST/bodies/TST-PM-005')->assertOk()->json('photos');

        $this->assertTrue($photos[0]['restricted']);
        $this->assertNull($photos[0]['url']);
    }

    #[Test]
    public function a_reviewers_correction_supersedes_the_extractor(): void
    {
        $this->body('TST-PM-006', ['sex' => 'M']);
        $this->observation('TST-PM-006-O01', 'PM', 'TST-PM-006', 'marks', 'Mole on the left cheek.');

        $this->artisan('dvi:extract', ['--extractor' => RuleExtractor::VERSION])->assertSuccessful();

        $item = ObservationNorm::where('obs_id', 'TST-PM-006-O01')->firstOrFail();

        $this->patchJson("/api/observation-items/{$item->id}", [
            'item' => ['category' => 'mark', 'type' => 'mole', 'region' => 'cheek', 'laterality' => 'right'],
            'reviewer' => 'forensic odontologist',
        ])->assertOk();

        $item->refresh();

        $this->assertSame('forensic odontologist', $item->reviewed_by);
        $this->assertSame('right', $item->norm_json['laterality']);
    }

    #[Test]
    public function ingest_refuses_to_read_from_a_ground_truth_path(): void
    {
        $this->artisan('dvi:ingest', ['--data' => '/tmp/some/ground_truth'])->assertFailed();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function body(string $pmId, array $attributes): PmCase
    {
        return PmCase::create($attributes + [
            'pm_id' => $pmId,
            'incident_id' => $this->incident->incident_id,
            'found_at' => '2026-07-27 10:00:00',
            'body_condition' => 'Fresh',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function profile(string $amId, string $name, array $attributes): AmFile
    {
        return AmFile::create($attributes + [
            'am_id' => $amId,
            'incident_id' => $this->incident->incident_id,
            'reported_name' => $name,
        ]);
    }

    protected function observation(
        string $obsId,
        string $type,
        string $recordId,
        string $category,
        string $text,
        string $lang = 'en',
    ): Observation {
        return Observation::create([
            'obs_id' => $obsId,
            'record_type' => $type,
            'record_id' => $recordId,
            'category' => $category,
            'raw_text' => $text,
            'lang' => $lang,
        ]);
    }
}
