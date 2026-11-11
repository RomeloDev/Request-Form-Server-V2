<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestForms\ListSuppliersRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    public function index(
        ListSuppliersRequest $request,
    ): JsonResponse {
        $filters = $request->validated();

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        $perPage = (int) ($filters['per_page'] ?? 20);

        $suppliers = Supplier::query()
            ->select([
                'name',
                'address',
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

        return SupplierResource::collection($suppliers)
            ->additional([
                'message' => 'Suppliers retrieved successfully.',
            ])
            ->response();
    }
}
