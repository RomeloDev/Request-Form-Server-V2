<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_approval_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_approval_step_id')
                ->constrained('request_approval_steps')
                ->cascadeOnDelete();
            $table->string('disk', 50);
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_approval_attachments');
    }
};
