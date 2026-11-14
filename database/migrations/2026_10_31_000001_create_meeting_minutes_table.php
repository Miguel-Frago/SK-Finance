<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The SK Secretary's minutes of meetings, kept per barangay.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained('barangays')->restrictOnDelete();
            $table->string('meeting_type', 20);                 // regular | special | assembly
            $table->string('title');
            $table->date('meeting_date');
            $table->string('start_time', 5)->nullable();        // HH:MM
            $table->string('end_time', 5)->nullable();
            $table->string('venue')->nullable();
            $table->string('presiding')->nullable();
            $table->text('present')->nullable();                // who attended, one name per line
            $table->text('absent')->nullable();
            $table->text('agenda')->nullable();
            $table->text('discussion');                         // what was discussed and decided
            $table->text('resolutions')->nullable();            // resolutions passed and action points
            $table->string('next_meeting')->nullable();
            $table->string('prepared_by');
            $table->string('attested_by')->nullable();
            $table->string('status', 10)->default('draft');     // draft: only the Secretary sees it | final: the barangay can read it
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['barangay_id', 'meeting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_minutes');
    }
};
