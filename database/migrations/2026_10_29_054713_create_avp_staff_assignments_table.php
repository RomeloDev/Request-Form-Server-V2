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
        Schema::create('avp_staff_assignments', function (Blueprint $table) {
            $table->id();
            /*
            * The official AVP Finance represented by this assignment.
            * Deleting the user also removes their routing configuration.
            */
            $table->foreignId('avp_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /*
            * The approver who processes requests on behalf of the AVP.
            *
            * unique() enforces the initial business rule that one staff
            * member can belong to only one AVP Staff assignment.
            */
            $table->foreignId('staff_user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamps();
        });

        Schema::create('avp_staff_assignment_branch',
            function (Blueprint $table): void {
                $table->foreignId('avp_staff_assignment_id')
                    ->constrained('avp_staff_assignments')
                    ->cascadeOnDelete();

                /*
                 * Do not silently delete a branch while it is part of an
                 * active routing configuration.
                 */
                $table->foreignId('branch_id')
                    ->constrained('branches')
                    ->restrictOnDelete();

                $table->timestamps();

                /*
                 * Prevent the same branch from being attached twice to the
                 * same assignment.
                 */
                $table->primary([
                    'avp_staff_assignment_id',
                    'branch_id',
                ]);

                /*
                 * A branch must route to only one AVP Staff assignment.
                 * This database constraint also protects against concurrent
                 * requests that pass application validation simultaneously.
                 */
                $table->unique('branch_id');
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('avp_staff_assignment_branch');
        Schema::dropIfExists('avp_staff_assignments');
    }
};
