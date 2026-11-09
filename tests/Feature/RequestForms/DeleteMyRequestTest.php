<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeleteMyRequestTest extends TestCase
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

    public function test_guest_cannot_delete_a_request(): void
    {
        $branch = $this->branch('GUEST');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        $this->deleteJson($this->deleteUrl($requestForm))
            ->assertUnauthorized();
    }

    public function test_another_user_cannot_delete_the_request(): void
    {
        $branch = $this->branch('PRIVATE');
        $requester = $this->user($branch);
        $otherUser = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($otherUser);

        $this->deleteJson($this->deleteUrl($requestForm))
            ->assertForbidden();

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'deleted_at' => null,
        ]);
    }

    public function test_owner_without_an_application_role_cannot_delete_the_request(): void
    {
        $branch = $this->branch('NO-ROLE');
        $requester = $this->user($branch, role: null);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->deleteJson($this->deleteUrl($requestForm))
            ->assertForbidden();
    }

    public function test_owner_can_soft_delete_a_request_without_deleting_audit_data_or_files(): void
    {
        Storage::fake('local');

        $branch = $this->branch('OWNER');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $item = $this->item($requestForm);
        $step = $this->approvalStep($requestForm, $approver);
        $attachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/quotation.pdf",
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put(
            $attachment->path,
            'private attachment',
        );

        Sanctum::actingAs($requester);

        $this->deleteJson($this->deleteUrl($requestForm))
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request deleted successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id);

        $this->assertSoftDeleted('request_forms', [
            'id' => $requestForm->id,
        ]);
        $this->assertDatabaseHas('request_items', [
            'id' => $item->id,
            'request_form_id' => $requestForm->id,
        ]);
        $this->assertDatabaseHas('request_approval_steps', [
            'id' => $step->id,
            'request_form_id' => $requestForm->id,
        ]);
        $this->assertDatabaseHas('request_attachments', [
            'id' => $attachment->id,
            'request_form_id' => $requestForm->id,
        ]);
        Storage::disk('local')->assertExists($attachment->path);

        $this->getJson("/api/v1/request-forms/{$requestForm->id}")
            ->assertNotFound();

        $this->getJson('/api/v1/request-forms')
            ->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonCount(0, 'data');
    }

    #[DataProvider('requestStatusProvider')]
    public function test_owner_can_delete_a_request_in_any_status(
        RequestStatus $status,
    ): void {
        $branch = $this->branch('STATUS');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: $status,
        );

        Sanctum::actingAs($requester);

        $this->deleteJson($this->deleteUrl($requestForm))
            ->assertOk();

        $this->assertSoftDeleted('request_forms', [
            'id' => $requestForm->id,
            'status' => $status->value,
        ]);
    }

    /**
     * @return array<string, array{RequestStatus}>
     */
    public static function requestStatusProvider(): array
    {
        return [
            'pending' => [RequestStatus::Pending],
            'ongoing' => [RequestStatus::Ongoing],
            'completed' => [RequestStatus::Completed],
            'disapproved' => [RequestStatus::Disapproved],
            'canceled' => [RequestStatus::Canceled],
        ];
    }

    public function test_deleted_or_missing_request_returns_custom_not_found_response(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $requestForm->delete();

        $this->deleteJson($this->deleteUrl($requestForm))
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    private function deleteUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}";
    }

    private function branch(string $code): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "{$code}-{$number}",
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Test Company',
            'acronym' => 'TEST',
        ]);
    }

    private function user(
        Branch $branch,
        ?string $role = 'User',
    ): User {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'firstName' => "Delete{$number}",
            'lastName' => 'Requester',
            'contact' => "0916000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "delete-requester-{$number}",
            'employee_id' => "DELETE-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

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
            'form_data' => ['purpose' => 'Office use'],
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

    private function item(RequestForm $requestForm): RequestItem
    {
        $item = new RequestItem([
            'quantity' => 2,
            'description' => 'Bond paper',
            'unit_cost' => '125.00',
            'remarks' => 'A4 size',
        ]);
        $item->line_number = 1;
        $item->line_total = '250.00';
        $requestForm->items()->save($item);

        return $item;
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
}
