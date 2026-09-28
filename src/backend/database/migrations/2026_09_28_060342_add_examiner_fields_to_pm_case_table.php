<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-case audit/custody metadata for bodies logged through the Incident
 * Pipeline intake form, not descriptive content the matcher compares —
 * so these are scalar pm_case columns, the same reasoning as the existing
 * found_place/lat/lon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_case', function (Blueprint $table) {
            $table->string('examiner_name')->nullable()->after('pm_id');
            $table->string('examiner_role')->nullable()->after('examiner_name');
            $table->string('signature_note')->nullable()->after('dental_chart_fdi');
            $table->dateTime('completed_at')->nullable()->after('signature_note');
            $table->string('chain_of_custody_hash')->nullable()->after('completed_at');
            // manual | pdf_scan | live_scan | mixed — provenance of the case record itself.
            $table->string('source_type')->default('manual')->after('chain_of_custody_hash');
        });
    }

    public function down(): void
    {
        Schema::table('pm_case', function (Blueprint $table) {
            $table->dropColumn([
                'examiner_name',
                'examiner_role',
                'signature_note',
                'completed_at',
                'chain_of_custody_hash',
                'source_type',
            ]);
        });
    }
};
