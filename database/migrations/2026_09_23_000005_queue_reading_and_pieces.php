<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            // An uploaded file is read in the queue, so the text is not there yet at first.
            $table->longText('document')->nullable()->change();
            // The upload itself, on the local disk, until it has been read.
            $table->string('source_path')->nullable()->after('source_filename');
            $table->string('batch_id', 36)->nullable()->after('status');
        });

        // One row per piece of a long document; each is extracted by its own job.
        Schema::create('tender_pieces', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('tender_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('offset');
            $table->unsignedInteger('length');
            $table->string('status', 20);
            $table->json('result')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
            $table->unique(['tender_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_pieces');
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropColumn(['source_path', 'batch_id']);
        });
    }
};
