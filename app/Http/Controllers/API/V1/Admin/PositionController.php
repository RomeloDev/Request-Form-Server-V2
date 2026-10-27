<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\StorePositionRequest;
use App\Http\Requests\Admin\UpdatePositionRequest;
use App\Models\User;

class PositionController extends Controller
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
    public function store(StorePositionRequest $request)
    {
        $data = $request->validated();

        $position = Position::create([
            "label" => $data["value"],
            "value" => $data["value"],
        ]);

        return response()->json([
            "message" => "New position " . $position->value . " added successfully",
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Position $position)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePositionRequest $request, Position $position)
    {
        $data = $request->validated();

        $position->update([
            "label"=> $data["label"],
        ]);

        return response()->json([
            "message"=> 'Position ' . $position->label
            . ' updated successfully.',
        ], 200);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Position $position)
    {
        $isAssigned = User::where(
            'position',
            $position->value
        )->exists();

        if ($isAssigned) {
            return response()->json([
                'message'=> 'This position is to users and cannot be deleted.',
            ], 409);
        }

        $label = $position->label;

        $position->delete();

        return response()->json([
            'message'=> 'Position '. $position->label . ' deleted successfully.',
        ], 200);
    }
}
