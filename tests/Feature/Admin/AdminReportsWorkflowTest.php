<?php

namespace Tests\Feature\Admin;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalAttachment;
use App\Models\RequestApprovalStep;
use App\Models\RequestAttachment;
use App\Models\RequestForm;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminReportsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_complete_the_read_only_report_workflow(): void
    {
        $fixture = $this->workflowFixture();
        $requestForm = $fixture['request'];
        $originalStatus = $requestForm->status;
        $originalUpdatedAt = $requestForm->getRawOriginal('updated_at');

        Sanctum::actingAs($fixture['admin']);

        $this->getJson('/api/v1/admin/request-reports?'.http_build_query([
            'search' => $requestForm->request_code,
            'status' => RequestStatus::Completed->value,
            'branch_id' => $fixture['branch']->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestForm->id);

        $this->getJson($this->reportUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath('data.items.0.id', $fixture['item']->id)
            ->assertJsonPath(
                'data.attachments.0.id',
                $fixture['previewAttachment']->id,
            )
            ->assertJsonPath(
                'data.approval_steps.0.id',
                $fixture['step']->id,
            );

        $this->get($this->previewUrl(
            $requestForm,
            $fixture['previewAttachment'],
        ))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get($this->downloadUrl(
            $requestForm,
            $fixture['downloadAttachment'],
        ))
            ->assertOk()
            ->assertHeader('Content-Type', self::DOCX_MIME);

        $this->get($this->approvalPreviewUrl(
            $requestForm,
            $fixture['step'],
            $fixture['approvalAttachment'],
        ))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->get($this->approvalDownloadUrl(
            $requestForm,
            $fixture['step'],
            $fixture['approvalAttachment'],
        ))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath('data.requester.id', $fixture['owner']->id);

        $this->get($this->signatureUrl(
            $requestForm,
            $fixture['owner'],
        ))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $requestForm->refresh();

        $this->assertSame($originalStatus, $requestForm->status);
        $this->assertSame(
            $originalUpdatedAt,
            $requestForm->getRawOriginal('updated_at'),
        );
        $this->assertDatabaseCount('request_items', 1);
        $this->assertDatabaseCount('request_attachments', 2);
        $this->assertDatabaseCount('request_approval_steps', 1);
        $this->assertDatabaseCount('request_approval_attachments', 1);
    }

    public function test_revoking_admin_role_immediately_removes_report_access(): void
    {
        $fixture = $this->workflowFixture();
        $admin = $fixture['admin'];
        $requestForm = $fixture['request'];

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/request-reports')->assertOk();

        $admin->removeRole('Admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/v1/admin/request-reports')
            ->assertForbidden();
        $this->getJson($this->reportUrl($requestForm))
            ->assertForbidden();
        $this->getJson($this->previewUrl(
            $requestForm,
            $fixture['previewAttachment'],
        ))->assertForbidden();
        $this->getJson($this->printUrl($requestForm))
            ->assertForbidden();
    }

    public function test_soft_deleted_request_is_unavailable_through_every_report_path(): void
    {
        $fixture = $this->workflowFixture();
        $requestForm = $fixture['request'];
        $requestForm->delete();

        Sanctum::actingAs($fixture['admin']);

        $this->getJson('/api/v1/admin/request-reports?'.http_build_query([
            'search' => $requestForm->request_code,
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->getJson($this->reportUrl($requestForm))
            ->assertNotFound()
            ->assertJsonPath('message', 'Request report not found.');
        $this->getJson($this->previewUrl(
            $requestForm,
            $fixture['previewAttachment'],
        ))->assertNotFound();
        $this->getJson($this->printUrl($requestForm))
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
        $this->getJson($this->signatureUrl(
            $requestForm,
            $fixture['owner'],
        ))
            ->assertNotFound()
            ->assertJsonPath(
                'message',
                'Request or signature not found.',
            );
    }

    /**
     * @return array{
     *     admin: User,
     *     owner: User,
     *     branch: Branch,
     *     request: RequestForm,
     *     item: RequestItem,
     *     previewAttachment: RequestAttachment,
     *     downloadAttachment: RequestAttachment,
     *     step: RequestApprovalStep,
     *     approvalAttachment: RequestApprovalAttachment
     * }
     */
    private function workflowFixture(): array
    {
        $ownerBranch = $this->branch('OWNER');
        $adminBranch = $this->branch('ADMIN');
        $owner = $this->user(
            $ownerBranch,
            'User',
            'signatures/workflow-owner.png',
        );
        $admin = $this->user($adminBranch, 'Admin');
        $approver = $this->user(
            $ownerBranch,
            'approver',
            'signatures/workflow-approver.png',
        );
        $requestForm = $this->requestForm($owner, $ownerBranch);

        $item = new RequestItem([
            'quantity' => 2,
            'description' => 'Regression item',
            'unit_cost' => '50.00',
            'remarks' => 'Read-only workflow',
        ]);
        $item->line_number = 1;
        $item->line_total = '100.00';
        $requestForm->items()->save($item);

        $previewAttachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/workflow.pdf",
            'original_name' => 'workflow.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);
        $downloadAttachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/workflow.docx",
            'original_name' => 'workflow.docx',
            'mime_type' => self::DOCX_MIME,
            'size_bytes' => 100,
        ]);

        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = ApprovalStepStatus::Approved;
        $requestForm->approvalSteps()->save($step);

        $approvalAttachment = $step->attachments()->create([
            'disk' => 'local',
            'path' => "approval-steps/{$step->id}/workflow.png",
            'original_name' => 'workflow.png',
            'mime_type' => 'image/png',
            'size_bytes' => 100,
        ]);

        Storage::disk('local')->put(
            $previewAttachment->path,
            'preview contents',
        );
        Storage::disk('local')->put(
            $downloadAttachment->path,
            'download contents',
        );
        Storage::disk('local')->put(
            $approvalAttachment->path,
            'approval contents',
        );
        Storage::disk('public')->put(
            $owner->signature,
            UploadedFile::fake()
                ->image('workflow-owner.png', 300, 120)
                ->getContent(),
        );

        return [
            'admin' => $admin,
            'owner' => $owner,
            'branch' => $ownerBranch,
            'request' => $requestForm,
            'item' => $item,
            'previewAttachment' => $previewAttachment,
            'downloadAttachment' => $downloadAttachment,
            'step' => $step,
            'approvalAttachment' => $approvalAttachment,
        ];
    }

    private function reportUrl(RequestForm $requestForm): string
    {
        return "/api/v1/admin/request-reports/{$requestForm->id}";
    }

    private function printUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/print";
    }

    private function signatureUrl(
        RequestForm $requestForm,
        User $signatureOwner,
    ): string {
        return "/api/v1/request-forms/{$requestForm->id}/print/signatures/{$signatureOwner->id}";
    }

    private function previewUrl(
        RequestForm $requestForm,
        RequestAttachment $attachment,
    ): string {
        return route('request-forms.attachments.preview', [
            'requestForm' => $requestForm,
            'attachment' => $attachment,
        ], absolute: false);
    }

    private function downloadUrl(
        RequestForm $requestForm,
        RequestAttachment $attachment,
    ): string {
        return route('request-forms.attachments.download', [
            'requestForm' => $requestForm,
            'attachment' => $attachment,
        ], absolute: false);
    }

    private function approvalPreviewUrl(
        RequestForm $requestForm,
        RequestApprovalStep $step,
        RequestApprovalAttachment $attachment,
    ): string {
        return route('request-forms.approval-attachments.preview', [
            'requestForm' => $requestForm,
            'approvalStep' => $step,
            'approvalAttachment' => $attachment,
        ], absolute: false);
    }

    private function approvalDownloadUrl(
        RequestForm $requestForm,
        RequestApprovalStep $step,
        RequestApprovalAttachment $attachment,
    ): string {
        return route('request-forms.approval-attachments.download', [
            'requestForm' => $requestForm,
            'approvalStep' => $step,
            'approvalAttachment' => $attachment,
        ], absolute: false);
    }

    private function branch(string $label): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "FLOW-{$number}",
            'branch_name' => "{$label} Branch",
            'branch' => 'Test Company',
            'acronym' => 'FLOW',
        ]);
    }

    private function user(
        Branch $branch,
        string $role,
        ?string $signature = null,
    ): User {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'firstName' => "Workflow{$number}",
            'lastName' => 'User',
            'contact' => "0923000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "workflow-user-{$number}",
            'employee_id' => "WORKFLOW-EMP-{$number}",
            'signature' => $signature,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function requestForm(User $requester, Branch $branch): RequestForm
    {
        $requestForm = new RequestForm([
            'kind_of_request' => 'Office request',
            'currency' => 'PHP',
            'form_data' => ['purpose' => 'Regression testing.'],
        ]);
        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = RequestStatus::Completed;
        $requestForm->grand_total = '100.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
