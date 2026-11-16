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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminReportAttachmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_attachment_routes_require_authentication(): void
    {
        $branch = $this->branch('GUEST');
        $requestForm = $this->requestForm($this->user($branch), $branch);
        $attachment = $this->requestAttachment($requestForm);

        $this->getJson($this->previewUrl($requestForm, $attachment))
            ->assertUnauthorized();
    }

    public function test_admin_can_preview_and_download_another_users_request_attachments(): void
    {
        $ownerBranch = $this->branch('OWNER');
        $adminBranch = $this->branch('ADMIN');
        $requestForm = $this->requestForm(
            $this->user($ownerBranch),
            $ownerBranch,
        );
        $preview = $this->requestAttachment($requestForm);
        $download = $this->requestAttachment(
            requestForm: $requestForm,
            originalName: 'report-document.docx',
            mimeType: self::DOCX_MIME,
        );

        Storage::disk('local')->put($preview->path, 'preview contents');
        Storage::disk('local')->put($download->path, 'download contents');

        Sanctum::actingAs($this->user($adminBranch, 'Admin'));

        $previewResponse = $this->get(
            $this->previewUrl($requestForm, $preview),
        );
        $previewResponse
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'inline',
            (string) $previewResponse->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            'preview contents',
            $previewResponse->streamedContent(),
        );

        $downloadResponse = $this->get(
            $this->downloadUrl($requestForm, $download),
        );
        $downloadResponse
            ->assertOk()
            ->assertHeader('Content-Type', self::DOCX_MIME)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'attachment',
            (string) $downloadResponse->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            'download contents',
            $downloadResponse->streamedContent(),
        );
    }

    public function test_admin_can_preview_and_download_an_approval_attachment(): void
    {
        $branch = $this->branch('APPROVAL');
        $requestForm = $this->requestForm($this->user($branch), $branch);
        $approver = $this->user($branch, 'approver');
        $step = $this->approvalStep($requestForm, $approver);
        $attachment = $this->approvalAttachment($step);

        Storage::disk('local')->put($attachment->path, 'approval proof');
        Sanctum::actingAs($this->user($branch, 'Admin'));

        $preview = $this->get(
            $this->approvalPreviewUrl($requestForm, $step, $attachment),
        );
        $preview
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'inline',
            (string) $preview->headers->get('Content-Disposition'),
        );

        $download = $this->get(
            $this->approvalDownloadUrl($requestForm, $step, $attachment),
        );
        $download
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'attachment',
            (string) $download->headers->get('Content-Disposition'),
        );
        $this->assertSame('approval proof', $download->streamedContent());
    }

    public function test_unrelated_ordinary_user_remains_forbidden(): void
    {
        $branch = $this->branch('PRIVATE');
        $requestForm = $this->requestForm($this->user($branch), $branch);
        $attachment = $this->requestAttachment($requestForm);

        Sanctum::actingAs($this->user($branch));

        $this->getJson($this->previewUrl($requestForm, $attachment))
            ->assertForbidden();
    }

    public function test_admin_cannot_combine_a_request_with_another_requests_attachment(): void
    {
        $branch = $this->branch('CROSS-REQUEST');
        $owner = $this->user($branch);
        $firstRequest = $this->requestForm($owner, $branch);
        $secondRequest = $this->requestForm($owner, $branch);
        $secondAttachment = $this->requestAttachment($secondRequest);

        Storage::disk('local')->put($secondAttachment->path, 'private');
        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson(
            $this->previewUrl($firstRequest, $secondAttachment),
        )->assertNotFound();
    }

    public function test_admin_cannot_combine_an_approval_step_with_another_steps_attachment(): void
    {
        $branch = $this->branch('CROSS-STEP');
        $requestForm = $this->requestForm($this->user($branch), $branch);
        $firstStep = $this->approvalStep(
            $requestForm,
            $this->user($branch, 'approver'),
            1,
        );
        $secondStep = $this->approvalStep(
            $requestForm,
            $this->user($branch, 'approver'),
            2,
        );
        $secondAttachment = $this->approvalAttachment($secondStep);

        Storage::disk('local')->put($secondAttachment->path, 'private');
        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson($this->approvalPreviewUrl(
            $requestForm,
            $firstStep,
            $secondAttachment,
        ))->assertNotFound();
    }

    public function test_server_owned_attachment_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $requestForm = $this->requestForm($this->user($branch), $branch);
        $attachment = $this->requestAttachment($requestForm);
        $step = $this->approvalStep(
            $requestForm,
            $this->user($branch, 'approver'),
        );
        $approvalAttachment = $this->approvalAttachment($step);

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $requestQuery = http_build_query([
            'user_id' => 1,
            'requester_id' => 1,
            'branch_id' => 1,
            'attachment_id' => 1,
            'disk' => 'public',
            'path' => 'signatures/private.png',
        ]);
        $this->getJson(
            $this->previewUrl($requestForm, $attachment)."?{$requestQuery}",
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
                'attachment_id',
                'disk',
                'path',
            ]);

        $approvalQuery = http_build_query([
            'user_id' => 1,
            'requester_id' => 1,
            'branch_id' => 1,
            'approver_id' => 1,
            'approval_step_id' => 1,
            'approval_attachment_id' => 1,
            'disk' => 'public',
            'path' => 'signatures/private.png',
        ]);
        $this->getJson($this->approvalPreviewUrl(
            $requestForm,
            $step,
            $approvalAttachment,
        )."?{$approvalQuery}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
                'approver_id',
                'approval_step_id',
                'approval_attachment_id',
                'disk',
                'path',
            ]);
    }

    public function test_admin_cannot_use_an_endpoint_for_an_unsupported_mime_type(): void
    {
        $branch = $this->branch('MIME');
        $requestForm = $this->requestForm($this->user($branch), $branch);
        $docx = $this->requestAttachment(
            $requestForm,
            'preview-denied.docx',
            self::DOCX_MIME,
        );
        $pdf = $this->requestAttachment(
            $requestForm,
            'download-denied.pdf',
            'application/pdf',
        );
        $step = $this->approvalStep(
            $requestForm,
            $this->user($branch, 'approver'),
        );
        $approvalPdf = $this->approvalAttachment(
            $step,
            'approval-denied.pdf',
            'application/pdf',
        );

        Storage::disk('local')->put($docx->path, 'docx');
        Storage::disk('local')->put($pdf->path, 'pdf');
        Storage::disk('local')->put($approvalPdf->path, 'approval pdf');
        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson($this->previewUrl($requestForm, $docx))
            ->assertUnsupportedMediaType();
        $this->getJson($this->downloadUrl($requestForm, $pdf))
            ->assertUnsupportedMediaType();
        $this->getJson($this->approvalPreviewUrl(
            $requestForm,
            $step,
            $approvalPdf,
        ))->assertUnsupportedMediaType();
    }

    public function test_missing_private_files_return_not_found(): void
    {
        $branch = $this->branch('MISSING');
        $requestForm = $this->requestForm($this->user($branch), $branch);
        $preview = $this->requestAttachment($requestForm);
        $download = $this->requestAttachment(
            $requestForm,
            'missing.docx',
            self::DOCX_MIME,
        );
        $step = $this->approvalStep(
            $requestForm,
            $this->user($branch, 'approver'),
        );
        $approval = $this->approvalAttachment($step);

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson($this->previewUrl($requestForm, $preview))
            ->assertNotFound();
        $this->getJson($this->downloadUrl($requestForm, $download))
            ->assertNotFound();
        $this->getJson($this->approvalPreviewUrl(
            $requestForm,
            $step,
            $approval,
        ))->assertNotFound();
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

    private function requestAttachment(
        RequestForm $requestForm,
        string $originalName = 'report.pdf',
        string $mimeType = 'application/pdf',
    ): RequestAttachment {
        $number = ++$this->sequence;

        return $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/{$number}-{$originalName}",
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => 100,
        ]);
    }

    private function approvalAttachment(
        RequestApprovalStep $step,
        string $originalName = 'approval.png',
        string $mimeType = 'image/png',
    ): RequestApprovalAttachment {
        $number = ++$this->sequence;

        return $step->attachments()->create([
            'disk' => 'local',
            'path' => "approval-steps/{$step->id}/{$number}-{$originalName}",
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => 100,
        ]);
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        int $level = 1,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = ApprovalStepStatus::Approved;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }

    private function branch(string $label): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "FILE-{$number}",
            'branch_name' => "{$label} Branch",
            'branch' => 'Test Company',
            'acronym' => 'FILE',
        ]);
    }

    private function user(Branch $branch, string $role = 'User'): User
    {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'firstName' => "File{$number}",
            'lastName' => 'User',
            'contact' => "0921000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "file-user-{$number}",
            'employee_id' => "FILE-EMP-{$number}",
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function requestForm(User $requester, Branch $branch): RequestForm
    {
        $requestForm = new RequestForm([
            'kind_of_request' => 'Office request',
            'currency' => 'PHP',
            'form_data' => ['purpose' => 'Testing Admin file access.'],
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
