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
            // The items as a person decided them on the review screen; packages and RFQs use these once set.
            $table->json('review')->nullable()->after('extraction');
            $table->timestamp('reviewed_at')->nullable()->after('review');
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropColumn(['review', 'reviewed_at']);
        });
    }
};
