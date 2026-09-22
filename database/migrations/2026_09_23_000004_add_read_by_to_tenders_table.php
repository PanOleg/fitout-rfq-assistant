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
            // How the document text was obtained (FitOut\Ingestion\ReadDocument): text, pdf-layout, ocr…
            $table->string('read_by', 20)->nullable()->after('source_filename');
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropColumn('read_by');
        });
    }
};
