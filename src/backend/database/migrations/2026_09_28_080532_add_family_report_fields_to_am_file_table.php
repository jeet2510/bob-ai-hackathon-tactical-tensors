<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-report contact/provenance metadata for ante-mortem reports logged
 * through the family interview form — not descriptive content the matcher
 * compares, so these are scalar am_file columns, the same reasoning as the
 * pm_case migration that added examiner_name/examiner_role/etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('am_file', function (Blueprint $table) {
            $table->string('reporter_name')->nullable()->after('am_id');
            $table->string('reporter_phone')->nullable()->after('reporter_name');
            $table->string('reporter_address')->nullable()->after('reporter_phone');
            $table->string('interviewing_officer')->nullable()->after('reporter_address');
            $table->dateTime('interviewed_at')->nullable()->after('interviewing_officer');
            // Free text from Section D ("dentist/clinic name & contact") — informational only;
            // dental_records_available (bool) is what ConfirmationRoute actually reads.
            $table->string('dentist_contact')->nullable()->after('dental_records_available');
            // Comma-joined labels from Section D's fingerprint-source checkboxes
            // (Passport/Aadhaar/Driving licence/Prior police record) — informational
            // only; prints_on_file (bool) is what ConfirmationRoute actually reads.
            $table->string('id_sources')->nullable()->after('prints_on_file');
            // family_self_report | staff_interview
            $table->string('source_type')->default('family_self_report')->after('synthetic');
        });
    }

    public function down(): void
    {
        Schema::table('am_file', function (Blueprint $table) {
            $table->dropColumn([
                'reporter_name',
                'reporter_phone',
                'reporter_address',
                'interviewing_officer',
                'interviewed_at',
                'dentist_contact',
                'id_sources',
                'source_type',
            ]);
        });
    }
};
