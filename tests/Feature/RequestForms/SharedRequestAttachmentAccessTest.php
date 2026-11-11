<?php

namespace Tests\Feature\RequestForms;

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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SharedRequestAttachmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const SECRET_TOKEN = 'SECRET-STORAGE-TOKEN';

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

    public function test_shared_recipient_can_preview_previewable_request_attachment(): void
    {
        $branch = $this->branch('SHARED-PREVIEW');
        $requester = $this->user($branch);
        $recipient = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->requestAttachment($requestForm);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Storage::disk('local')->put(
            $attachment->path,
            'shared recipient preview contents',
        );

        Sanctum::actingAs($recipient);

        $response = $this->get(
            $this->requestPreviewUrl($requestForm, $attachment),
        );

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertPrivateCacheResponse($response);
        $this->assertStringContainsString(
            'inline',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            'shared recipient preview contents',
            $response->streamedContent(),
        );
    }

    public function test_shared_recipient_can_download_permitted_document_attachment(): void
    {
        $branch = $this->branch('SHARED-DOWNLOAD');
        $requester = $this->user($branch);
        $recipient = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->requestAttachment(
            requestForm: $requestForm,
            originalName: 'supplier-quote.docx',
            mimeType: self::DOCX_MIME,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Storage::disk('local')->put(
            $attachment->path,
            'shared recipient download contents',
        );

        Sanctum::actingAs($recipient);

        $response = $this->get(
            $this->requestDownloadUrl($requestForm, $attachment),
        );

        $response
            ->assertOk()
            ->assertDownload('supplier-quote.docx')
            ->assertHeader('Content-Type', self::DOCX_MIME);

        $this->assertPrivateCacheResponse($response);
        $this->assertSame(
            'shared recipient download contents',
            $response->streamedContent(),
        );
    }

    public function test_shared_recipient_can_preview_approved_attachment(): void
    {
        $branch = $this->branch('SHARED-APPROVED-PREVIEW');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $recipient = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $step = $this->approvalStep($requestForm, $approver);
        $attachment = $this->approvalAttachment($step, 'approval-proof.png', 'image/png');

        $requestForm->sharedRecipients()->attach($recipient->id);

        Storage::disk('local')->put(
            $attachment->path,
            'approved preview contents',
        );

        Sanctum::actingAs($recipient);

        $response = $this->get(
            $this->approvalPreviewUrl($requestForm, $step, $attachment),
        );

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->assertPrivateCacheResponse($response);
        $this->assertStringContainsString(
            'inline',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            'approved preview contents',
            $response->streamedContent(),
        );
    }

    public function test_shared_recipient_can_download_approved_attachment(): void
    {
        $branch = $this->branch('SHARED-APPROVED-DOWNLOAD');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $recipient = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $step = $this->approvalStep($requestForm, $approver);
        $attachment = $this->approvalAttachment($step, 'approval-proof.png', 'image/png');

        $requestForm->sharedRecipients()->attach($recipient->id);

        Storage::disk('local')->put(
            $attachment->path,
            'approved download contents',
        );

        Sanctum::actingAs($recipient);

        $response = $this->get(
            $this->approvalDownloadUrl($requestForm, $step, $attachment),
        );

        $response
            ->assertOk()
            ->assertDownload('approval-proof.png')
            ->assertHeader('Content-Type', 'image/png');

        $this->assertPrivateCacheResponse($response);
        $this->assertSame(
            'approved download contents',
            $response->streamedContent(),
        );
    }

    public function test_unshared_user_receives_forbidden(): void
    {
        $branch = $this->branch('UNSHARED');
        $requester = $this->user($branch);
        $unsharedUser = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->requestAttachment($requestForm);

        Storage::disk('local')->put(
            $attachment->path,
            'unshared user must not read this',
        );

        Sanctum::actingAs($unsharedUser);

        $this->getJson(
            $this->requestPreviewUrl($requestForm, $attachment),
        )->assertForbidden();
    }

    public function test_roleless_shared_recipient_receives_forbidden(): void
    {
        $branch = $this->branch('ROLELESS');
        $requester = $this->user($branch);
        $rolelessRecipient = $this->user($branch, null);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->requestAttachment($requestForm);

        $requestForm->sharedRecipients()->attach(
            $rolelessRecipient->id
        );

        Storage::disk('local')->put(
            $attachment->path,
            'roleless recipient must not read this',
        );

        Sanctum::actingAs($rolelessRecipient);

        $this->getJson(
            $this->requestPreviewUrl($requestForm, $attachment),
        )->assertForbidden();
    }

    public function test_revoked_recipient_loses_attachment_access(): void
    {
        $branch = $this->branch('REVOKED');
        $requester = $this->user($branch);
        $recipient = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->requestAttachment($requestForm);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Storage::disk('local')->put(
            $attachment->path,
            'revoked recipient contents',
        );

        Sanctum::actingAs($recipient);

        $this->get($this->requestPreviewUrl($requestForm, $attachment))
            ->assertOk();

        $requestForm->sharedRecipients()->detach($recipient->id);

        $this->getJson(
            $this->requestPreviewUrl($requestForm, $attachment),
        )->assertForbidden();
    }

    public function test_share_for_another_request_grants_no_access(): void
    {
        $branch = $this->branch('OTHER-SHARE');
        $requester = $this->user($branch);
        $recipient = $this->user($branch);

        $sharedRequest = $this->requestForm($requester, $branch);
        $unsharedRequest = $this->requestForm($requester, $branch);
        $attachment = $this->requestAttachment($unsharedRequest);

        $sharedRequest->sharedRecipients()->attach($recipient->id);

        Storage::disk('local')->put(
            $attachment->path,
            'share on another request must not grant access',
        );

        Sanctum::actingAs($recipient);

        $this->getJson(
            $this->requestPreviewUrl($unsharedRequest, $attachment),
        )->assertForbidden();
    }

    public function test_cross_request_attachment_ids_remain_inaccessible(): void
    {
        $branch = $this->branch('CROSS-REQUEST');
        $requester = $this->user($branch);
        $recipient = $this->user($branch);

        $sharedRequest = $this->requestForm($requester, $branch);
        $otherRequest = $this->requestForm($requester, $branch);

        $otherRequestAttachment = $this->requestAttachment($otherRequest);

        $sharedRequest->sharedRecipients()->attach($recipient->id);

        Storage::disk('local')->put(
            $otherRequestAttachment->path,
            'cross-request attachment contents',
        );

        Sanctum::actingAs($recipient);

        // Scoped binding must reject an attachment that does not belong to
        // the request in the URL.
        $this->getJson(
            $this->requestPreviewUrl(
                $sharedRequest,
                $otherRequestAttachment,
            ),
        )->assertNotFound();
    }

    public function test_existing_requester_and_assigned_approver_access_still_passes(): void
    {
        $branch = $this->branch('REGRESSION');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        $previewable = $this->requestAttachment($requestForm);
        $downloadable = $this->requestAttachment(
            requestForm: $requestForm,
            originalName: 'supplier-quote.docx',
            mimeType: self::DOCX_MIME,
        );

        Storage::disk('local')->put(
            $previewable->path,
            'requester preview contents',
        );
        Storage::disk('local')->put(
            $downloadable->path,
            'approver download contents',
        );

        Sanctum::actingAs($requester);

        $this->get(
            $this->requestPreviewUrl($requestForm, $previewable),
        )
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->approvalStep($requestForm, $approver);

        Sanctum::actingAs($approver);

        $this->get(
            $this->requestDownloadUrl($requestForm, $downloadable),
        )
            ->assertOk()
            ->assertDownload('supplier-quote.docx');
    }

    public function test_storage_disk_and_path_are_not_exposed_in_error_responses(): void
    {
        $branch = $this->branch('PRIVACY');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $unsharedUser = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->requestAttachment(
            requestForm: $requestForm,
            path: 'request-forms/private/'.self::SECRET_TOKEN.'.pdf',
        );
        $step = $this->approvalStep($requestForm, $approver);
        $approvalAttachment = $this->approvalAttachment(
            $step,
            'approval-proof.png',
            'image/png',
            'request-forms/private/'.self::SECRET_TOKEN.'.png',
        );

        Sanctum::actingAs($requester);

        // Both files are missing from the fake disk, so the controller aborts
        // without ever describing where it looked for them.
        $requestResponse = $this->getJson(
            $this->requestPreviewUrl($requestForm, $attachment),
        );

        $approvalResponse = $this->getJson(
            $this->approvalPreviewUrl(
                $requestForm,
                $step,
                $approvalAttachment,
            ),
        );

        Sanctum::actingAs($unsharedUser);

        $forbiddenResponse = $this->getJson(
            $this->requestPreviewUrl($requestForm, $attachment),
        );

        $requestResponse
            ->assertNotFound()
            ->assertJsonPath(
                'message',
                'Attachment file not found.',
            );

        $approvalResponse
            ->assertNotFound()
            ->assertJsonPath(
                'message',
                'Approved attachment file not found.',
            );

        $forbiddenResponse->assertForbidden();

        foreach ([
            $requestResponse,
            $approvalResponse,
            $forbiddenResponse,
        ] as $response) {
            $body = (string) $response->getContent();

            $this->assertStringNotContainsString(
                self::SECRET_TOKEN,
                $body,
            );
            $this->assertStringNotContainsString(
                (string) $attachment->path,
                $body,
            );
            $this->assertStringNotContainsString(
                (string) $approvalAttachment->path,
                $body,
            );
            $this->assertArrayNotHasKey('disk', $response->json());
            $this->assertArrayNotHasKey('path', $response->json());
        }
    }

    /**
     * Symfony normalizes cache directives, so assert the directives instead
     * of depending on their ordering.
     */
    private function assertPrivateCacheResponse(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    private function requestPreviewUrl(
        RequestForm $requestForm,
        RequestAttachment $attachment,
    ): string {
        return route(
            'request-forms.attachments.preview',
            [
                'requestForm' => $requestForm,
                'attachment' => $attachment,
            ],
            absolute: false,
        );
    }

    private function requestDownloadUrl(
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

    private function approvalPreviewUrl(
        RequestForm $requestForm,
        RequestApprovalStep $step,
        RequestApprovalAttachment $attachment,
    ): string {
        return route(
            'request-forms.approval-attachments.preview',
            [
                'requestForm' => $requestForm,
                'approvalStep' => $step,
                'approvalAttachment' => $attachment,
            ],
            absolute: false,
        );
    }

    private function approvalDownloadUrl(
        RequestForm $requestForm,
        RequestApprovalStep $step,
        RequestApprovalAttachment $attachment,
    ): string {
        return route(
            'request-forms.approval-attachments.download',
            [
                'requestForm' => $requestForm,
                'approvalStep' => $step,
                'approvalAttachment' => $attachment,
            ],
            absolute: false,
        );
    }

    private function requestAttachment(
        RequestForm $requestForm,
        string $originalName = 'quotation.pdf',
        string $mimeType = 'application/pdf',
        ?string $path = null,
    ): RequestAttachment {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        return $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => $path
                ?? "request-forms/{$requestForm->id}/private-document.{$extension}",
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

    private function approvalAttachment(
        RequestApprovalStep $step,
        string $originalName,
        string $mimeType,
        ?string $path = null,
    ): RequestApprovalAttachment {
        return $step->approvalAttachments()->create([
            'disk' => 'local',
            'path' => $path
                ?? "request-forms/{$step->request_form_id}/approval/private-approval."
                .pathinfo($originalName, PATHINFO_EXTENSION),
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => strlen('approved attachment contents'),
        ]);
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
        ?string $role = 'User',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Shared{$number}",
            'lastName' => 'Recipient',
            'contact' => "0918000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "shared-attachment-user-{$number}",
            'employee_id' => "SHARED-ATTACHMENT-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

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
