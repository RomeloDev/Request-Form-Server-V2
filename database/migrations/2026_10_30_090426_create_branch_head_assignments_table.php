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
        Schema::create('branch_head_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('branch_head_assignment_branch', function (Blueprint $table): void {
            $table->foreignId('branch_head_assignment_id')
                ->constrained('branch_head_assignments')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->timestamps();
            $table->primary([
                'branch_head_assignment_id',
                'branch_id',
            ]);
            $table->unique('branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the dependent pivot table first.
        Schema::dropIfExists('branch_head_assignment_branch');

        // The parent can now be safely removed.
        Schema::dropIfExists('branch_head_assignments');
    }
};
