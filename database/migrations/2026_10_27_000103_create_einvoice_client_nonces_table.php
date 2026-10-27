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
        Schema::create('einvoice_client_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('sender', 64);
            $table->string('nonce', 64);
            $table->timestamp('used_at')->index();
            $table->unique(['sender', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoice_client_nonces');
    }
};
