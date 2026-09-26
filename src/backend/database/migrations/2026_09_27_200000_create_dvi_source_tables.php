<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source-of-record tables, mirroring src/data/schema.sql and aligned to the
 * INTERPOL DVI AM (yellow) and PM (pink) form sets.
 *
 * These hold exactly what was recorded in the field. They are populated by
 * `dvi:ingest` from the dataset CSVs and are never written by the pipeline —
 * everything the system infers lands in the tables created by the companion
 * migration, so an examiner's words are always separable from a machine's
 * reading of them.
 *
 * Primary keys are the dataset's own string identifiers (PM-LS26-014), not
 * autoincrement integers: they are what appears on the body tag, in the
 * reviewer's notes and in the ground-truth files, and keeping them makes
 * every log line and URL traceable back to the source record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident', function (Blueprint $table) {
            $table->string('incident_id')->primary();
            $table->string('name');
            $table->date('incident_date');
            $table->string('district')->nullable();
            $table->boolean('synthetic')->default(true);
            $table->timestamps();
        });

        // Post-mortem case: scalar facts recorded by examiners.
        Schema::create('pm_case', function (Blueprint $table) {
            $table->string('pm_id')->primary();
            $table->string('incident_id');
            $table->dateTime('found_at');
            $table->string('found_place')->nullable();
            $table->double('lat')->nullable();
            $table->double('lon')->nullable();

            // Drives how much of the description below can be trusted.
            $table->string('body_condition')->nullable();

            $table->string('sex', 1)->nullable();
            $table->unsignedSmallInteger('age_min')->nullable();
            $table->unsignedSmallInteger('age_max')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();

            // Primary-identifier availability: sample_taken | degraded | not_collected,
            // chart_completed | not_examined | unsuitable, usable | unusable | not_taken.
            $table->string('dna_status')->nullable();
            $table->string('dental_status')->nullable();
            $table->string('print_status')->nullable();

            // JSON, FDI tooth numbers: {"missing":[...],"filled":[...],"crown":[...],"root_canal":[...]}
            $table->text('dental_chart_fdi')->nullable();

            $table->boolean('synthetic')->default(true);
            $table->timestamps();

            $table->foreign('incident_id')->references('incident_id')->on('incident')->cascadeOnDelete();
            $table->index(['incident_id', 'sex']);
        });

        // Ante-mortem file: scalar facts from the missing-person report.
        Schema::create('am_file', function (Blueprint $table) {
            $table->string('am_id')->primary();
            $table->string('incident_id')->nullable();

            // Fictional, and never used for scoring — a name is not evidence.
            $table->string('reported_name');

            $table->string('sex', 1)->nullable();
            $table->unsignedSmallInteger('age')->nullable();

            // As the family said it ("5 ft 7 in") plus the converted value.
            $table->string('height_text')->nullable();
            $table->unsignedSmallInteger('height_cm_reported')->nullable();

            $table->dateTime('last_seen_at')->nullable();
            $table->string('last_seen_place')->nullable();
            $table->string('reported_by_relation')->nullable();
            $table->string('occupation')->nullable();
            $table->string('marital_status')->nullable();

            $table->string('dna_reference_type')->nullable();
            $table->boolean('dental_records_available')->default(false);
            $table->boolean('prints_on_file')->default(false);
            $table->text('dental_chart_fdi')->nullable();

            $table->boolean('synthetic')->default(true);
            $table->timestamps();

            $table->index(['incident_id', 'sex']);
        });

        /*
         * One free-text form box on either record. A single box may describe
         * several distinct items, which is why normalisation is a separate step.
         * Text arrives in English, Hindi and Marathi, including code-mixed
         * Latin-script Marathi.
         */
        Schema::create('observation', function (Blueprint $table) {
            $table->string('obs_id')->primary();
            $table->string('record_type', 2);          // PM | AM
            $table->string('record_id');               // pm_id or am_id
            $table->string('category');                // marks | tattoo | implant | appearance | build | clothing | jewellery | belongings | id_document
            $table->string('form_field')->nullable();  // INTERPOL form section label
            $table->text('raw_text');
            $table->string('lang', 5)->default('en');  // en | hi | mr
            $table->string('source_type')->nullable();
            $table->string('recorded_by')->nullable(); // a role, never a person
            $table->dateTime('recorded_at')->nullable();
            $table->text('reliability_note')->nullable();
            $table->boolean('synthetic')->default(true);
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
            $table->index('category');
        });

        /*
         * A photograph, or a restricted placeholder standing in for one.
         *
         * Photos are evidence artefacts and are never scored directly: a vision
         * step turns them into normalised items which then enter the same
         * scorer as text. Face photos carry no file and are never matched.
         */
        Schema::create('photo_evidence', function (Blueprint $table) {
            $table->string('photo_id')->primary();
            $table->string('record_type', 2);
            $table->string('record_id');
            $table->string('modality');                // body_diagram | tattoo_closeup | clothing_flatlay | last_seen_photo | tattoo_photo | face_photo_restricted
            $table->string('view')->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->string('source_type')->nullable();
            $table->string('quality_flags')->nullable(); // comma list: blur, low_light, noise
            $table->string('file_path')->nullable();     // empty for restricted placeholders
            $table->string('use_policy');                // extract_items_then_human_confirm | restricted_display_only_never_matched
            $table->text('reliability_note')->nullable();
            $table->string('sha256')->nullable();        // chain-of-custody hash
            $table->boolean('synthetic')->default(true);
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
            $table->index('modality');

            // Deliberately omitted: `withheld_from_text`. It is a ground-truth
            // audit field for evaluation only and must not be reachable from
            // application code, so it is never ingested.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_evidence');
        Schema::dropIfExists('observation');
        Schema::dropIfExists('am_file');
        Schema::dropIfExists('pm_case');
        Schema::dropIfExists('incident');
    }
};
