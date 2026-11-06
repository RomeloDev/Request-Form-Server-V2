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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShowMyRequestTest extends TestCase
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
        $this->getJson('/api/v1/request-forms/999999')
            ->assertUnauthorized();
    }

    public function test_owner_without_an_application_role_is_forbidden(): void
    {
        $branch = $this->branch('NO-ROLE');
        $owner = $this->user($branch, role: null);
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/request-forms/{$requestForm->id}")
            ->assertForbidden();
    }

    public function test_another_user_cannot_view_the_request(): void
    {
        $branch = $this->branch('PRIVATE');
        $owner = $this->user($branch);
        $otherUser = $this->user($branch);
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($otherUser);

        $this->getJson("/api/v1/request-forms/{$requestForm->id}")
            ->assertForbidden();
    }

    public function test_missing_request_returns_the_custom_not_found_response(): void
    {
        $user = $this->user($this->branch('MISSING'));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/request-forms/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    public function test_owner_can_view_complete_request_details(): void
    {
        $branch = $this->branch('DETAIL');
        $owner = $this->user($branch);
        $approver = $this->user(
            branch: $branch,
            role: 'approver',
            position: 'Area Manager',
        );
        $requestForm = $this->requestForm($owner, $branch);

        $item = new RequestItem([
            'quantity' => 2,
            'unit' => 'box',
            'description' => 'Bond paper',
            'unit_cost' => '125.00',
            'remarks' => 'A4 size',
        ]);
        $item->line_number = 1;
        $item->line_total = '250.00';
        $requestForm->items()->save($item);

        $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/private-document.pdf",
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = ApprovalStepStatus::Pending;
        $requestForm->approvalSteps()->save($step);

        Sanctum::actingAs($owner);

        $response = $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}",
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.request_code',
                $requestForm->request_code,
            )
            ->assertJsonPath('data.requester.id', $owner->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath(
                'data.items.0.description',
                'Bond paper',
            )
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'quotation.pdf',
            )
            ->assertJsonPath('data.approval_steps.0.level', 1)
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $approver->id,
            )
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path');
    }

    public function test_identity_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($owner);

        $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}?user_id=1&requester_id=1&branch_id=1",
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
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
        string $position = 'Employee',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Details{$number}",
            'lastName' => 'Requester',
            'contact' => "0919000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "details-requester-{$number}",
            'employee_id' => "DETAILS-EMP-{$number}",
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
