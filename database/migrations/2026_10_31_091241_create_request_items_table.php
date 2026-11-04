<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_form_id')->constrained('request_forms')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit')->nullable();
            $table->text('description')->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('line_total', 15, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->json('item_data')->nullable();
            $table->unique([
                'request_form_id',
                'line_number',
            ]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_items');
    }
};
