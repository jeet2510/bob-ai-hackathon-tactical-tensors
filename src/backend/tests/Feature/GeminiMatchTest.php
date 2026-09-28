<?php

namespace Tests\Feature;

use App\Models\AmFile;
use App\Models\Candidate;
use App\Models\Incident;
use App\Models\Observation;
use App\Models\PmCase;
use App\Models\User;
use App\Services\Extraction\RuleExtractor;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Gemini refinement never runs the deterministic scorer itself, never
 * searches beyond a body's existing shortlist, and never lets its own
 * match_run become the one Incident::latestRun() returns elsewhere in the
 * app — see App\Models\Incident::latestRun().
 */
class GeminiMatchTest extends TestCase
{
    use RefreshDatabase;

    protected Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(), 'sanctum');

        config(['gemini.api_key' => 'test-key']);

        $this->incident = Incident::create([
            'incident_id' => 'GMT',
            'name' => 'Gemini match test',
            'incident_date' => '2026-07-26',
            'district' => 'Testville',
        ]);
    }

    #[Test]
    public function it_refines_the_shortlist_and_leaves_the_deterministic_run_as_latest(): void
    {
        $body = $this->body('GMT-PM-001', ['sex' => 'M', 'age_min' => 30, 'age_max' => 40, 'height_cm' => 170]);
        $this->observation('GMT-PM-001-O01', 'PM', 'GMT-PM-001', 'tattoo', 'Tattoo - Om symbol, right wrist.');

        $match = $this->profile('GMT-AM-001', 'Rakesh Mohanty', ['sex' => 'M', 'age' => 34, 'height_cm_reported' => 171]);
        $this->observation('GMT-AM-001-O01', 'AM', 'GMT-AM-001', 'tattoo', 'Om tattoo on right wrist.');

        $this->artisan('dvi:extract', ['--extractor' => RuleExtractor::VERSION])->assertSuccessful();
        $deterministicRun = app(MatchingService::class)->run($this->incident, RuleExtractor::VERSION);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'summary' => 'The wrist tattoo is the deciding factor; both photographs show the same Om placement.',
                    'candidates' => [[
                        'am_id' => $match->am_id,
                        'confidence' => 'high',
                        'rationale' => 'The wrist tattoo described by the family matches the photographed tattoo.',
                        'visual_notes' => 'Same Om symbol placement visible in both photographs.',
                    ]],
                ])]]],
            ]],
        ])]);

        $response = $this->postJson("/api/incidents/GMT/bodies/{$body->pm_id}/gemini-match")->assertOk();

        $response->assertJsonPath('candidates.0.am_id', $match->am_id);
        $response->assertJsonPath('candidates.0.ai_rationale', 'The wrist tattoo described by the family matches the photographed tattoo.');
        $response->assertJsonPath('summary', 'The wrist tattoo is the deciding factor; both photographs show the same Om placement.');
        $this->assertNotEmpty($response->json('candidates.0.evidence_summary'), 'The deterministic rationale for this pairing should surface as matched evidence.');

        $this->assertDatabaseHas('candidate', [
            'pm_id' => $body->pm_id,
            'am_id' => $match->am_id,
            'confidence_band' => 'high',
        ]);

        $geminiCandidate = Candidate::where('pm_id', $body->pm_id)->where('confidence_band', 'high')
            ->whereNotNull('ai_rationale')->firstOrFail();
        $this->assertNotSame($deterministicRun['run_id'], $geminiCandidate->run_id);

        // The critical invariant: a Gemini run must never become "the" run.
        $shown = $this->getJson("/api/incidents/GMT/bodies/{$body->pm_id}")->assertOk();
        $this->assertSame($deterministicRun['run_id'], $shown->json('run.run_id'));
        $this->assertSame('matcher-v1/evidence-v1', $shown->json('run.scorer_version'));

        // The summary (and the rest) must also be readable back later without calling Gemini again.
        $latest = $this->getJson("/api/incidents/GMT/bodies/{$body->pm_id}/gemini-match")->assertOk();
        $latest->assertJsonPath('summary', 'The wrist tattoo is the deciding factor; both photographs show the same Om placement.');
        $latest->assertJsonPath('candidates.0.am_id', $match->am_id);
    }

    #[Test]
    public function it_rejects_a_candidate_gemini_invents_outside_the_shortlist(): void
    {
        $body = $this->body('GMT-PM-002', ['sex' => 'M', 'age_min' => 30, 'age_max' => 40, 'height_cm' => 170]);
        $this->observation('GMT-PM-002-O01', 'PM', 'GMT-PM-002', 'tattoo', 'Tattoo - anchor, left forearm.');

        $this->profile('GMT-AM-002', 'Someone', ['sex' => 'M', 'age' => 33, 'height_cm_reported' => 169]);
        $this->observation('GMT-AM-002-O01', 'AM', 'GMT-AM-002', 'tattoo', 'Anchor tattoo on left forearm.');

        $this->artisan('dvi:extract', ['--extractor' => RuleExtractor::VERSION])->assertSuccessful();
        app(MatchingService::class)->run($this->incident, RuleExtractor::VERSION);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'candidates' => [[
                        'am_id' => 'GMT-AM-999', // never part of the shortlist
                        'confidence' => 'high',
                        'rationale' => 'Hallucinated.',
                    ]],
                ])]]],
            ]],
        ])]);

        $this->postJson("/api/incidents/GMT/bodies/{$body->pm_id}/gemini-match")->assertStatus(422);

        $this->assertDatabaseMissing('candidate', ['am_id' => 'GMT-AM-999']);
    }

    #[Test]
    public function it_requires_a_deterministic_run_to_exist_first(): void
    {
        $body = $this->body('GMT-PM-003', ['sex' => 'M']);

        $response = $this->postJson("/api/incidents/GMT/bodies/{$body->pm_id}/gemini-match");

        $response->assertStatus(422);
        $response->assertJsonPath('ai_available', false);
    }

    #[Test]
    public function it_requires_a_nonempty_shortlist(): void
    {
        $body = $this->body('GMT-PM-004', ['sex' => 'M']);

        // A run with no profiles at all in the incident: nothing to compare.
        app(MatchingService::class)->run($this->incident, RuleExtractor::VERSION);

        $response = $this->postJson("/api/incidents/GMT/bodies/{$body->pm_id}/gemini-match");

        $response->assertStatus(422);
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

    protected function observation(string $obsId, string $type, string $recordId, string $category, string $text): Observation
    {
        return Observation::create([
            'obs_id' => $obsId,
            'record_type' => $type,
            'record_id' => $recordId,
            'category' => $category,
            'raw_text' => $text,
            'lang' => 'en',
        ]);
    }
}
