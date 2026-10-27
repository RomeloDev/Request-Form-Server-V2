<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use Illuminate\Http\JsonResponse;
use App\Support\BranchAcronyms;
use App\Models\User;

class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBranchRequest $request)
    {
        $data = $request->validated();

        $branch = Branch::create([
            'branch_code'=> $data['branch_code'],
            'branch_name'=> strtoupper($data['branch_name']),
            'branch'=> $data['branch'],
            'acronym' => BranchAcronyms::resolve(
                $data['branch'],
                $data['acronym'] ?? null,
            ),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Branch created successfully.',
            'data' => $branch,
        ], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(Branch $branch)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $data = $request->validated();

        // Resolve before changing the model so its existing values are available.
        $acronym = BranchAcronyms::resolve(
            $data['branch'],
            $data['acronym'] ?? null,
            $branch->branch,
            $branch->acronym,
        );

        $branch->update([
            'branch_code' => $data['branch_code'],
            'branch_name'=> strtoupper($data['branch_name']),
            'branch' => $data['branch'],
            'acronym' => $acronym,
        ]);

        return response()->json([
            'message' => 'Branch updated successfully.',
            'data' => $branch,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Branch $branch): JsonResponse
    {
        $isAssigned = User::where(
            'branch_code',
            (string) $branch->id
        )->exists();

        if ($isAssigned) {
            return response()->json([
                'message' => 'This position is assigned to users and cannot be deleted.',
            ], 409);
        }

        $branch->delete();

        return response()->json([
            'message'=> 'Branch deleted '. $branch->branch_code . ' deleted successfully.',
        ], 200);
    }
}
