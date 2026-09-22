<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('name');
            $table->string('region', 40);
            $table->date('return_by');
            $table->longText('document');
            $table->string('status', 20)->index();
            $table->json('extraction')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
