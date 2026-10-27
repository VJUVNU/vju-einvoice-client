<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('einvoice.connection');
    }

    public function up(): void
    {
        Schema::create('einvoice_client_events', function (Blueprint $table) {
            $table->id();
            // {key}:{status} — hợp đồng: app idempotent theo data.key + data.status. Webhook và pull dùng chung.
            $table->string('dedupe_key', 191)->unique();
            $table->string('document_key', 64)->index();
            $table->string('status', 16);
            $table->string('event_id', 100)->nullable();
            $table->unsignedBigInteger('change_id')->nullable();
            $table->string('source', 16);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoice_client_events');
    }
};
