<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
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

class DownloadRequestAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

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

    public function test_requester_can_download_their_docx_attachment(): void
    {
        $branch = $this->branch('REQUESTER');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment(
            requestForm: $requestForm,
            originalName: 'supplier-quote.docx',
            mimeType: self::DOCX_MIME,
        );

        Storage::disk($attachment->disk)->put(
            $attachment->path,
            'private docx contents',
        );

        Sanctum::actingAs($requester);

        $response = $this->get(
            $this->downloadUrl($requestForm, $attachment),
        );

        $response
            ->assertOk()
            ->assertDownload('supplier-quote.docx')
            ->assertHeader('Content-Type', self::DOCX_MIME);

        $this->assertSame(
            'private docx contents',
            $response->streamedContent(),
        );
    }

    public function test_assigned_approver_can_download_xlsx_attachment(): void
    {
        $branch = $this->branch('APPROVER');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment(
            requestForm: $requestForm,
            originalName: 'price-list.xlsx',
            mimeType: self::XLSX_MIME,
        );

        $this->approvalStep($requestForm, $approver);

        Storage::disk($attachment->disk)->put(
            $attachment->path,
            'private xlsx contents',
        );

        Sanctum::actingAs($approver);

        $response = $this->get(
            $this->downloadUrl($requestForm, $attachment),
        );

        $response
            ->assertOk()
            ->assertDownload('price-list.xlsx')
            ->assertHeader('Content-Type', self::XLSX_MIME);

        $this->assertSame(
            'private xlsx contents',
            $response->streamedContent(),
        );
    }

    public function test_guest_cannot_download_document_attachment(): void
    {
        $branch = $this->branch('GUEST');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        $this->getJson($this->downloadUrl($requestForm, $attachment))
            ->assertUnauthorized();
    }

    public function test_unassigned_user_cannot_download_document_attachment(): void
    {
        $branch = $this->branch('PRIVATE');
        $requester = $this->user($branch);
        $unassignedUser = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        Sanctum::actingAs($unassignedUser);

        $this->getJson($this->downloadUrl($requestForm, $attachment))
            ->assertForbidden();
    }

    public function test_attachment_from_another_request_is_not_downloaded(): void
    {
        $branch = $this->branch('MISMATCH');
        $requester = $this->user($branch);
        $ownedRequest = $this->requestForm($requester, $branch);
        $differentRequest = $this->requestForm($requester, $branch);
        $differentAttachment = $this->attachment($differentRequest);

        Storage::disk($differentAttachment->disk)->put(
            $differentAttachment->path,
            'must not be downloaded through another request',
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->downloadUrl($ownedRequest, $differentAttachment),
        )->assertNotFound();
    }

    public function test_missing_private_document_returns_not_found(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        Sanctum::actingAs($requester);

        $this->getJson($this->downloadUrl($requestForm, $attachment))
            ->assertNotFound();
    }

    public function test_previewable_file_cannot_be_opened_through_download_endpoint(): void
    {
        $branch = $this->branch('PREVIEW');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment(
            requestForm: $requestForm,
            originalName: 'quotation.pdf',
            mimeType: 'application/pdf',
        );

        Storage::disk($attachment->disk)->put(
            $attachment->path,
            'fake pdf contents',
        );

        Sanctum::actingAs($requester);

        $this->getJson($this->downloadUrl($requestForm, $attachment))
            ->assertUnsupportedMediaType();
    }

    public function test_identity_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->downloadUrl($requestForm, $attachment)
            .'?user_id=1&requester_id=1&branch_id=1',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
            ]);
    }

    private function downloadUrl(
        RequestForm $requestForm,
        RequestAttachment $attachment,
    ): string {
        return route(
            'request-forms.attachments.download',
            [
                'requestForm' => $requestForm,
                'attachment' => $attachment,
            ],
            absolute: false,
        );
    }

    private function attachment(
        RequestForm $requestForm,
        string $originalName = 'supplier-quote.docx',
        string $mimeType = self::DOCX_MIME,
    ): RequestAttachment {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        return $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/private-document.{$extension}",
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => strlen('private attachment contents'),
        ]);
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = ApprovalStepStatus::Pending;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }

    private function branch(string $code): Branch
    {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Test Company',
            'acronym' => $code,
        ]);
    }

    private function user(
        Branch $branch,
        string $role = 'User',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Download{$number}",
            'lastName' => 'Requester',
            'contact' => "0916000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "download-user-{$number}",
            'employee_id' => "DOWNLOAD-EMP-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Office use',
            ],
        ]);

        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = RequestStatus::Pending;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
