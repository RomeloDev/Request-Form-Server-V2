<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApprovalActivityTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/dashboard';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_monthly_and_weekly_series_are_complete_and_zero_filled(): void
    {
        $this->freezeAt('2026-06-17 12:00:00');

        $approver = $this->user($this->branch('EMPTY-ACTIVITY'), 'approver');

        Sanctum::actingAs($approver);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $monthlyDecisions = $response->json(
            'data.approval.activity.monthly_decisions',
        );
        $weeklyAssignments = $response->json(
            'data.approval.activity.weekly_assignments',
        );

        $this->assertCount(12, $monthlyDecisions);
        $this->assertSame([
            '2026-01',
            '2026-02',
            '2026-03',
            '2026-04',
            '2026-05',
            '2026-06',
            '2026-07',
            '2026-08',
            '2026-09',
            '2026-10',
            '2026-11',
            '2026-12',
        ], array_column($monthlyDecisions, 'month'));
        $this->assertSame([
            'Jan',
            'Feb',
            'Mar',
            'Apr',
            'May',
            'Jun',
            'Jul',
            'Aug',
            'Sep',
            'Oct',
            'Nov',
            'Dec',
        ], array_column($monthlyDecisions, 'label'));

        foreach ($monthlyDecisions as $month) {
            $this->assertSame(0, $month['approved']);
            $this->assertSame(0, $month['disapproved']);
        }

        $this->assertCount(7, $weeklyAssignments);
        $this->assertSame([
            '2026-06-15',
            '2026-06-16',
            '2026-06-17',
            '2026-06-18',
            '2026-06-19',
            '2026-06-20',
            '2026-06-21',
        ], array_column($weeklyAssignments, 'date'));
        $this->assertSame([
            'Mon',
            'Tue',
            'Wed',
            'Thu',
            'Fri',
            'Sat',
            'Sun',
        ], array_column($weeklyAssignments, 'label'));

        foreach ($weeklyAssignments as $day) {
            $this->assertSame(0, $day['total']);
        }
    }

    public function test_monthly_decisions_group_by_acted_at_and_ignore_pending_and_out_of_range(): void
    {
        $this->freezeAt('2026-06-17 12:00:00');

        $branch = $this->branch('MONTHLY');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $otherApprover = $this->user($branch, 'approver');

        $this->decisionStep(
            $requester,
            $branch,
            $approver,
            'MONTHLY-APPROVED-JAN',
            ApprovalStepStatus::Approved,
            '2026-01-05 09:00:00',
        );
        $this->decisionStep(
            $requester,
            $branch,
            $approver,
            'MONTHLY-DISAPPROVED-JAN',
            ApprovalStepStatus::Disapproved,
            '2026-01-20 10:00:00',
        );
        $this->decisionStep(
            $requester,
            $branch,
            $approver,
            'MONTHLY-APPROVED-JUN',
            ApprovalStepStatus::Approved,
            '2026-06-10 11:00:00',
        );

        // Pending steps are not decisions, even if acted_at is populated.
        $pendingRequest = $this->requestForm(
            $requester,
            $branch,
            'MONTHLY-PENDING',
            RequestStatus::Pending,
        );
        $pendingStep = $this->approvalStep(
            $pendingRequest,
            $approver,
            1,
            ApprovalStepStatus::Pending,
        );
        $pendingStep->acted_at = $this->local('2026-03-15 12:00:00');
        $pendingStep->saveQuietly();

        // Invalid decision rows without acted_at are ignored.
        $missingActedAt = $this->decisionStep(
            $requester,
            $branch,
            $approver,
            'MONTHLY-MISSING-ACTED-AT',
            ApprovalStepStatus::Approved,
            '2026-04-01 12:00:00',
        );
        $missingActedAt->acted_at = null;
        $missingActedAt->saveQuietly();

        // Out-of-year decisions are excluded by the bounded query.
        $this->decisionStep(
            $requester,
            $branch,
            $approver,
            'MONTHLY-PREVIOUS-YEAR',
            ApprovalStepStatus::Approved,
            '2025-12-31 23:59:59',
        );
        $this->decisionStep(
            $requester,
            $branch,
            $approver,
            'MONTHLY-NEXT-YEAR',
            ApprovalStepStatus::Disapproved,
            '2027-01-01 00:00:00',
        );

        // Another approver's decision must not appear.
        $this->decisionStep(
            $requester,
            $branch,
            $otherApprover,
            'MONTHLY-OTHER-APPROVER',
            ApprovalStepStatus::Approved,
            '2026-02-02 09:00:00',
        );

        Sanctum::actingAs($approver);

        $monthlyDecisions = $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.approval.activity.monthly_decisions');

        $byMonth = collect($monthlyDecisions)->keyBy('month');

        $this->assertSame(1, $byMonth['2026-01']['approved']);
        $this->assertSame(1, $byMonth['2026-01']['disapproved']);
        $this->assertSame(0, $byMonth['2026-02']['approved']);
        $this->assertSame(0, $byMonth['2026-03']['approved']);
        $this->assertSame(0, $byMonth['2026-04']['approved']);
        $this->assertSame(0, $byMonth['2026-05']['approved']);
        $this->assertSame(1, $byMonth['2026-06']['approved']);
        $this->assertSame(0, $byMonth['2026-06']['disapproved']);
        $this->assertSame(0, $byMonth['2026-12']['approved']);
        $this->assertSame(0, $byMonth['2026-12']['disapproved']);
    }

    public function test_weekly_assignments_group_by_created_at_monday_through_sunday(): void
    {
        $this->freezeAt('2026-06-17 12:00:00');

        $branch = $this->branch('WEEKLY');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $otherApprover = $this->user($branch, 'approver');

        $this->assignmentStep(
            $requester,
            $branch,
            $approver,
            'WEEKLY-MON-FIRST',
            '2026-06-15 09:00:00',
        );
        $this->assignmentStep(
            $requester,
            $branch,
            $approver,
            'WEEKLY-MON-SECOND',
            '2026-06-15 17:00:00',
        );
        $this->assignmentStep(
            $requester,
            $branch,
            $approver,
            'WEEKLY-WED',
            '2026-06-17 12:00:00',
        );
        $this->assignmentStep(
            $requester,
            $branch,
            $approver,
            'WEEKLY-SUN-LATE',
            '2026-06-21 23:00:00',
        );

        // Out-of-week assignments are excluded.
        $this->assignmentStep(
            $requester,
            $branch,
            $approver,
            'WEEKLY-PREVIOUS-SUNDAY',
            '2026-06-14 23:59:59',
        );
        $this->assignmentStep(
            $requester,
            $branch,
            $approver,
            'WEEKLY-NEXT-MONDAY',
            '2026-06-22 00:00:00',
        );

        // Another approver's assignment in the same week is excluded.
        $this->assignmentStep(
            $requester,
            $branch,
            $otherApprover,
            'WEEKLY-OTHER-APPROVER',
            '2026-06-17 10:00:00',
        );

        Sanctum::actingAs($approver);

        $weeklyAssignments = $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.approval.activity.weekly_assignments');

        $this->assertSame([
            '2026-06-15',
            '2026-06-16',
            '2026-06-17',
            '2026-06-18',
            '2026-06-19',
            '2026-06-20',
            '2026-06-21',
        ], array_column($weeklyAssignments, 'date'));

        $this->assertSame([
            'Mon' => 2,
            'Tue' => 0,
            'Wed' => 1,
            'Thu' => 0,
            'Fri' => 0,
            'Sat' => 0,
            'Sun' => 1,
        ], array_column($weeklyAssignments, 'total', 'label'));
    }

    public function test_soft_deleted_request_activity_is_excluded(): void
    {
        $this->freezeAt('2026-06-17 12:00:00');

        $branch = $this->branch('ACTIVITY-TRASHED');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        $this->decisionStep(
            $requester,
            $branch,
            $approver,
            'ACTIVITY-ACTIVE',
            ApprovalStepStatus::Approved,
            '2026-06-10 09:00:00',
        );

        $deletedRequest = $this->requestForm(
            $requester,
            $branch,
            'ACTIVITY-DELETED',
            RequestStatus::Pending,
        );
        $deletedStep = $this->approvalStep(
            $deletedRequest,
            $approver,
            1,
            ApprovalStepStatus::Approved,
        );
        $deletedStep->acted_at = $this->local('2026-06-11 09:00:00');
        $deletedStep->created_at = $this->local('2026-06-16 09:00:00');
        $deletedStep->saveQuietly();
        $deletedRequest->delete();

        Sanctum::actingAs($approver);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $monthlyDecisions = $response->json(
            'data.approval.activity.monthly_decisions',
        );
        $byMonth = collect($monthlyDecisions)->keyBy('month');

        $this->assertSame(1, $byMonth['2026-06']['approved']);

        $weeklyAssignments = $response->json(
            'data.approval.activity.weekly_assignments',
        );

        $this->assertSame(
            1,
            array_sum(array_column($weeklyAssignments, 'total')),
        );
    }

    public function test_frozen_year_boundary_produces_correct_month_and_week_keys(): void
    {
        $this->freezeAt('2027-01-01 00:30:00');

        $branch = $this->branch('BOUNDARY');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        // Belongs to the current week but the previous calendar year.
        $previousYear = $this->requestForm(
            $requester,
            $branch,
            'BOUNDARY-PREVIOUS-YEAR',
            RequestStatus::Pending,
        );
        $previousYearStep = $this->approvalStep(
            $previousYear,
            $approver,
            1,
            ApprovalStepStatus::Approved,
        );
        $previousYearStep->created_at = $this->local(
            '2026-12-31 23:00:00',
        );
        $previousYearStep->acted_at = $this->local(
            '2026-12-31 23:30:00',
        );
        $previousYearStep->saveQuietly();

        // Belongs to the current week and the new calendar year.
        $currentYear = $this->requestForm(
            $requester,
            $branch,
            'BOUNDARY-CURRENT-YEAR',
            RequestStatus::Pending,
        );
        $currentYearStep = $this->approvalStep(
            $currentYear,
            $approver,
            1,
            ApprovalStepStatus::Approved,
        );
        $currentYearStep->created_at = $this->local(
            '2027-01-01 00:15:00',
        );
        $currentYearStep->acted_at = $this->local(
            '2027-01-01 00:15:00',
        );
        $currentYearStep->saveQuietly();

        Sanctum::actingAs($approver);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $monthlyDecisions = $response->json(
            'data.approval.activity.monthly_decisions',
        );

        $this->assertSame([
            '2027-01',
            '2027-02',
            '2027-03',
            '2027-04',
            '2027-05',
            '2027-06',
            '2027-07',
            '2027-08',
            '2027-09',
            '2027-10',
            '2027-11',
            '2027-12',
        ], array_column($monthlyDecisions, 'month'));

        $byMonth = collect($monthlyDecisions)->keyBy('month');

        // The 2026 acted_at must not appear in the 2027 series.
        $this->assertSame(1, $byMonth['2027-01']['approved']);
        $this->assertSame(
            1,
            array_sum(array_column($monthlyDecisions, 'approved')),
        );

        $weeklyAssignments = $response->json(
            'data.approval.activity.weekly_assignments',
        );

        $this->assertSame([
            '2026-12-28',
            '2026-12-29',
            '2026-12-30',
            '2026-12-31',
            '2027-01-01',
            '2027-01-02',
            '2027-01-03',
        ], array_column($weeklyAssignments, 'date'));

        $byDay = array_column($weeklyAssignments, 'total', 'date');

        $this->assertSame(1, $byDay['2026-12-31']);
        $this->assertSame(1, $byDay['2027-01-01']);
        $this->assertSame(2, array_sum($byDay));
    }

    public function test_admin_and_user_still_receive_null_approval(): void
    {
        $branch = $this->branch('ACTIVITY-NULL');

        foreach (['Admin', 'User'] as $role) {
            Sanctum::actingAs($this->user($branch, $role));

            $this->getJson(self::ENDPOINT)
                ->assertOk()
                ->assertJsonPath('data.approval', null);
        }
    }

    private function freezeAt(string $dateTime): void
    {
        Carbon::setTestNow($this->local($dateTime));
    }

    private function local(string $dateTime): Carbon
    {
        return Carbon::parse(
            $dateTime,
            (string) config('app.timezone', 'UTC'),
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

    private function user(Branch $branch, string $role = 'User'): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Activity{$number}",
            'lastName' => 'User',
            'contact' => "0918000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "activity-dashboard-{$number}",
            'employee_id' => "ACTIVITY-DASH-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function decisionStep(
        User $requester,
        Branch $branch,
        User $approver,
        string $requestCode,
        ApprovalStepStatus $status,
        string $actedAt,
    ): RequestApprovalStep {
        $requestForm = $this->requestForm(
            $requester,
            $branch,
            $requestCode,
            RequestStatus::Pending,
        );

        return $this->approvalStep(
            $requestForm,
            $approver,
            1,
            $status,
            actedAt: $this->local($actedAt),
        );
    }

    private function assignmentStep(
        User $requester,
        Branch $branch,
        User $approver,
        string $requestCode,
        string $createdAt,
    ): RequestApprovalStep {
        $requestForm = $this->requestForm(
            $requester,
            $branch,
            $requestCode,
            RequestStatus::Pending,
        );

        return $this->approvalStep(
            $requestForm,
            $approver,
            1,
            ApprovalStepStatus::Pending,
            createdAt: $this->local($createdAt),
        );
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        string $requestCode,
        RequestStatus $status,
        RequestFormType $formType = RequestFormType::StockRequisition,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing approval activity aggregation.',
            ],
        ]);

        $requestForm->request_code = $requestCode;
        $requestForm->form_type = $formType;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        int $level,
        ApprovalStepStatus $status = ApprovalStepStatus::Pending,
        mixed $createdAt = null,
        mixed $actedAt = null,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = $status;
        $step->acted_at = $status === ApprovalStepStatus::Pending
            ? null
            : ($actedAt ?? now());

        $requestForm->approvalSteps()->save($step);

        if ($createdAt !== null) {
            $step->created_at = $createdAt;
            $step->updated_at = $createdAt;
            $step->saveQuietly();
        }

        return $step;
    }
}
