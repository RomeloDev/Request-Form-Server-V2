<?php

namespace App\Http\Resources;

use App\Models\RequestForm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    /**
     * Transform the dashboard payload into the approved API contract.
     *
     * Only aggregate counts, activity, and recent-request previews are
     * returned. Raw models, requester IDs, approver IDs, approval-step IDs,
     * form data, totals, comments, attachments, and storage fields are never
     * exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $personal = is_array($this->resource['personal'] ?? null)
            ? $this->resource['personal']
            : [];

        $approval = $this->resource['approval'] ?? null;

        return [
            'personal' => [
                'summary' => $this->personalSummary(
                    $personal['summary'] ?? null,
                ),
                'recent_requests' => $this->recentRequests(
                    $personal['recent_requests'] ?? null,
                ),
            ],
            'approval' => is_array($approval)
                ? [
                    'summary' => $this->approvalSummary(
                        $approval['summary'] ?? null,
                    ),
                    'activity' => $this->approvalActivity(
                        $approval['activity'] ?? null,
                    ),
                ]
                : null,
        ];
    }

    /**
     * Normalize personal counts to integers, defaulting missing values to zero.
     *
     * @return array<string, int>
     */
    private function personalSummary(mixed $summary): array
    {
        $summary = is_array($summary) ? $summary : [];

        return [
            'total' => (int) ($summary['total'] ?? 0),
            'pending' => (int) ($summary['pending'] ?? 0),
            'ongoing' => (int) ($summary['ongoing'] ?? 0),
            'completed' => (int) ($summary['completed'] ?? 0),
            'disapproved' => (int) ($summary['disapproved'] ?? 0),
            'canceled' => (int) ($summary['canceled'] ?? 0),
        ];
    }

    /**
     * Normalize approval counts to integers, defaulting missing values to zero.
     *
     * @return array<string, int>
     */
    private function approvalSummary(mixed $summary): array
    {
        $summary = is_array($summary) ? $summary : [];

        return [
            'total_assigned' => (int) ($summary['total_assigned'] ?? 0),
            'approved' => (int) ($summary['approved'] ?? 0),
            'awaiting_action' => (int) ($summary['awaiting_action'] ?? 0),
            'disapproved' => (int) ($summary['disapproved'] ?? 0),
            'closed_without_action' => (int) (
                $summary['closed_without_action'] ?? 0
            ),
        ];
    }

    /**
     * Normalize the approval activity contract.
     *
     * Every entry is rebuilt from an explicit whitelist so no IDs, comments,
     * requester information, or internal timestamps can leak through.
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
    private function approvalActivity(mixed $activity): array
    {
        $activity = is_array($activity) ? $activity : [];

        return [
            'monthly_decisions' => $this->monthlyDecisions(
                $activity['monthly_decisions'] ?? null,
            ),
            'weekly_assignments' => $this->weeklyAssignments(
                $activity['weekly_assignments'] ?? null,
            ),
        ];
    }

    /**
     * @return array<int, array{
     *     month: string,
     *     label: string,
     *     approved: int,
     *     disapproved: int
     * }>
     */
    private function monthlyDecisions(mixed $decisions): array
    {
        if (! is_array($decisions)) {
            return [];
        }

        $result = [];

        foreach ($decisions as $decision) {
            if (! is_array($decision)) {
                continue;
            }

            $result[] = [
                'month' => (string) ($decision['month'] ?? ''),
                'label' => (string) ($decision['label'] ?? ''),
                'approved' => (int) ($decision['approved'] ?? 0),
                'disapproved' => (int) ($decision['disapproved'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{date: string, label: string, total: int}>
     */
    private function weeklyAssignments(mixed $assignments): array
    {
        if (! is_array($assignments)) {
            return [];
        }

        $result = [];

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $result[] = [
                'date' => (string) ($assignment['date'] ?? ''),
                'label' => (string) ($assignment['label'] ?? ''),
                'total' => (int) ($assignment['total'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Transform recent request models into the approved preview shape.
     *
     * This whitelist deliberately excludes form_data, requester details,
     * grand_total, currency, items, approval steps, shares, attachments,
     * and cancellation reason. Enums are serialized by their API values.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentRequests(mixed $recentRequests): array
    {
        if (! is_array($recentRequests)) {
            return [];
        }

        $result = [];

        foreach ($recentRequests as $recentRequest) {
            if (! $recentRequest instanceof RequestForm) {
                continue;
            }

            $result[] = [
                'id' => (int) $recentRequest->getKey(),
                'request_code' => (string) $recentRequest->request_code,
                'form_type' => $recentRequest->form_type->value,
                'status' => $recentRequest->status->value,
                'branch' => [
                    'branch_code' => (string) $recentRequest->branch->branch_code,
                    'branch_name' => (string) $recentRequest->branch->branch_name,
                ],
                'created_at' => $recentRequest->created_at?->toISOString(),
            ];
        }

        return $result;
    }
}
