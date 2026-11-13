<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestForms\ListBanksRequest;
use App\Http\Resources\BankResource;
use App\Models\Bank;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class BankController extends Controller
{
    public function index(
        ListBanksRequest $request,
    ): JsonResponse {
        $filters = $request->validated();

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        $perPage = (int) ($filters['per_page'] ?? 20);

        $banks = Bank::query()
            ->select([
                'name',
            ])
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->where(
                    'name',
                    'like',
                    "%{$search}%",
                ),
            )
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return BankResource::collection($banks)
            ->additional([
                'message' => 'Banks retrieved successfully.',
            ])
            ->response();
    }
}
