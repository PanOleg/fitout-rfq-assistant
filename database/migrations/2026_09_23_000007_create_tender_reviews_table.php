<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every saved review, never overwritten: who decided what, and when.
        Schema::create('tender_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('tender_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('reviewer', 100);
            $table->json('items');
            $table->text('notes')->nullable();
            $table->string('summary');
            $table->timestamp('created_at');
            $table->unique(['tender_id', 'version']);
        });

        Schema::table('tenders', function (Blueprint $table): void {
            // The review version the tender shows; a save based on an older one is refused.
            $table->unsignedInteger('review_version')->default(0)->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropColumn('review_version');
        });
        Schema::dropIfExists('tender_reviews');
    }
};
