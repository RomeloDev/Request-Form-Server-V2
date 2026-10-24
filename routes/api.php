<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\BranchController;
use App\Http\Controllers\API\V1\PositionController;
use App\Http\Controllers\API\V1\Auth\RegisterController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::get('/view-branch', [BranchController::class,'viewBranch'])->name('view.branch');
    Route::get('/positions', [PositionController::class, 'index']);
    Route::post('/register', [RegisterController::class, 'register']);
});
