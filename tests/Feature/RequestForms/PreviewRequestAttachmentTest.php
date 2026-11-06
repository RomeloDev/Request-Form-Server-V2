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
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PreviewRequestAttachmentTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_requester_can_preview_their_request_attachment_inline(): void
    {
        $branch = $this->branch('REQUESTER');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        Storage::disk($attachment->disk)->put(
            $attachment->path,
            'private attachment contents',
        );

        Sanctum::actingAs($requester);

        $response = $this->get(
            $this->previewUrl($requestForm, $attachment),
        );

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $contentDisposition = (string) $response->headers->get(
            'Content-Disposition',
        );

        $this->assertStringContainsString('inline', $contentDisposition);
        $this->assertStringContainsString(
            'quotation.pdf',
            $contentDisposition,
        );
        $this->assertSame(
            'private attachment contents',
            $response->streamedContent(),
        );
    }

    public function test_assigned_approver_can_preview_request_attachment(): void
    {
        $branch = $this->branch('APPROVER');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        $this->approvalStep($requestForm, $approver);

        Storage::disk($attachment->disk)->put(
            $attachment->path,
            'approver-visible contents',
        );

        Sanctum::actingAs($approver);

        $response = $this->get(
            $this->previewUrl($requestForm, $attachment),
        );

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString(
            'inline',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            'approver-visible contents',
            $response->streamedContent(),
        );
    }

    #[DataProvider('previewableImageProvider')]
    public function test_requester_can_preview_an_image_inline(
        string $originalName,
        string $mimeType,
    ): void {
        $branch = $this->branch('IMAGE');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment(
            requestForm: $requestForm,
            originalName: $originalName,
            mimeType: $mimeType,
        );

        Storage::disk($attachment->disk)->put(
            $attachment->path,
            'fake png contents',
        );

        Sanctum::actingAs($requester);

        $response = $this->get(
            $this->previewUrl($requestForm, $attachment),
        );

        $response
            ->assertOk()
            ->assertHeader('Content-Type', $mimeType);

        $contentDisposition = (string) $response->headers->get(
            'Content-Disposition',
        );

        $this->assertStringContainsString('inline', $contentDisposition);
        $this->assertStringContainsString(
            $originalName,
            $contentDisposition,
        );
        $this->assertSame(
            'fake png contents',
            $response->streamedContent(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function previewableImageProvider(): array
    {
        return [
            'JPEG' => ['item-photo.jpg', 'image/jpeg'],
            'PNG' => ['item-photo.png', 'image/png'],
            'WEBP' => ['item-photo.webp', 'image/webp'],
        ];
    }

    public function test_document_file_cannot_be_opened_through_preview_endpoint(): void
    {
        $branch = $this->branch('DOCUMENT');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment(
            requestForm: $requestForm,
            originalName: 'supplier-quote.docx',
            mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        Storage::disk($attachment->disk)->put(
            $attachment->path,
            'fake docx contents',
        );

        Sanctum::actingAs($requester);

        $this->getJson($this->previewUrl($requestForm, $attachment))
            ->assertUnsupportedMediaType();
    }

    public function test_guest_cannot_preview_request_attachment(): void
    {
        $branch = $this->branch('GUEST');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        $this->getJson($this->previewUrl($requestForm, $attachment))
            ->assertUnauthorized();
    }

    public function test_unassigned_user_cannot_preview_request_attachment(): void
    {
        $branch = $this->branch('PRIVATE');
        $requester = $this->user($branch);
        $unassignedUser = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        Sanctum::actingAs($unassignedUser);

        $this->getJson($this->previewUrl($requestForm, $attachment))
            ->assertForbidden();
    }

    public function test_attachment_from_another_request_is_not_previewed(): void
    {
        $branch = $this->branch('MISMATCH');
        $requester = $this->user($branch);
        $ownedRequest = $this->requestForm($requester, $branch);
        $differentRequest = $this->requestForm($requester, $branch);
        $differentAttachment = $this->attachment($differentRequest);

        Storage::disk($differentAttachment->disk)->put(
            $differentAttachment->path,
            'must not be previewed through another request',
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->previewUrl($ownedRequest, $differentAttachment),
        )->assertNotFound();
    }

    public function test_missing_private_file_returns_not_found(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        Sanctum::actingAs($requester);

        $this->getJson($this->previewUrl($requestForm, $attachment))
            ->assertNotFound();
    }

    public function test_identity_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        $attachment = $this->attachment($requestForm);

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->previewUrl($requestForm, $attachment)
            .'?user_id=1&requester_id=1&branch_id=1',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
            ]);
    }

    private function previewUrl(
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

    private function attachment(
        RequestForm $requestForm,
        string $originalName = 'quotation.pdf',
        string $mimeType = 'application/pdf',
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
            'firstName' => "Preview{$number}",
            'lastName' => 'Requester',
            'contact' => "0917000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "preview-user-{$number}",
            'employee_id' => "PREVIEW-EMP-{$number}",
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
