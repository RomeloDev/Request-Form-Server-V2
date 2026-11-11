<?php

namespace Tests\Feature\RequestForms;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListSharedRequestsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_guest_receives_unauthorized(): void
    {
        $branch = $this->branch('GUEST');
        $requester = $this->user($branch, 'User', 'GuestRequester');
        $recipient = $this->user($branch, 'User', 'GuestRecipient');
        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->sharedRecipients()->attach($recipient->id);

        $this->getJson('/api/v1/shared-requests')
            ->assertUnauthorized();
    }

    public function test_approved_authenticated_roles_can_access_the_endpoint(): void
    {
        $branch = $this->branch('ROLES');
        $requester = $this->user($branch, 'User', 'RoleRequester');
        $requestForm = $this->requestForm($requester, $branch);

        foreach (['Admin', 'User', 'approver'] as $index => $role) {
            $recipient = $this->user(
                $branch,
                $role,
                "RoleRecipient{$index}",
            );

            $requestForm->sharedRecipients()->attach($recipient->id);

            Sanctum::actingAs($recipient);

            $this->getJson('/api/v1/shared-requests')
                ->assertOk()
                ->assertJsonPath(
                    'message',
                    'Shared requests retrieved successfully.',
                )
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', $requestForm->id);
        }
    }

    public function test_current_user_receives_only_requests_shared_with_them(): void
    {
        $branch = $this->branch('CURRENT');
        $requester = $this->user($branch, 'User', 'CurrentRequester');
        $currentUser = $this->user($branch, 'User', 'CurrentRecipient');
        $otherRecipient = $this->user(
            $branch,
            'User',
            'OtherRecipient',
        );

        $sharedWithCurrentUser = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'CURRENT-SHARED-001',
        );
        $sharedWithOtherUser = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'CURRENT-OTHER-001',
        );

        $sharedWithCurrentUser->sharedRecipients()->attach(
            $currentUser->id
        );
        $sharedWithOtherUser->sharedRecipients()->attach(
            $otherRecipient->id
        );

        Sanctum::actingAs($currentUser);

        $response = $this->getJson('/api/v1/shared-requests');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $sharedWithCurrentUser->id,
            );
    }

    public function test_another_recipients_shares_are_excluded(): void
    {
        $branch = $this->branch('ANOTHER');
        $requester = $this->user($branch, 'User', 'AnotherRequester');
        $currentUser = $this->user($branch, 'User', 'AnotherCurrent');
        $otherRecipient = $this->user(
            $branch,
            'User',
            'AnotherOther',
        );

        $currentRequest = $this->requestForm($requester, $branch);
        $otherRequest = $this->requestForm($requester, $branch);

        $currentRequest->sharedRecipients()->attach($currentUser->id);
        $otherRequest->sharedRecipients()->attach($otherRecipient->id);

        Sanctum::actingAs($currentUser);

        $response = $this->getJson('/api/v1/shared-requests');

        $returnedIds = collect(
            $response->json('data')
        )->pluck('id')->all();

        $this->assertSame([$currentRequest->id], $returnedIds);
        $this->assertNotContains($otherRequest->id, $returnedIds);
    }

    public function test_unshared_and_soft_deleted_requests_are_excluded(): void
    {
        $branch = $this->branch('EXCLUDE');
        $requester = $this->user($branch, 'User', 'ExcludeRequester');
        $recipient = $this->user($branch, 'User', 'ExcludeRecipient');

        $visibleRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'EXCLUDE-VISIBLE-001',
        );
        $unsharedRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'EXCLUDE-UNSHARED-001',
        );
        $softDeletedRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'EXCLUDE-DELETED-001',
        );

        $visibleRequest->sharedRecipients()->attach($recipient->id);
        $softDeletedRequest->sharedRecipients()->attach($recipient->id);
        $softDeletedRequest->delete();

        Sanctum::actingAs($recipient);

        $response = $this->getJson('/api/v1/shared-requests');

        $returnedIds = collect(
            $response->json('data')
        )->pluck('id')->all();

        $this->assertSame([$visibleRequest->id], $returnedIds);
        $this->assertNotContains($unsharedRequest->id, $returnedIds);
        $this->assertNotContains($softDeletedRequest->id, $returnedIds);
    }

    public function test_search_matches_all_approved_fields(): void
    {
        $branch = $this->branch('SEARCH');
        $neutralBranch = $this->branch('NEUTRAL');

        $requester = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'SearchFirstNameToken',
            lastName: 'SearchLastNameToken',
        );
        $neutralRequester = $this->user(
            $neutralBranch,
            'User',
            'NeutralRequester',
        );
        $recipient = $this->user($branch, 'User', 'SearchRecipient');

        $targetRequest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            formType: RequestFormType::PurchaseOrder,
            kindOfRequest: 'SearchKindToken',
            requestCode: 'SEARCH-CODE-TOKEN',
        );
        $neutralRequest = $this->requestForm(
            requester: $neutralRequester,
            branch: $neutralBranch,
            formType: RequestFormType::StockRequisition,
            kindOfRequest: 'NeutralKind',
            requestCode: 'NEUTRAL-CODE',
        );

        $targetRequest->sharedRecipients()->attach($recipient->id);
        $neutralRequest->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        foreach ([
            'SEARCH-CODE-TOKEN',
            RequestFormType::PurchaseOrder->value,
            'SearchKindToken',
            'SearchFirstNameToken',
            'SearchLastNameToken',
            $branch->branch_code,
            $branch->branch_name,
        ] as $term) {
            $response = $this->getJson(
                '/api/v1/shared-requests?search='.urlencode($term)
            );

            $response
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $targetRequest->id);
        }
    }

    public function test_pagination_and_allowed_per_page_values_work(): void
    {
        $branch = $this->branch('PAGE');
        $requester = $this->user($branch, 'User', 'PageRequester');
        $recipient = $this->user($branch, 'User', 'PageRecipient');

        $requests = [];

        for ($index = 1; $index <= 12; $index++) {
            $requestForm = $this->requestForm(
                $requester,
                $branch,
                requestCode: "PAGE-SHARED-{$index}",
            );

            $requestForm->sharedRecipients()->attach($recipient->id);
            $requests[] = $requestForm;
        }

        Sanctum::actingAs($recipient);

        $firstPage = $this->getJson(
            '/api/v1/shared-requests?page=1&per_page=10'
        );

        $firstPage
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'request_code',
                        'form_type',
                        'kind_of_request',
                        'status',
                        'requester' => [
                            'id',
                            'firstname',
                            'lastname',
                            'position',
                        ],
                        'branch' => [
                            'branch_code',
                            'branch_name',
                            'branch',
                        ],
                        'shared_at',
                        'created_at',
                    ],
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ],
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'links',
                    'path',
                    'per_page',
                    'to',
                    'total',
                ],
                'message',
            ])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(10, 'data');

        $secondPage = $this->getJson(
            '/api/v1/shared-requests?page=2&per_page=10'
        );

        $secondPage
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/shared-requests?per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonCount(12, 'data');

        $this->getJson('/api/v1/shared-requests?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonCount(12, 'data');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $branch = $this->branch('INVALID');
        $recipient = $this->user($branch, 'User', 'InvalidRecipient');
        $requester = $this->user($branch, 'User', 'InvalidRequester');
        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $search = str_repeat('a', 101);

        $this->getJson(
            "/api/v1/shared-requests?search={$search}&page=0&per_page=15"
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'search',
                'page',
                'per_page',
            ]);
    }

    public function test_prohibited_filters_are_rejected(): void
    {
        $branch = $this->branch('PROHIBITED');
        $recipient = $this->user($branch, 'User', 'ProhibitedRecipient');
        $requester = $this->user($branch, 'User', 'ProhibitedRequester');
        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            '/api/v1/shared-requests?user_id=1&recipient_id=1'
            .'&requester_id=1&branch_id=1&role=Admin&status=Completed'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'recipient_id',
                'requester_id',
                'branch_id',
                'role',
                'status',
            ]);
    }

    public function test_response_contains_only_approved_fields(): void
    {
        $branch = $this->branch('SAFE');
        $requester = $this->user($branch, 'User', 'SafeRequester');
        $recipient = $this->user($branch, 'User', 'SafeRecipient');

        $requester->forceFill([
            'signature' => 'sensitive-signature-data',
        ])->save();

        $requestForm = $this->requestForm($requester, $branch);
        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $response = $this->getJson('/api/v1/shared-requests');

        $response
            ->assertOk()
            ->assertJsonMissingPath('data.0.form_data')
            ->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.attachments')
            ->assertJsonMissingPath('data.0.approval_steps')
            ->assertJsonMissingPath('data.0.requester.email')
            ->assertJsonMissingPath('data.0.requester.contact')
            ->assertJsonMissingPath('data.0.requester.userName')
            ->assertJsonMissingPath('data.0.requester.employee_id')
            ->assertJsonMissingPath('data.0.requester.role')
            ->assertJsonMissingPath('data.0.requester.roles')
            ->assertJsonMissingPath('data.0.requester.signature')
            ->assertJsonMissingPath('data.0.branch.id')
            ->assertJsonMissingPath('data.0.branch.acronym');

        $this->assertSame(
            [
                'id',
                'request_code',
                'form_type',
                'kind_of_request',
                'status',
                'requester',
                'branch',
                'shared_at',
                'created_at',
            ],
            array_keys($response->json('data.0')),
        );

        $this->assertSame(
            ['id', 'firstname', 'lastname', 'position'],
            array_keys($response->json('data.0.requester')),
        );

        $this->assertSame(
            ['branch_code', 'branch_name', 'branch'],
            array_keys($response->json('data.0.branch')),
        );
    }

    public function test_shared_at_comes_from_the_pivot(): void
    {
        $branch = $this->branch('SHARED-AT');
        $requester = $this->user($branch, 'User', 'SharedAtRequester');
        $recipient = $this->user($branch, 'User', 'SharedAtRecipient');
        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->sharedRecipients()->attach($recipient->id);

        DB::table('request_shares')
            ->where('request_form_id', $requestForm->id)
            ->where('recipient_id', $recipient->id)
            ->update([
                'created_at' => '2026-11-11 08:15:30',
            ]);

        Sanctum::actingAs($recipient);

        $this->getJson('/api/v1/shared-requests')
            ->assertOk()
            ->assertJsonPath(
                'data.0.shared_at',
                Carbon::parse('2026-11-11 08:15:30')->toISOString(),
            );
    }

    public function test_requests_are_ordered_by_share_time_then_request_id(): void
    {
        $branch = $this->branch('ORDER');
        $requester = $this->user($branch, 'User', 'OrderRequester');
        $recipient = $this->user($branch, 'User', 'OrderRecipient');

        $olderRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'ORDER-OLDER',
        );
        $newerRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'ORDER-NEWER',
        );

        $olderRequest->sharedRecipients()->attach($recipient->id);
        $newerRequest->sharedRecipients()->attach($recipient->id);

        DB::table('request_shares')
            ->where('request_form_id', $olderRequest->id)
            ->update(['created_at' => '2026-11-10 09:00:00']);

        DB::table('request_shares')
            ->where('request_form_id', $newerRequest->id)
            ->update(['created_at' => '2026-11-11 09:00:00']);

        Sanctum::actingAs($recipient);

        $this->getJson('/api/v1/shared-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newerRequest->id)
            ->assertJsonPath('data.1.id', $olderRequest->id);
    }

    public function test_duplicate_rows_are_not_returned(): void
    {
        $branch = $this->branch('DUPLICATE');
        $requester = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'DuplicateToken',
        );
        $recipient = $this->user($branch, 'User', 'DuplicateRecipient');

        /*
         * The token matches both the request code and requester name, so a
         * careless join-based search could emit the same row twice.
         */
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'DuplicateToken',
        );
        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $response = $this->getJson(
            '/api/v1/shared-requests?search=DuplicateToken'
        );

        $returnedIds = collect(
            $response->json('data')
        )->pluck('id')->all();

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data');

        $this->assertSame(
            array_values(array_unique($returnedIds)),
            $returnedIds,
        );
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
        ?string $role,
        string $firstName,
        ?string $lastName = null,
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => $firstName,
            'lastName' => $lastName ?? "Example{$number}",
            'contact' => "0915000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "shared-requests-{$number}",
            'employee_id' => "SHARED-REQUESTS-EMP-{$number}",
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
        RequestFormType $formType = RequestFormType::StockRequisition,
        ?string $kindOfRequest = 'For Stock',
        ?string $requestCode = null,
    ): RequestForm {
        $number = ++$this->sequence;

        $requestForm = new RequestForm([
            'kind_of_request' => $kindOfRequest,
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing shared request listing.',
            ],
        ]);

        $requestForm->request_code = $requestCode
            ?? "SHARED-REQUESTS-SRL-{$number}";
        $requestForm->form_type = $formType;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
