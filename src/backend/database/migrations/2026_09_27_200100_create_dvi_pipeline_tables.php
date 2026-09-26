<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the pipeline infers, kept strictly separate from the source
 * records so that what a human wrote is always distinguishable from what a
 * machine read into it.
 *
 * Each stage is reproducible: extraction is keyed by extractor version and
 * backed by a replay cache, and every match run records the scorer version and
 * config hash that produced it, so a candidate list shown to a reviewer months
 * later can be explained exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Stage 1 — one normalised item read out of a free-text form box.
         *
         * `reviewed_by` is the human-in-the-loop hook: a reviewer who corrects
         * a mis-read item stamps their role here, and the corrected item is
         * what the scorer subsequently uses.
         */
        Schema::create('observation_norm', function (Blueprint $table) {
            $table->id();
            $table->string('obs_id');
            $table->unsignedInteger('item_index');
            $table->json('norm_json');
            $table->string('extractor_version');
            $table->float('confidence')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('obs_id')->references('obs_id')->on('observation')->cascadeOnDelete();

            // One row per item per extractor: two extractor versions can be
            // compared side by side without clobbering each other.
            $table->unique(['obs_id', 'item_index', 'extractor_version'], 'obs_norm_unique');
            $table->index('extractor_version');
        });

        // Stage 1b — the same item schema, read out of a photograph instead.
        Schema::create('photo_norm', function (Blueprint $table) {
            $table->id();
            $table->string('photo_id');
            $table->unsignedInteger('item_index');
            $table->json('norm_json');
            $table->string('extractor_version');
            $table->float('confidence')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('photo_id')->references('photo_id')->on('photo_evidence')->cascadeOnDelete();
            $table->unique(['photo_id', 'item_index', 'extractor_version'], 'photo_norm_unique');
        });

        /*
         * A single execution of the scorer over an incident. Candidates and
         * evidence always belong to a run, so re-scoring never overwrites the
         * evidence a past decision was based on.
         */
        Schema::create('match_run', function (Blueprint $table) {
            $table->string('run_id')->primary();
            $table->string('incident_id');
            $table->string('scorer_version');
            $table->string('extractor_version');
            $table->string('config_hash')->nullable();
            $table->json('config')->nullable();
            $table->boolean('assignment_applied')->default(false);
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->foreign('incident_id')->references('incident_id')->on('incident')->cascadeOnDelete();
        });

        /*
         * Stage 2 — why a pairing scores what it does, one row per evidence
         * category. This is the audit trail behind every number on screen.
         *
         * `verdict` distinguishes the four outcomes that matter forensically:
         *   match    — the two sides agree
         *   conflict — they disagree (a flipped scar side is a conflict, not a miss)
         *   missing  — one side has nothing recorded; no information either way
         *   excluded — present but not assessable (burnt trunk, slipped skin),
         *              which is emphatically not the same as absent
         */
        Schema::create('match_evidence', function (Blueprint $table) {
            $table->id();
            $table->string('run_id');
            $table->string('pm_id');
            $table->string('am_id');
            $table->string('category');
            $table->string('tier', 1)->nullable();     // A | B | C — INTERPOL reliability tier
            $table->string('verdict');
            $table->double('llr')->default(0);         // log-likelihood-ratio contribution
            $table->string('pm_obs_id')->nullable();   // provenance back to the form box
            $table->string('am_obs_id')->nullable();
            $table->json('pm_item')->nullable();
            $table->json('am_item')->nullable();
            $table->text('rationale')->nullable();

            $table->foreign('run_id')->references('run_id')->on('match_run')->cascadeOnDelete();
            $table->index(['run_id', 'pm_id', 'am_id'], 'evidence_pairing_idx');
        });

        // Stage 2/3 output — the ranked candidate list a reviewer works from.
        Schema::create('candidate', function (Blueprint $table) {
            $table->id();
            $table->string('run_id');
            $table->string('pm_id');
            $table->string('am_id');
            $table->double('score');
            $table->unsignedInteger('rank')->nullable();
            $table->string('confidence_band');         // high | moderate | low | no_credible_candidate

            // How much of the comparison was actually evidenced. A strong score
            // built on two categories is not a strong result.
            $table->double('coverage')->default(0);

            $table->boolean('assigned')->default(false); // chosen by global one-to-one assignment
            $table->json('recommended_route')->nullable(); // primary-identifier test to run first

            $table->foreign('run_id')->references('run_id')->on('match_run')->cascadeOnDelete();
            $table->unique(['run_id', 'pm_id', 'am_id'], 'candidate_unique');
            $table->index(['run_id', 'pm_id', 'rank']);
        });

        /*
         * Append-only. A changed decision is a new row, never an update — the
         * history of who concluded what, and when, is itself evidence.
         */
        Schema::create('review_decision', function (Blueprint $table) {
            $table->id();
            $table->string('pm_id');
            $table->string('am_id')->nullable();       // null for no_credible_candidate
            $table->string('run_id')->nullable();
            $table->string('reviewer');                // a role
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // recommend_confirm_test | reject | defer | no_credible_candidate
            $table->string('decision');

            $table->text('note')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();

            $table->index(['pm_id', 'decided_at']);
        });

        /*
         * Replay store, so the whole pipeline runs deterministically without
         * live model access. Keyed by the hash of the input and the prompt
         * version: change the prompt and you get a new cache line rather than
         * a silently stale one.
         */
        Schema::create('llm_cache', function (Blueprint $table) {
            $table->id();
            $table->string('input_hash', 64);
            $table->string('prompt_version');
            $table->json('output_json');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->unique(['input_hash', 'prompt_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_cache');
        Schema::dropIfExists('review_decision');
        Schema::dropIfExists('candidate');
        Schema::dropIfExists('match_evidence');
        Schema::dropIfExists('match_run');
        Schema::dropIfExists('photo_norm');
        Schema::dropIfExists('observation_norm');
    }
};
