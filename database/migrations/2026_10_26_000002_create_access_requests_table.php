<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Step 1 of joining: a person asks for access with their email, name, barangay and position.
// pending -> approved (the Admin emailed a secret code) -> registered (they made their account), or denied.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->foreignId('barangay_id')->constrained('barangays')->restrictOnDelete();
            $table->string('role', 20);                      // treasurer | chairman
            $table->string('status', 20)->default('pending');
            $table->string('code_hash', 64)->nullable();     // sha256 of the secret code; the code itself is never stored
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('code_used_at')->nullable();
            $table->text('last_message')->nullable();        // the last note the Admin sent (a mistake to fix, or a reason)
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
