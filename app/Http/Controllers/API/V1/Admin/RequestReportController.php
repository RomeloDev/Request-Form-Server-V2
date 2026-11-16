<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestForms\ListRequestReportsRequest;
use App\Http\Resources\RequestReportResource;
use App\Models\RequestForm;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class RequestReportController extends Controller
{
    public function index(ListRequestReportsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = RequestForm::query()
            ->with([
                'requester:id,firstName,lastName,position',
                'branch:id,branch_code,branch_name,branch,acronym',
            ]);

        $query
            ->when(
                isset($filters['status']) &&
                $filters['status'] !== 'ALL',
                fn ($query) => $query->where(
                    'status',
                    $filters['status'],
                ),
            )
            ->when(
                isset($filters['form_type']) &&
                $filters['form_type'] !== 'ALL',
                fn ($query) => $query->where(
                    'form_type',
                    $filters['form_type'],
                ),
            )
            ->when(
                isset($filters['branch_id']),
                fn ($query) => $query->where(
                    'branch_id',
                    $filters['branch_id'],
                ),
            );

        if (isset($filters['search'])) {
            $search = trim($filters['search']);

            if ($search !== '') {
                $pattern = "%{$search}%";

                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->where('request_code', 'like', $pattern)
                        ->orWhere('form_type', 'like', $pattern)
                        ->orWhere('kind_of_request', 'like', $pattern)
                        ->orWhereHas(
                            'requester',
                            fn ($requesterQuery) => $requesterQuery
                                ->where('firstName', 'like', $pattern)
                                ->orWhere('lastName', 'like', $pattern),
                        )
                        ->orWhereHas(
                            'branch',
                            fn ($branchQuery) => $branchQuery
                                ->where('branch_code', 'like', $pattern)
                                ->orWhere('branch_name', 'like', $pattern)
                                ->orWhere('branch', 'like', $pattern)
                                ->orWhere('acronym', 'like', $pattern),
                        );
                });
            }
        }

        if (isset($filters['date_from']) && isset($filters['date_to'])) {
            $dateFrom = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $filters['date_from'],
                'Asia/Manila',
            )->startOfDay()->utc();

            $dateTo = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $filters['date_to'],
                'Asia/Manila',
            )->endOfDay()->utc();

            $query->whereBetween('created_at', [
                $dateFrom,
                $dateTo,
            ]);
        }

        $sortColumns = [
            'id' => 'request_forms.id',
            'request_code' => 'request_forms.request_code',
            'form_type' => 'request_forms.form_type',
            'status' => 'request_forms.status',
            'grand_total' => 'request_forms.grand_total',
            'created_at' => 'request_forms.created_at',
        ];

        $sortKey = $filters['sort'] ?? 'created_at';
        $sortColumn = $sortColumns[$sortKey];
        $direction = $filters['direction'] ?? 'desc';

        $query->orderBy($sortColumn, $direction);

        if ($sortKey !== 'id') {
            $query->orderBy('request_forms.id', $direction);
        }

        $reports = $query
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return RequestReportResource::collection($reports)
            ->additional([
                'message' => 'Request reports retrieved successfully.',
            ])
            ->response();
    }
}
