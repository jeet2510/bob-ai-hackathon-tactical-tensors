<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carries a Gemini-refined candidate's multimodal reasoning. Populated only
 * for candidate rows produced by scorer_version 'gemini-v1' — the
 * deterministic matcher-v1 rows leave these null, since their reasoning is
 * already fully captured, category by category, in match_evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate', function (Blueprint $table) {
            $table->text('ai_rationale')->nullable()->after('recommended_route');
            $table->text('ai_visual_notes')->nullable()->after('ai_rationale');
        });
    }

    public function down(): void
    {
        Schema::table('candidate', function (Blueprint $table) {
            $table->dropColumn(['ai_rationale', 'ai_visual_notes']);
        });
    }
};
