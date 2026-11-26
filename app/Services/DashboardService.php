<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequestStatus;
use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\BranchHeadAssignment;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use Carbon\CarbonInterface;

class DashboardService
{
    /**
     * Build the dashboard payload for the authenticated user.
     *
     * The user model is always resolved by the controller from the
     * authenticated session, so no browser-provided identity is accepted.
     *
     * @return array{
     *     personal: array{
     *         summary: array<string, int>,
     *         recent_requests: array<int, RequestForm>
     *     },
     *     approval: array{
     *         summary: array<string, int>,
     *         activity: array{
     *             monthly_decisions: array<int, array{
     *                 month: string,
     *                 label: string,
     *                 approved: int,
     *                 disapproved: int
     *             }>,
     *             weekly_assignments: array<int, array{
     *                 date: string,
     *                 label: string,
     *                 total: int
     *             }>
     *         }
     *     }|null,
     *      administration: array{
     *          summary: array<string, int>,
     *           request_summary: array<string, int>,
     *          recent_requests: array<int, RequestForm>
     *       }|null
     * }
     */
    public function forUser(User $user): array
    {
        return [
            'personal' => $this->personalOverview($user),
            'approval' => $this->approvalOverview($user),
            'administration' => $this->administrationOverview($user),
        ];
    }

    public function administrationOverview(User $user): ?array
    {
        if (! $user->hasRole('Admin')) {
            return null;
        }

        $nonAdminUsers = User::query()->withoutRole('Admin');

        return [
            'summary' => [
                'total_users' => (clone $nonAdminUsers)->count(),

                'verified_users' => (clone $nonAdminUsers)
                    ->whereNotNull('email_verified_at')
                    ->count(),

                'unverified_users' => (clone $nonAdminUsers)
                    ->whereNull('email_verified_at')
                    ->count(),

                'branches' => Branch::query()->count(),

                'positions' => Position::query()->count(),

                'approvers' => User::query()
                    ->role('approver')
                    ->count(),

                'avp_staff_assignments' => AvpStaffAssignment::query()->count(),

                'branch_head_assignments' => BranchHeadAssignment::query()->count(),

                'feedbacks' => Feedback::query()->count(),
            ],

            'request_summary' => $this->administrationRequestSummary(),

            'recent_requests' => $this->administrationRecentRequests(),
        ];
    }

    /**
     * Count all active request forms by status.
     *
     * RequestForm's SoftDeletes scope automatically excludes deleted requests.
     *
     * @return array{
     *     total: int,
     *     pending: int,
     *     ongoing: int,
     *     completed: int,
     *     disapproved: int,
     *     canceled: int
     * }
     */
    public function administrationRequestSummary(): array
    {
        $summary = RequestForm::query()
            ->selectRaw(
                '
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS ongoing,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS disapproved,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS canceled
                ',
                [
                    RequestStatus::Pending->value,
                    RequestStatus::Ongoing->value,
                    RequestStatus::Completed->value,
                    RequestStatus::Disapproved->value,
                    RequestStatus::Canceled->value,
                ],
            )->first();

        return [
            'total' => (int) ($summary?->total ?? 0),
            'pending' => (int) ($summary?->pending ?? 0),
            'ongoing' => (int) ($summary?->ongoing ?? 0),
            'completed' => (int) ($summary?->completed ?? 0),
            'disapproved' => (int) ($summary?->disapproved ?? 0),
            'canceled' => (int) ($summary?->canceled ?? 0),
        ];
    }

    /**
     * Load the five latest active requests for the Admin dashboard.
     *
     * Requester information and form contents are not selected.
     *
     * @return array<int, RequestForm>
     */
    public function administrationRecentRequests(): array
    {
        return RequestForm::query()
            ->select([
                'id',
                'request_code',
                'form_type',
                'status',
                'branch_id',
                'created_at',
            ])
            ->with([
                'branch' => fn ($query) => $query->select([
                    'id',
                    'branch_code',
                    'branch_name',
                ]),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->all();
    }

    /**
     * Build the personal request summary and recent-request previews.
     *
     * Every query is scoped to the authenticated user's primary key. The
     * browser never supplies a requester or user ID.
     *
     * @return array{
     *     summary: array<string, int>,
     *     recent_requests: array<int, RequestForm>
     * }
     */
    private function personalOverview(User $user): array
    {
        return [
            'summary' => $this->personalSummary($user),
            'recent_requests' => $this->personalRecentRequests($user),
        ];
    }

    /**
     * Count the authenticated user's requests by status in one query.
     *
     * A single conditional aggregate avoids six round trips. Status values
     * are bound parameters, never concatenated into the SQL string. The
     * model's SoftDeletes global scope excludes trashed requests.
     *
     * @return array{total: int, pending: int, ongoing: int, completed: int, disapproved: int, canceled: int}
     */
    private function personalSummary(User $user): array
    {
        $summary = RequestForm::query()
            ->where('requester_id', $user->getKey())
            ->selectRaw(
                '
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS ongoing,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS disapproved,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS canceled
                ',
                [
                    RequestStatus::Pending->value,
                    RequestStatus::Ongoing->value,
                    RequestStatus::Completed->value,
                    RequestStatus::Disapproved->value,
                    RequestStatus::Canceled->value,
                ],
            )
            ->first();

        return [
            'total' => (int) ($summary?->total ?? 0),
            'pending' => (int) ($summary?->pending ?? 0),
            'ongoing' => (int) ($summary?->ongoing ?? 0),
            'completed' => (int) ($summary?->completed ?? 0),
            'disapproved' => (int) ($summary?->disapproved ?? 0),
            'canceled' => (int) ($summary?->canceled ?? 0),
        ];
    }

    /**
     * Load the five newest requests owned by the authenticated user.
     *
     * Only columns needed by the dashboard preview are selected, and the
     * branch relation is eager-loaded with just its display fields. The
     * explicit resource transformation lives in DashboardResource.
     *
     * @return array<int, RequestForm>
     */
    private function personalRecentRequests(User $user): array
    {
        return RequestForm::query()
            ->select([
                'id',
                'request_code',
                'form_type',
                'status',
                'branch_id',
                'created_at',
            ])
            ->with([
                'branch' => fn ($query) => $query->select([
                    'id',
                    'branch_code',
                    'branch_name',
                ]),
            ])
            ->where('requester_id', $user->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->all();
    }

    /**
     * Build the approver-only approval section.
     *
     * Admin and User accounts must always receive null.
     *
     * @return array{
     *     summary: array<string, int>,
     *     activity: array{
     *         monthly_decisions: array<int, array{
     *             month: string,
     *             label: string,
     *             approved: int,
     *             disapproved: int
     *         }>,
     *         weekly_assignments: array<int, array{
     *             date: string,
     *             label: string,
     *             total: int
     *         }>
     *     }
     * }|null
     */
    private function approvalOverview(User $user): ?array
    {
        if (! $user->hasRole('approver')) {
            return null;
        }

        return [
            'summary' => $this->approvalSummary($user),
            'activity' => $this->approvalActivity($user),
        ];
    }

    /**
     * Build the monthly decision and weekly assignment activity series.
     *
     * One "now" value is captured so both series use the same application
     * week and year even if the request crosses midnight.
     *
     * @return array{
     *     monthly_decisions: array<int, array{
     *         month: string,
     *         label: string,
     *         approved: int,
     *         disapproved: int
     *     }>,
     *     weekly_assignments: array<int, array{
     *         date: string,
     *         label: string,
     *         total: int
     *     }>
     * }
     */
    private function approvalActivity(User $user): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = now($timezone);

        return [
            'monthly_decisions' => $this->monthlyDecisions(
                $user,
                $now,
                $timezone,
            ),
            'weekly_assignments' => $this->weeklyAssignments(
                $user,
                $now,
                $timezone,
            ),
        ];
    }

    /**
     * Count this approver's decisions for every month of the current year.
     *
     * Decisions are grouped by acted_at, not request creation time. Only
     * Approved and Disapproved steps count. The query is bounded to the
     * current application year and selects only status and acted_at so it
     * works on SQLite and does not load models with unrelated columns.
     *
     * @return array<int, array{
     *     month: string,
     *     label: string,
     *     approved: int,
     *     disapproved: int
     * }>
     */
    private function monthlyDecisions(
        User $user,
        CarbonInterface $now,
        string $timezone,
    ): array {
        $startOfYear = $now->copy()
            ->setTimezone($timezone)
            ->startOfYear();
        $endOfYear = $startOfYear->copy()->endOfYear();

        $decisions = RequestApprovalStep::query()
            ->select([
                'status',
                'acted_at',
            ])
            ->where('approver_id', $user->getKey())
            ->whereHas('requestForm')
            ->whereIn('status', [
                ApprovalStepStatus::Approved->value,
                ApprovalStepStatus::Disapproved->value,
            ])
            ->whereNotNull('acted_at')
            ->whereBetween('acted_at', [$startOfYear, $endOfYear])
            ->get();

        $counts = [];

        foreach ($decisions as $decision) {
            $actedAt = $decision->acted_at;

            if (! $actedAt instanceof CarbonInterface) {
                continue;
            }

            $month = $actedAt->copy()
                ->setTimezone($timezone)
                ->format('Y-m');

            if (! isset($counts[$month])) {
                $counts[$month] = [
                    'approved' => 0,
                    'disapproved' => 0,
                ];
            }

            if ($decision->status === ApprovalStepStatus::Approved) {
                $counts[$month]['approved']++;
            } elseif (
                $decision->status === ApprovalStepStatus::Disapproved
            ) {
                $counts[$month]['disapproved']++;
            }
        }

        $labels = [
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
        ];

        $monthlyDecisions = [];

        for ($index = 0; $index < 12; $index++) {
            $month = $startOfYear->copy()
                ->addMonths($index)
                ->format('Y-m');

            $monthlyDecisions[] = [
                'month' => $month,
                'label' => $labels[$index],
                'approved' => (int) ($counts[$month]['approved'] ?? 0),
                'disapproved' => (int) (
                    $counts[$month]['disapproved'] ?? 0
                ),
            ];
        }

        return $monthlyDecisions;
    }

    /**
     * Count this approver's assigned steps for each day of the current week.
     *
     * Assignments are grouped by the approval step's created_at, starting on
     * Monday in the application timezone. The query is bounded to the current
     * week, excludes soft-deleted request forms, and selects only created_at.
     *
     * @return array<int, array{date: string, label: string, total: int}>
     */
    private function weeklyAssignments(
        User $user,
        CarbonInterface $now,
        string $timezone,
    ): array {
        $startOfWeek = $now->copy()
            ->setTimezone($timezone)
            ->startOfWeek(CarbonInterface::MONDAY);
        $endOfWeek = $startOfWeek->copy()->addDays(6)->endOfDay();

        $assignments = RequestApprovalStep::query()
            ->select([
                'created_at',
            ])
            ->where('approver_id', $user->getKey())
            ->whereHas('requestForm')
            ->whereNotNull('created_at')
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->get();

        $counts = [];

        foreach ($assignments as $assignment) {
            $createdAt = $assignment->created_at;

            if (! $createdAt instanceof CarbonInterface) {
                continue;
            }

            $localCreatedAt = $createdAt->copy()
                ->setTimezone($timezone);

            if (
                $localCreatedAt->lt($startOfWeek)
                || $localCreatedAt->gt($endOfWeek)
            ) {
                continue;
            }

            $date = $localCreatedAt->format('Y-m-d');
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }

        $labels = [
            'Mon',
            'Tue',
            'Wed',
            'Thu',
            'Fri',
            'Sat',
            'Sun',
        ];

        $weeklyAssignments = [];

        for ($index = 0; $index < 7; $index++) {
            $date = $startOfWeek->copy()
                ->addDays($index)
                ->format('Y-m-d');

            $weeklyAssignments[] = [
                'date' => $date,
                'label' => $labels[$index],
                'total' => (int) ($counts[$date] ?? 0),
            ];
        }

        return $weeklyAssignments;
    }

    /**
     * Count the authenticated approver's approval steps by outcome.
     *
     * The base query is always scoped by the authenticated approver's key and
     * excludes steps whose request form is soft-deleted. The awaiting-action
     * count reuses the same actionablePending scope as Process Requests, so
     * the two surfaces cannot drift apart.
     *
     * @return array{
     *     total_assigned: int,
     *     approved: int,
     *     awaiting_action: int,
     *     disapproved: int,
     *     closed_without_action: int
     * }
     */
    private function approvalSummary(User $user): array
    {
        $baseQuery = RequestApprovalStep::query()
            ->where('approver_id', $user->getKey())
            ->whereHas('requestForm');

        $summary = (clone $baseQuery)
            ->selectRaw(
                '
                    COUNT(*) AS total_assigned,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS approved,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS disapproved,
                    COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) AS closed_without_action
                ',
                [
                    ApprovalStepStatus::Approved->value,
                    ApprovalStepStatus::Disapproved->value,
                    ApprovalStepStatus::Skipped->value,
                    ApprovalStepStatus::Canceled->value,
                ],
            )
            ->first();

        $awaitingAction = (clone $baseQuery)
            ->actionablePending()
            ->count();

        return [
            'total_assigned' => (int) ($summary?->total_assigned ?? 0),
            'approved' => (int) ($summary?->approved ?? 0),
            'awaiting_action' => (int) $awaitingAction,
            'disapproved' => (int) ($summary?->disapproved ?? 0),
            'closed_without_action' => (int) (
                $summary?->closed_without_action ?? 0
            ),
        ];
    }
}
