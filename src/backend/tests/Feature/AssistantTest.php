<?php

namespace Tests\Feature;

use App\Models\AmFile;
use App\Models\Incident;
use App\Models\PmCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DVI Assistant is read-only: it can describe the incident's current data
 * but must never be able to change it, and must degrade gracefully — never
 * a 500 — when Gemini has no credentials.
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    protected Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(), 'sanctum');

        config(['gemini.api_key' => 'test-key']);

        $this->incident = Incident::create([
            'incident_id' => 'AST',
            'name' => 'Assistant test',
            'incident_date' => '2026-07-26',
            'district' => 'Testville',
        ]);

        PmCase::create([
            'pm_id' => 'AST-PM-001',
            'incident_id' => 'AST',
            'found_at' => '2026-07-27 10:00:00',
            'body_condition' => 'Fresh',
            'sex' => 'M',
        ]);

        AmFile::create([
            'am_id' => 'AST-AM-001',
            'incident_id' => 'AST',
            'reported_name' => 'Someone',
            'sex' => 'M',
        ]);
    }

    #[Test]
    public function the_dashboard_briefing_answers_from_the_real_incident_snapshot(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => '1 body recovered and 1 family report filed so far; nothing has a credible candidate yet.',
                ]]],
            ]],
        ])]);

        $response = $this->getJson('/api/incidents/AST/insight')->assertOk();

        $response->assertJsonPath('ai_available', true);
        $response->assertJsonPath('text', '1 body recovered and 1 family report filed so far; nothing has a credible candidate yet.');
    }

    #[Test]
    public function the_chat_sidebar_answers_and_carries_history_forward(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'One body, AST-PM-001, is still awaiting review.']]],
            ]],
        ])]);

        $response = $this->postJson('/api/incidents/AST/assistant', [
            'message' => 'How many bodies are awaiting review?',
            'history' => [
                ['role' => 'user', 'text' => 'Hi'],
                ['role' => 'assistant', 'text' => 'Hello, how can I help with this incident?'],
            ],
        ])->assertOk();

        $response->assertJsonPath('ai_available', true);
        $response->assertJsonPath('reply', 'One body, AST-PM-001, is still awaiting review.');
    }

    #[Test]
    public function it_degrades_gracefully_with_no_credentials_configured(): void
    {
        config(['gemini.api_key' => null]);

        $response = $this->postJson('/api/incidents/AST/assistant', ['message' => 'Anything urgent?']);

        $response->assertOk();
        $response->assertJsonPath('ai_available', false);
        $response->assertJsonPath('reply', null);
    }

    #[Test]
    public function a_malformed_history_entry_is_rejected(): void
    {
        $response = $this->postJson('/api/incidents/AST/assistant', [
            'message' => 'Hello',
            'history' => [['role' => 'system', 'text' => 'not allowed']],
        ]);

        $response->assertStatus(422);
    }
}
