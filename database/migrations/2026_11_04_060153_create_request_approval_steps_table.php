<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the ordered approval chain for each request.
     */
    public function up(): void
    {
        Schema::create(
            'request_approval_steps',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Approval steps belong entirely to their request.
                 * Permanently deleting the request removes its steps.
                 */
                $table->foreignId('request_form_id')
                    ->constrained('request_forms')
                    ->cascadeOnDelete();

                /*
                 * The user expected to perform this approval step.
                 *
                 * Restrict deletion to preserve approval and audit history.
                 */
                $table->foreignId('approver_id')
                    ->constrained('users')
                    ->restrictOnDelete();

                /*
                 * Position in the approval chain.
                 *
                 * The lowest Pending level is the current approver.
                 */
                $table->unsignedSmallInteger('level');

                /*
                 * Initial values will be:
                 * - noted_by
                 * - approved_by
                 *
                 * A PHP enum will control these values next.
                 */
                $table->string('step_type', 30);

                /*
                 * Initial lifecycle:
                 * Pending → Approved
                 * Pending → Disapproved
                 */
                $table->string('status', 30)
                    ->default('Pending');

                /*
                 * The approver may provide a comment with their decision.
                 */
                $table->text('comment')->nullable();

                /*
                 * Null while Pending; populated when the user acts.
                 */
                $table->timestamp('acted_at')->nullable();

                $table->timestamps();

                /*
                 * A request cannot have two steps at the same level.
                 */
                $table->unique([
                    'request_form_id',
                    'level',
                ]);

                /*
                 * The same user cannot appear twice in one request's
                 * resolved approval chain.
                 */
                $table->unique([
                    'request_form_id',
                    'approver_id',
                ]);

                /*
                 * Supports queries such as:
                 * "Show pending approvals assigned to this user."
                 */
                $table->index([
                    'approver_id',
                    'status',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('request_approval_steps');
    }
};
