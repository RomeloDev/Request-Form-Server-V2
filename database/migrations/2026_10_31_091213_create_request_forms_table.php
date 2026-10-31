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
        Schema::create('request_forms', function (Blueprint $table) {
            $table->id();

            /*
             * The authenticated user who submitted the request.
             *
             * We store this relationship on the server. The frontend will
             * never be allowed to choose requester_id.
             */
            $table->foreignId('requester_id')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             * The branch used to route the request.
             *
             * Laravel will derive this from the authenticated user's
             * branch_code instead of accepting a branch from the browser.
             */
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            /*
             * A server-generated human-readable identifier.
             * Example: SRL0000001
             */
            $table->string('request_code')->unique();

            /*
             * Examples:
             * - Stock Requisition Slip
             * - Purchase Order Requisition Slip
             */
            $table->string('form_type');

            /*
             * Optional business category selected by the requester.
             */
            $table->string('kind_of_request')->nullable();

            /*
             * ISO-style currency code such as PHP, USD, or EUR.
             */
            $table->string('currency', 3)->default('PHP');

            /*
             * Keep this as a string in the database.
             * We will control valid values through a PHP enum later.
             */
            $table->string('status')->default('Pending');

            /*
             * Type-specific header fields belong here.
             *
             * Examples:
             * - Stock request: purpose
             * - Purchase order: supplier and address
             * - Check request: payee and bank
             *
             * Line items do not belong in this JSON column.
             */
            $table->json('form_data')->nullable();

            /*
             * This value is recalculated by Laravel.
             * Never trust the total received from the frontend.
             */
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_forms');
    }
};
