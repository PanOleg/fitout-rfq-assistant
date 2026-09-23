<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One token per person (or per integration), stored only as a hash.
        Schema::create('access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('tender_reviews', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('tender_id')->constrained()->nullOnDelete();
        });

        Schema::table('tenders', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('idempotency_key')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
        Schema::table('tender_reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::dropIfExists('access_tokens');
    }
};
