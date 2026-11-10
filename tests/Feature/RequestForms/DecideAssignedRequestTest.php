<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalAttachment;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DecideAssignedRequestTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_route_requires_authentication(): void
    {
        $this->patchJson(
            '/api/v1/approver/request-forms/999999/decision',
            ['action' => 'approve'],
        )->assertUnauthorized();
    }

    public function test_non_approver_and_unassigned_approver_are_forbidden(): void
    {
        $branch = $this->branch('AUTH');
        $requester = $this->user($branch);
        $assignedApprover = $this->user($branch, 'approver');
        $unassignedApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep($requestForm, $assignedApprover, 1);

        Sanctum::actingAs($requester);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertForbidden();

        Sanctum::actingAs($unassignedApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertForbidden();
    }

    public function test_first_approval_moves_request_to_ongoing_and_preserves_next_step(): void
    {
        $branch = $this->branch('ONGOING');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $secondApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $firstStep = $this->approvalStep($requestForm, $firstApprover, 1);
        $secondStep = $this->approvalStep($requestForm, $secondApprover, 2);

        Sanctum::actingAs($firstApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
            'comment' => '  Verified and approved.  ',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Request approved successfully.')
            ->assertJsonPath('data.status', RequestStatus::Ongoing->value)
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Approved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.comment',
                'Verified and approved.',
            )
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Pending->value,
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Ongoing->value,
        ]);
        $this->assertDatabaseHas('request_approval_steps', [
            'id' => $firstStep->id,
            'status' => ApprovalStepStatus::Approved->value,
            'comment' => 'Verified and approved.',
        ]);
        $this->assertDatabaseHas('request_approval_steps', [
            'id' => $secondStep->id,
            'status' => ApprovalStepStatus::Pending->value,
            'acted_at' => null,
        ]);
        $this->assertNotNull($firstStep->fresh()->acted_at);
    }

    public function test_final_approval_completes_the_request(): void
    {
        $branch = $this->branch('COMPLETE');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $finalApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm(
            $requester,
            $branch,
            RequestStatus::Ongoing,
        );

        $this->approvalStep(
            requestForm: $requestForm,
            approver: $firstApprover,
            level: 1,
            status: ApprovalStepStatus::Approved,
        );
        $finalStep = $this->approvalStep(
            $requestForm,
            $finalApprover,
            2,
        );

        Sanctum::actingAs($finalApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Completed->value)
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Approved->value,
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Completed->value,
        ]);
        $this->assertNotNull($finalStep->fresh()->acted_at);
    }

    public function test_delegated_approver_can_upload_optional_approved_images(): void
    {
        Storage::fake('local');

        $branch = $this->branch('DELEGATED');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $step = $this->approvalStep(
            $requestForm,
            $approver,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::DelegatedApproval,
        );

        Sanctum::actingAs($approver);

        $this->post($this->decisionUrl($requestForm), [
            '_method' => 'PATCH',
            'action' => 'approve',
            'attachments' => [
                UploadedFile::fake()->image('signed-approval.png'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(1, 'data.approval_steps.0.attachments')
            ->assertJsonPath(
                'data.approval_steps.0.attachments.0.original_name',
                'signed-approval.png',
            );

        $attachment = RequestApprovalAttachment::query()->firstOrFail();

        $this->assertSame($step->id, $attachment->request_approval_step_id);
        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_non_delegated_and_disapproving_staff_cannot_upload_approved_attachments(): void
    {
        Storage::fake('local');

        $branch = $this->branch('UPLOAD-RULES');
        $requester = $this->user($branch);
        $ordinaryApprover = $this->user($branch, 'approver');
        $delegatedApprover = $this->user($branch, 'approver');

        $ordinaryRequest = $this->requestForm($requester, $branch);
        $this->approvalStep($ordinaryRequest, $ordinaryApprover, 1);

        Sanctum::actingAs($ordinaryApprover);

        $this->post($this->decisionUrl($ordinaryRequest), [
            '_method' => 'PATCH',
            'action' => 'approve',
            'attachments' => [UploadedFile::fake()->image('forged.png')],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);

        $delegatedRequest = $this->requestForm($requester, $branch);
        $this->approvalStep(
            $delegatedRequest,
            $delegatedApprover,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::DelegatedApproval,
        );

        Sanctum::actingAs($delegatedApprover);

        $this->post($this->decisionUrl($delegatedRequest), [
            '_method' => 'PATCH',
            'action' => 'disapprove',
            'comment' => 'The request needs correction.',
            'attachments' => [UploadedFile::fake()->image('not-approved.png')],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);

        $this->assertDatabaseCount('request_approval_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_approved_attachment_validates_type_size_and_count(): void
    {
        Storage::fake('local');

        $branch = $this->branch('UPLOAD-VALIDATION');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $this->approvalStep(
            $requestForm,
            $approver,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::DelegatedApproval,
        );

        Sanctum::actingAs($approver);

        $this->post($this->decisionUrl($requestForm), [
            '_method' => 'PATCH',
            'action' => 'approve',
            'attachments' => [
                UploadedFile::fake()->create('script.php', 10, 'application/x-httpd-php'),
                UploadedFile::fake()->create('too-large.png', 5121, 'image/png'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'attachments.0',
                'attachments.1',
            ]);

        $this->post($this->decisionUrl($requestForm), [
            '_method' => 'PATCH',
            'action' => 'approve',
            'attachments' => collect(range(1, 11))
                ->map(
                    fn (int $number): UploadedFile => UploadedFile::fake()
                        ->image("approved-{$number}.png"),
                )
                ->all(),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);

        $this->assertSame(
            ApprovalStepStatus::Pending,
            $requestForm->approvalSteps()->firstOrFail()->status,
        );
        $this->assertDatabaseCount('request_approval_attachments', 0);
    }

    public function test_approved_attachment_files_are_private_and_authorized(): void
    {
        Storage::fake('local');

        $branch = $this->branch('APPROVED-FILE');
        $requester = $this->user($branch);
        $assignedApprover = $this->user($branch, 'approver');
        $unassignedApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $step = $this->approvalStep(
            $requestForm,
            $assignedApprover,
            1,
            ApprovalStepStatus::Approved,
            ApprovalStepType::DelegatedApproval,
        );

        $path = "request-forms/{$requestForm->id}/approval-steps/{$step->id}/approved.png";
        Storage::disk('local')->put($path, 'approved-image');

        $attachment = $step->attachments()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'approved.png',
            'mime_type' => 'image/png',
            'size_bytes' => 14,
        ]);

        $previewUrl = $this->approvalAttachmentUrl(
            $requestForm,
            $step,
            $attachment,
            'preview',
        );
        $downloadUrl = $this->approvalAttachmentUrl(
            $requestForm,
            $step,
            $attachment,
            'download',
        );

        $this->getJson($previewUrl)->assertUnauthorized();

        Sanctum::actingAs($unassignedApprover);
        $this->getJson($previewUrl)->assertForbidden();

        Sanctum::actingAs($requester);
        $this->get($previewUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($downloadUrl)->assertOk()->assertDownload('approved.png');
        $this->getJson("{$previewUrl}?path=forged.png")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path']);

        Sanctum::actingAs($assignedApprover);
        $this->get($previewUrl)->assertOk();
    }

    public function test_disapproval_requires_comment_and_skips_later_steps(): void
    {
        $branch = $this->branch('REJECT');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $currentApprover = $this->user($branch, 'approver');
        $laterApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm(
            $requester,
            $branch,
            RequestStatus::Ongoing,
        );

        $firstStep = $this->approvalStep(
            $requestForm,
            $firstApprover,
            1,
            ApprovalStepStatus::Approved,
        );
        $currentStep = $this->approvalStep(
            $requestForm,
            $currentApprover,
            2,
        );
        $laterStep = $this->approvalStep(
            $requestForm,
            $laterApprover,
            3,
        );

        Sanctum::actingAs($currentApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'disapprove',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'disapprove',
            'comment' => 'Missing supplier quotation.',
        ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request disapproved successfully.',
            )
            ->assertJsonPath(
                'data.status',
                RequestStatus::Disapproved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Approved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Disapproved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.2.status',
                ApprovalStepStatus::Skipped->value,
            );

        $this->assertSame(
            ApprovalStepStatus::Approved,
            $firstStep->fresh()->status,
        );
        $this->assertSame(
            ApprovalStepStatus::Disapproved,
            $currentStep->fresh()->status,
        );
        $this->assertNotNull($currentStep->fresh()->acted_at);
        $this->assertSame(
            ApprovalStepStatus::Skipped,
            $laterStep->fresh()->status,
        );
        $this->assertNull($laterStep->fresh()->acted_at);
    }

    public function test_later_approver_cannot_act_before_every_earlier_step_is_approved(): void
    {
        $branch = $this->branch('ORDER');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $laterApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $firstStep = $this->approvalStep($requestForm, $firstApprover, 1);
        $laterStep = $this->approvalStep($requestForm, $laterApprover, 2);

        Sanctum::actingAs($laterApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'It is not yet your turn to process this request.',
            );

        $this->assertSame(
            ApprovalStepStatus::Pending,
            $firstStep->fresh()->status,
        );
        $this->assertSame(
            ApprovalStepStatus::Pending,
            $laterStep->fresh()->status,
        );
        $this->assertSame(
            RequestStatus::Pending,
            $requestForm->fresh()->status,
        );
    }

    public function test_terminal_non_approved_earlier_step_blocks_later_approver(): void
    {
        $branch = $this->branch('BLOCKED');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $laterApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm(
            $requester,
            $branch,
            RequestStatus::Ongoing,
        );

        $this->approvalStep(
            $requestForm,
            $firstApprover,
            1,
            ApprovalStepStatus::Skipped,
        );
        $laterStep = $this->approvalStep(
            $requestForm,
            $laterApprover,
            2,
        );

        Sanctum::actingAs($laterApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'It is not yet your turn to process this request.',
            );

        $this->assertSame(
            ApprovalStepStatus::Pending,
            $laterStep->fresh()->status,
        );
    }

    public function test_duplicate_and_terminal_request_decisions_are_rejected(): void
    {
        $branch = $this->branch('STALE');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep($requestForm, $approver, 1);

        Sanctum::actingAs($approver);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertOk();

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This request can no longer be processed.',
            );

        $this->assertSame(
            RequestStatus::Completed,
            $requestForm->fresh()->status,
        );
    }

    public function test_invalid_and_server_owned_fields_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep($requestForm, $approver, 1);

        Sanctum::actingAs($approver);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approved',
            'comment' => str_repeat('x', 2001),
            'attachment' => 'private-path.pdf',
            'attachments' => ['private-path.pdf'],
            'user_id' => $approver->id,
            'approver_id' => $approver->id,
            'approval_step_id' => 1,
            'level' => 1,
            'status' => 'Approved',
            'request_status' => 'Completed',
            'acted_at' => now()->toISOString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'action',
                'comment',
                'attachment',
                'attachments.0',
                'user_id',
                'approver_id',
                'approval_step_id',
                'level',
                'status',
                'request_status',
                'acted_at',
            ]);

        $this->assertSame(
            ApprovalStepStatus::Pending,
            RequestApprovalStep::findOrFail(1)->status,
        );
    }

    public function test_missing_and_soft_deleted_requests_return_custom_not_found_response(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($approver);

        $this->patchJson(
            '/api/v1/approver/request-forms/999999/decision',
            ['action' => 'approve'],
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Assigned request not found.');

        $requestForm = $this->requestForm($requester, $branch);
        $this->approvalStep($requestForm, $approver, 1);
        $requestForm->delete();

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Assigned request not found.');
    }

    private function decisionUrl(RequestForm $requestForm): string
    {
        return "/api/v1/approver/request-forms/{$requestForm->id}/decision";
    }

    private function approvalAttachmentUrl(
        RequestForm $requestForm,
        RequestApprovalStep $step,
        RequestApprovalAttachment $attachment,
        string $action,
    ): string {
        return "/api/v1/request-forms/{$requestForm->id}/approval-steps/{$step->id}/attachments/{$attachment->id}/{$action}";
    }

    private function branch(string $code): Branch
    {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Test Company',
            'acronym' => 'TEST',
        ]);
    }

    private function user(Branch $branch, string $role = 'User'): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Decision{$number}",
            'lastName' => 'User',
            'contact' => "0921000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "decision-user-{$number}",
            'employee_id' => "DECISION-EMP-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        RequestStatus $status = RequestStatus::Pending,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing approver decisions.',
            ],
        ]);

        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        int $level,
        ApprovalStepStatus $status = ApprovalStepStatus::Pending,
        ApprovalStepType $stepType = ApprovalStepType::ApprovedBy,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = $stepType;
        $step->status = $status;
        $step->acted_at = $status === ApprovalStepStatus::Approved
            ? now()
            : null;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }
}
