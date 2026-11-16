<?php

namespace Tests\Feature\Admin;

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

class ListRequestReportsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/request-reports';

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
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized();
    }

    public function test_non_admin_application_roles_are_forbidden(): void
    {
        $branch = $this->branch('AUTH');

        foreach (['User', 'approver'] as $role) {
            Sanctum::actingAs($this->user($branch, $role));

            $this->getJson(self::ENDPOINT)
                ->assertForbidden();
        }
    }

    public function test_admin_can_list_organization_wide_reports_using_the_safe_contract(): void
    {
        $firstBranch = $this->branch('NORTH');
        $secondBranch = $this->branch('SOUTH');
        $firstRequester = $this->user($firstBranch);
        $secondRequester = $this->user($secondBranch);

        $older = $this->requestForm(
            requester: $firstRequester,
            branch: $firstBranch,
            requestCode: 'REPORT-SRL-0001',
            createdAt: '2026-11-14 01:00:00',
        );
        $newer = $this->requestForm(
            requester: $secondRequester,
            branch: $secondBranch,
            requestCode: 'REPORT-CIRS-0002',
            formType: RequestFormType::CheckIssuance,
            status: RequestStatus::Completed,
            createdAt: '2026-11-15 01:00:00',
        );

        $deleted = $this->requestForm(
            requester: $firstRequester,
            branch: $firstBranch,
            requestCode: 'REPORT-DELETED-0003',
            createdAt: '2026-11-16 01:00:00',
        );
        $deleted->delete();

        Sanctum::actingAs($this->user($firstBranch, 'Admin'));

        $response = $this->getJson(self::ENDPOINT);

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request reports retrieved successfully.',
            )
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath(
                'data.0.requester.firstname',
                $secondRequester->firstName,
            )
            ->assertJsonPath(
                'data.0.branch.branch_code',
                $secondBranch->branch_code,
            )
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'request_code',
                    'form_type',
                    'kind_of_request',
                    'currency',
                    'status',
                    'grand_total',
                    'requester' => [
                        'id',
                        'firstname',
                        'lastname',
                        'position',
                    ],
                    'branch' => [
                        'id',
                        'branch_code',
                        'branch_name',
                        'branch',
                        'acronym',
                    ],
                    'created_at',
                ]],
                'links',
                'meta',
            ])
            ->assertJsonMissingPath('data.0.requester_id')
            ->assertJsonMissingPath('data.0.branch_id')
            ->assertJsonMissingPath('data.0.form_data')
            ->assertJsonMissingPath('data.0.attachments')
            ->assertJsonMissingPath('data.0.approval_steps')
            ->assertJsonMissingPath('data.0.requester.email')
            ->assertJsonMissingPath('data.0.requester.signature');

        $response->assertJsonMissing([
            'request_code' => $deleted->request_code,
        ]);
    }

    public function test_admin_can_search_report_identity_requester_type_and_branch_fields(): void
    {
        $matchingBranch = $this->branch(
            'MNL',
            'Manila Operations Center',
            'Metro Company',
            'MOC',
        );
        $otherBranch = $this->branch('CEB');
        $matchingRequester = $this->user(
            $matchingBranch,
            'User',
            'Celeste',
            'Navigator',
        );
        $otherRequester = $this->user($otherBranch);

        $match = $this->requestForm(
            requester: $matchingRequester,
            branch: $matchingBranch,
            requestCode: 'SEARCH-CIRS-ALPHA',
            formType: RequestFormType::CheckIssuance,
            kindOfRequest: 'Treasury settlement',
        );
        $this->requestForm(
            requester: $otherRequester,
            branch: $otherBranch,
            requestCode: 'SEARCH-SRL-BETA',
        );

        Sanctum::actingAs($this->user($matchingBranch, 'Admin'));

        foreach ([
            'CIRS-ALPHA',
            'Celeste',
            'Navigator',
            'Check Issuance',
            'Treasury',
            'Operations Center',
            'MOC',
        ] as $search) {
            $this->getJson(self::ENDPOINT.'?'.http_build_query([
                'search' => $search,
            ]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $match->id);
        }
    }

    public function test_status_form_type_and_branch_filters_are_combined(): void
    {
        $targetBranch = $this->branch('TARGET');
        $otherBranch = $this->branch('OTHER');
        $targetRequester = $this->user($targetBranch);
        $otherRequester = $this->user($otherBranch);

        $match = $this->requestForm(
            requester: $targetRequester,
            branch: $targetBranch,
            requestCode: 'FILTER-MATCH',
            formType: RequestFormType::PurchaseOrder,
            status: RequestStatus::Completed,
        );
        $this->requestForm(
            requester: $targetRequester,
            branch: $targetBranch,
            requestCode: 'FILTER-WRONG-STATUS',
            formType: RequestFormType::PurchaseOrder,
        );
        $this->requestForm(
            requester: $otherRequester,
            branch: $otherBranch,
            requestCode: 'FILTER-WRONG-BRANCH',
            formType: RequestFormType::PurchaseOrder,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($this->user($targetBranch, 'Admin'));

        $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'status' => RequestStatus::Completed->value,
            'form_type' => RequestFormType::PurchaseOrder->value,
            'branch_id' => $targetBranch->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'status' => 'ALL',
            'form_type' => 'ALL',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_grouped_search_cannot_escape_status_and_branch_filters(): void
    {
        $targetBranch = $this->branch('GROUP-TARGET');
        $otherBranch = $this->branch('GROUP-OTHER');
        $targetRequester = $this->user($targetBranch);
        $otherRequester = $this->user($otherBranch);

        $match = $this->requestForm(
            requester: $targetRequester,
            branch: $targetBranch,
            requestCode: 'QUARTERLY-MATCH',
            status: RequestStatus::Completed,
        );
        $this->requestForm(
            requester: $targetRequester,
            branch: $targetBranch,
            requestCode: 'QUARTERLY-WRONG-STATUS',
            status: RequestStatus::Pending,
        );
        $this->requestForm(
            requester: $otherRequester,
            branch: $otherBranch,
            requestCode: 'QUARTERLY-WRONG-BRANCH',
            status: RequestStatus::Completed,
        );
        $this->requestForm(
            requester: $targetRequester,
            branch: $targetBranch,
            requestCode: 'ANNUAL-NONMATCH',
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($this->user($targetBranch, 'Admin'));

        $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'search' => 'QUARTERLY',
            'status' => RequestStatus::Completed->value,
            'branch_id' => $targetBranch->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_date_filter_uses_complete_asia_manila_business_days(): void
    {
        $branch = $this->branch('DATE');
        $requester = $this->user($branch);

        $insideStart = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'DATE-INSIDE-START',
            createdAt: '2026-11-15 16:00:00',
        );
        $insideEnd = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'DATE-INSIDE-END',
            createdAt: '2026-11-16 15:59:59',
        );
        $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'DATE-BEFORE',
            createdAt: '2026-11-15 15:59:59',
        );
        $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'DATE-AFTER',
            createdAt: '2026-11-16 16:00:00',
        );

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $response = $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'date_from' => '2026-11-16',
            'date_to' => '2026-11-16',
            'sort' => 'created_at',
            'direction' => 'asc',
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $insideStart->id)
            ->assertJsonPath('data.1.id', $insideEnd->id);
    }

    public function test_allowlisted_sorting_and_pagination_are_deterministic(): void
    {
        $branch = $this->branch('PAGE');
        $requester = $this->user($branch);

        foreach (range(1, 11) as $number) {
            $this->requestForm(
                requester: $requester,
                branch: $branch,
                requestCode: sprintf('PAGE-%02d', $number),
                createdAt: '2026-11-16 01:00:00',
            );
        }

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'sort' => 'request_code',
            'direction' => 'asc',
            'page' => 2,
            'per_page' => 10,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request_code', 'PAGE-11')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 11);
    }

    public function test_invalid_and_server_owned_query_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'search' => ['not-a-string'],
            'status' => 'Approved',
            'form_type' => 'Unknown Form',
            'branch_id' => 999999,
            'date_from' => '2026-11-17',
            'date_to' => '2026-11-16',
            'sort' => 'requester_id',
            'direction' => 'sideways',
            'page' => 0,
            'per_page' => 51,
            'user_id' => 1,
            'requester_id' => 1,
            'role' => 'Admin',
            'include_deleted' => true,
            'deleted_at' => '2026-11-16',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'search',
                'status',
                'form_type',
                'branch_id',
                'date_to',
                'sort',
                'direction',
                'page',
                'per_page',
                'user_id',
                'requester_id',
                'role',
                'include_deleted',
                'deleted_at',
            ]);
    }

    public function test_date_range_requires_both_dates(): void
    {
        $branch = $this->branch('DATE-RULE');

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson(self::ENDPOINT.'?date_from=2026-11-16')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');

        $this->getJson(self::ENDPOINT.'?date_to=2026-11-16')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
    }

    private function branch(
        string $code,
        ?string $name = null,
        string $company = 'Test Company',
        ?string $acronym = null,
    ): Branch {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => $name ?? "{$code} Test Branch",
            'branch' => $company,
            'acronym' => $acronym ?? $code,
        ]);
    }

    private function user(
        Branch $branch,
        string $role = 'User',
        ?string $firstName = null,
        string $lastName = 'Reporter',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => $firstName ?? "Report{$number}",
            'lastName' => $lastName,
            'contact' => "0919000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "report-user-{$number}",
            'employee_id' => "REPORT-EMP-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        string $requestCode,
        RequestFormType $formType = RequestFormType::StockRequisition,
        RequestStatus $status = RequestStatus::Pending,
        string $kindOfRequest = 'Office request',
        string $createdAt = '2026-11-16 01:00:00',
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => $kindOfRequest,
            'currency' => 'PHP',
            'form_data' => [
                'private_test_value' => 'Must not appear in reports.',
            ],
        ]);

        $requestForm->request_code = $requestCode;
        $requestForm->form_type = $formType;
        $requestForm->status = $status;
        $requestForm->grand_total = '1250.50';
        $requestForm->created_at = $createdAt;
        $requestForm->updated_at = $createdAt;
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
