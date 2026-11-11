<?php

namespace Tests\Feature\RequestForms;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListMyRequestsTest extends TestCase
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
        $this->getJson('/api/v1/request-forms')
            ->assertUnauthorized();
    }

    public function test_account_without_an_application_role_is_forbidden(): void
    {
        $user = $this->user(
            branch: $this->branch('NO-ROLE'),
            role: null,
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/request-forms')
            ->assertForbidden();
    }

    public function test_user_receives_only_their_requests_in_newest_first_order(): void
    {
        $branch = $this->branch('OWNER');
        $requester = $this->user($branch);
        $otherUser = $this->user($branch);

        $older = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'OWNER-SRL-0000001',
            createdAt: now()->subDay(),
        );
        $newer = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'OWNER-SRL-0000002',
            createdAt: now(),
        );
        $otherRequest = $this->requestForm(
            requester: $otherUser,
            branch: $branch,
            requestCode: 'OTHER-SRL-0000003',
            createdAt: now()->addMinute(),
        );

        // The other user's share must not influence the owner's table.
        $otherRequest->sharedRecipients()->attach($otherUser->id);

        Sanctum::actingAs($requester);

        $response = $this->getJson('/api/v1/request-forms?per_page=1');

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Your requests retrieved successfully.',
            )
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.request_code', $newer->request_code)
            ->assertJsonPath('data.0.shared_count', 0)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ])
            ->assertJsonMissing([
                'id' => $otherRequest->id,
                'request_code' => $otherRequest->request_code,
            ]);

        $this->assertNotSame($older->id, $response->json('data.0.id'));
    }

    public function test_status_and_search_filters_are_applied_to_the_users_requests(): void
    {
        $branch = $this->branch('FILTER');
        $requester = $this->user($branch);

        $pending = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'FILTER-SRL-ALPHA',
            status: RequestStatus::Pending,
            kindOfRequest: 'Office Supplies',
        );
        $completed = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'FILTER-POR-BETA',
            status: RequestStatus::Completed,
            kindOfRequest: 'Warehouse Equipment',
            formType: RequestFormType::PurchaseOrder,
        );

        Sanctum::actingAs($requester);

        $this->getJson('/api/v1/request-forms?status=Pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/v1/request-forms?status=ALL')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/request-forms?search=ALPHA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/v1/request-forms?search=Warehouse')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completed->id);

        $this->getJson('/api/v1/request-forms?search=Purchase%20Order')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completed->id);
    }

    public function test_identity_overrides_and_invalid_filters_are_rejected(): void
    {
        $requester = $this->user($this->branch('VALIDATE'));

        Sanctum::actingAs($requester);

        $this->getJson(
            '/api/v1/request-forms?user_id=1&requester_id=1&branch_id=1&status=all&page=0&per_page=51',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
                'status',
                'page',
                'per_page',
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

    private function user(Branch $branch, ?string $role = 'User'): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "List{$number}",
            'lastName' => 'Requester',
            'contact' => "0918000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "list-requester-{$number}",
            'employee_id' => "LIST-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * Create a share recipient. Shares are counted only, so recipients do
     * not need a branch relationship for these listings.
     */
    private function recipient(): User
    {
        $number = ++$this->sequence;

        return User::factory()->create([
            'firstName' => "List{$number}",
            'lastName' => 'Recipient',
            'contact' => "0918000{$number}",
            'position' => 'Employee',
            'userName' => "list-recipient-{$number}",
            'employee_id' => "LIST-EMP-{$number}",
        ]);
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        string $requestCode,
        RequestStatus $status = RequestStatus::Pending,
        string $kindOfRequest = 'For Stock',
        RequestFormType $formType = RequestFormType::StockRequisition,
        mixed $createdAt = null,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => $kindOfRequest,
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing the My Requests listing.',
            ],
        ]);

        $requestForm->request_code = $requestCode;
        $requestForm->form_type = $formType;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';

        if ($createdAt !== null) {
            $requestForm->created_at = $createdAt;
            $requestForm->updated_at = $createdAt;
        }

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    public function test_summary_counts_all_owned_requests_independently_of_table_filters(): void
    {
        $branch = $this->branch('SUMMARY');
        $requester = $this->user($branch);
        $otherUser = $this->user($branch);

        $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'SUMMARY-PENDING-1',
            status: RequestStatus::Pending,
        );

        $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'SUMMARY-PENDING-2',
            status: RequestStatus::Pending,
        );

        foreach ([
            RequestStatus::Ongoing,
            RequestStatus::Completed,
            RequestStatus::Disapproved,
            RequestStatus::Canceled,
        ] as $index => $status) {
            $this->requestForm(
                requester: $requester,
                branch: $branch,
                requestCode: "SUMMARY-STATUS-{$index}",
                status: $status,
            );
        }

        // This request must not be included in the authenticated user's KPIs.
        $this->requestForm(
            requester: $otherUser,
            branch: $branch,
            requestCode: 'OTHER-PENDING',
            status: RequestStatus::Pending,
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            '/api/v1/request-forms?status=Pending&search=SUMMARY&per_page=1',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.total', 6)
            ->assertJsonPath('summary.pending', 2)
            ->assertJsonPath('summary.ongoing', 1)
            ->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.disapproved', 1)
            ->assertJsonPath('summary.canceled', 1);
    }

    public function test_owned_request_without_recipients_reports_zero_shared_count(): void
    {
        $branch = $this->branch('UNSHARED');
        $requester = $this->user($branch);

        $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'UNSHARED-SRL-1',
        );

        Sanctum::actingAs($requester);  

        $this->getJson('/api/v1/request-forms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shared_count', 0);
    }

    public function test_owned_request_shared_with_multiple_recipients_reports_the_count(): void
    {
        $branch = $this->branch('SHARED');
        $requester = $this->user($branch);

        $recipients = collect([$this->recipient(), $this->recipient(), $this->recipient()]);

        $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'SHARED-SRL-1',
            status: RequestStatus::Completed,
        )->sharedRecipients()->attach($recipients->pluck('id'));

        Sanctum::actingAs($requester);

        $response = $this->getJson('/api/v1/request-forms');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shared_count', 3);

        /*
         * Only the count is exposed; no recipient identities or pivot data.
         */
        $this->assertNotContains(
            'shared_recipients',
            array_keys($response->json('data.0')),
        );
    }

    public function test_another_requesters_shares_are_never_counted(): void
    {
        $branch = $this->branch('ISOLATION');
        $requester = $this->user($branch);
        $otherUser = $this->user($branch);

        $mine = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'MINE-SRL-1',
        );

        $theirs = $this->requestForm(
            requester: $otherUser,
            branch: $branch,
            requestCode: 'THEIRS-SRL-2',
            status: RequestStatus::Completed,
        );

        $theirs->sharedRecipients()->attach([
            $this->recipient()->id,
            $this->recipient()->id,
        ]);

        Sanctum::actingAs($requester);

        $this->getJson('/api/v1/request-forms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.shared_count', 0);
    }

    public function test_revoked_shares_are_not_counted(): void
    {
        $branch = $this->branch('REVOKE');
        $requester = $this->user($branch);

        $kept = $this->recipient();
        $revoked = $this->recipient();

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'REVOKE-SRL-1',
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach([$kept->id, $revoked->id]);

        $requestForm->sharedRecipients()->detach($revoked->id);

        Sanctum::actingAs($requester);

        $this->getJson('/api/v1/request-forms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestForm->id)
            ->assertJsonPath('data.0.shared_count', 1);
    }
}
