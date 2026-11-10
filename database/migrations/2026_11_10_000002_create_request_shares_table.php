<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the explicit sharing list for requests.
     */
    public function up(): void
    {
        Schema::create('request_shares', function (Blueprint $table): void {
            $table->id();

            /*
             * A share belongs entirely to its request.
             * Permanently deleting the request removes its shares.
             */
            $table->foreignId('request_form_id')
                ->constrained('request_forms')
                ->cascadeOnDelete();

            /*
             * The user who may view the shared request.
             *
             * Deleting the recipient's account removes their shares.
             */
            $table->foreignId('recipient_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamps();

            /*
             * A request can only be shared once with the same recipient.
             */
            $table->unique([
                'request_form_id',
                'recipient_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_shares');
    }
};
