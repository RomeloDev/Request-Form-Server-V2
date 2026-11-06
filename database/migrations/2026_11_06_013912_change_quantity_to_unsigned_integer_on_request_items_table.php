<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Refuse to silently round, truncate, or accept quantities
         * outside the Stock Requisition business rule.
         */
        $unsafeQuantityExists = DB::table('request_items')
            ->whereNotNull('quantity')
            ->where(function ($query): void {
                $query
                    ->where('quantity', '<', 1)
                    ->orWhere('quantity', '>', 100000)
                    ->orWhereRaw(
                        'quantity <> ROUND(quantity, 0)'
                    );
            })
            ->exists();

        if ($unsafeQuantityExists) {
            throw new RuntimeException(
                'Request item quantities must be whole numbers between 1 and 100000 before changing the column type.'
            );
        }

        Schema::table('request_items', function (
            Blueprint $table
        ): void {
            $table
                ->unsignedInteger('quantity')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (
            Blueprint $table
        ): void {
            $table
                ->decimal('quantity', 12, 3)
                ->nullable()
                ->change();
        });
    }
};
