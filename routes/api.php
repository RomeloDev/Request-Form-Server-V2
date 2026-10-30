<?php

use App\Http\Controllers\API\V1\Admin\ApproverController;
use App\Http\Controllers\API\V1\Admin\AvpStaffController;
use App\Http\Controllers\API\V1\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\API\V1\Admin\PositionController as AdminPositionController;
use App\Http\Controllers\API\V1\Admin\UserController;
use App\Http\Controllers\API\V1\Auth\LoginController;
use App\Http\Controllers\API\V1\Auth\ProfileController;
use App\Http\Controllers\API\V1\Auth\RegisterController;
use App\Http\Controllers\API\V1\BranchController;
use App\Http\Controllers\API\V1\PositionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    // Health check endpoint
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    // Public endpoints
    Route::get('/view-branch', [BranchController::class, 'viewBranch'])->name('view.branch');
    Route::get('/positions', [PositionController::class, 'index']);
    Route::post('/register', [RegisterController::class, 'register']);
    Route::post('/login', [LoginController::class, 'login']);

    // Protected endpoints
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::post('/logout', [LoginController::class, 'logout']);

        // Admin-only endpoints
        // User management endpoints (CRUD)
        Route::get('/view-users', [UserController::class, 'index']);
        Route::post('/verified-user/{id}', [UserController::class, 'verify']);
        Route::delete('/delete-user/{id}', [UserController::class, 'destroy']);
        Route::post('/update-profile/{user}', [UserController::class, 'update'])
            ->missing(fn () => response()->json(['message' => 'User not found.'], 404));

        // Position management endpoints
        Route::post('/create-position', [AdminPositionController::class, 'store']);
        Route::put('/update-position/{position}', [AdminPositionController::class, 'update'])->missing(
            fn () => response()->json(['message' => 'Position not found.'], 404));
        Route::delete('/delete-position/{position}', [AdminPositionController::class, 'destroy'])->missing(
            fn () => response()->json(['message' => 'Position not found'], 404));

        // Branch management endpoints
        Route::post('/add-branch', [AdminBranchController::class, 'store']);
        Route::put('/update-branch/{branch}', [AdminBranchController::class, 'update'])
            ->missing(
                fn () => response()->json(['message' => 'Branch not found'], 404));
        Route::delete('/delete-branch/{branch}', [AdminBranchController::class, 'destroy'])->missing(
            fn () => response()->json(['message' => 'Branch not found'], 404));

        // Approvers Management endpoints
        Route::get('/approvers', [ApproverController::class, 'index']);
        Route::post('/approvers', [ApproverController::class, 'store']);
        Route::delete('/approvers/{user}', [ApproverController::class, 'destroy'])->missing(
            fn () => response()->json(['message' => 'Approver not found.'], 404));
        Route::get('/approvers/candidates', [ApproverController::class, 'candidates']);

        // AVP Staff candidate endpoints
        Route::get('/avp-staffs/avp-candidates', [AvpStaffController::class, 'avpCandidates']);
        Route::get('/avp-staffs/staff-candidates/{avp}', [AvpStaffController::class, 'staffCandidates']
        )->missing(
            fn () => response()->json([
                'message' => 'Selected AVP account not found.',
            ], 404)
        );
        Route::get('/avp-staffs/available-branches', [AvpStaffController::class, 'availableBranches']);
        // Create an AVP Staff branch-routing assignment.
        Route::post('/avp-staffs', [AvpStaffController::class, 'store']);
        // List configured AVP Staff assignments.
        Route::get('/avp-staffs', [AvpStaffController::class, 'index']);
        Route::put('/avp-staffs/{assignment}', [AvpStaffController::class, 'update'])->missing(
            fn () => response()->json([
                'message' => 'AVP Staff assignment not found.',
            ], 404)
        );
        Route::delete('/avp-staffs/{assignment}', [AvpStaffController::class, 'destroy'])->missing(
            fn () => response()->json([
                'message' => 'AVP Staff assignment not found.',
            ], 404)
        );
    });
});
