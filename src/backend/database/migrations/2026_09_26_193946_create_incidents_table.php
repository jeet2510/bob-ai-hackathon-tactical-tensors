<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();

            $table->string('incident_id')->unique();

            $table->string('name');
            $table->text('description')->nullable();

            $table->date('incident_date');

            $table->string('district')->nullable();
            $table->string('location')->nullable();

            $table->text('environment')->nullable();

            $table->string('status')->default('active');

            $table->boolean('synthetic')->default(false);

            /*
             * Audit fields
             */
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('updated_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('incident_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
