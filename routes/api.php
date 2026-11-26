<?php

use App\Http\Controllers\API\V1\Admin\ApproverController;
use App\Http\Controllers\API\V1\Admin\AvpStaffController;
use App\Http\Controllers\API\V1\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\API\V1\Admin\BranchHeadController;
use App\Http\Controllers\API\V1\Admin\PositionController as AdminPositionController;
use App\Http\Controllers\API\V1\Admin\RequestReportController;
use App\Http\Controllers\API\V1\Admin\UserController;
use App\Http\Controllers\API\V1\Auth\LoginController;
use App\Http\Controllers\API\V1\Auth\PasswordResetController;
use App\Http\Controllers\API\V1\Auth\ProfileController;
use App\Http\Controllers\API\V1\Auth\ProfilePasswordController;
use App\Http\Controllers\API\V1\Auth\ProfileSignatureController;
use App\Http\Controllers\API\V1\Auth\RegisterController;
use App\Http\Controllers\API\V1\BankController;
use App\Http\Controllers\API\V1\BranchController;
use App\Http\Controllers\API\V1\DashboardController;
use App\Http\Controllers\API\V1\FeedbackController;
use App\Http\Controllers\API\V1\NotificationController;
use App\Http\Controllers\API\V1\PositionController;
use App\Http\Controllers\API\V1\RequestFormController;
use App\Http\Controllers\API\V1\SupplierController;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    // Health check endpoint
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    // Public endpoints
    Route::get('/view-branch', [BranchController::class, 'viewBranch'])->name('view.branch');
    Route::get('/positions', [PositionController::class, 'index']);
    Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:20,1,forgot-password');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:20,1,reset-password');

    // Feedback endpoint
    Route::post(
        '/send-feedback',
        [FeedbackController::class, 'store'],
    )->middleware('throttle:30,1');

    // Protected endpoints
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/profile', [ProfileController::class, 'show'])
            ->name('profile.show');
        Route::post('/logout', [LoginController::class, 'logout']);

        // Authenticated, role-gated dashboard.
        Route::get('/dashboard', [DashboardController::class, 'show'])
            ->name('dashboard.show');

        // Admin-only endpoints
        // Admin user management endpoints, including protected media reads.
        // Only implemented actions are registered here.
        Route::prefix('admin/users')->name('admin.users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::post('/', [UserController::class, 'store'])
                ->name('store');
            Route::post('/bulk', [UserController::class, 'storeBulk'])
                ->name('bulk-store');
            Route::get('/{user}/profile-picture', [UserController::class, 'profilePicture'])
                ->name('profile-picture.show');
            Route::get('/{user}/signature', [UserController::class, 'signature'])
                ->name('signature.show');
            Route::post('/{user}/verify', [UserController::class, 'verify'])->name('verify');
        });

        // Legacy user management endpoints kept for frontend compatibility.
        Route::get('/view-users', [UserController::class, 'index']);
        Route::post('/verified-user/{user}', [UserController::class, 'verify']);
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

        // Branch Head candidate and branch-selection endpoints.
        Route::get(
            '/branch-heads/candidates',
            [BranchHeadController::class, 'candidates']
        );
        Route::get(
            '/branch-heads/available-branches',
            [BranchHeadController::class, 'availableBranches']
        );
        // Branch Head assignment management endpoints.
        Route::get(
            '/branch-heads',
            [BranchHeadController::class, 'index']
        );
        Route::post(
            '/branch-heads',
            [BranchHeadController::class, 'store']
        );
        Route::get(
            '/branch-heads/{assignment}',
            [BranchHeadController::class, 'show']
        )->missing(
            fn () => response()->json([
                'message' => 'Branch Head assignment not found.',
            ], 404)
        );
        Route::put(
            '/branch-heads/{assignment}',
            [BranchHeadController::class, 'update']
        )->missing(
            fn () => response()->json([
                'message' => 'Branch Head assignment not found.',
            ], 404)
        );
        Route::delete(
            '/branch-heads/{assignment}',
            [BranchHeadController::class, 'destroy']
        )->missing(
            fn () => response()->json([
                'message' => 'Branch Head assignment not found.',
            ], 404)
        );

        /*
        * User request endpoints.
        */
        Route::get(
            '/request-forms/approver-candidates',
            [RequestFormController::class, 'approverCandidates']
        )->name('request-forms.approver-candidates.index');
        Route::get(
            '/request-forms/{requestForm}/share-candidates',
            [RequestFormController::class, 'shareCandidates']
        )->missing(
            fn () => response()->json([
                'message' => 'Request not found.',
            ], 404),
        )->name('request-forms.share-candidates.index');
        Route::get(
            '/request-forms/{requestForm}/shares',
            [RequestFormController::class, 'indexShares']
        )->missing(
            fn () => response()->json([
                'message' => 'Request not found.',
            ], 404),
        )->name('request-forms.shares.index');
        Route::post(
            '/request-forms/{requestForm}/shares',
            [RequestFormController::class, 'storeShare']
        )->missing(
            fn () => response()->json([
                'message' => 'Request not found.',
            ], 404),
        )->name('request-forms.shares.store');
        Route::delete(
            '/request-forms/{requestForm}/shares/{recipient}',
            [RequestFormController::class, 'destroyShare']
        )->whereNumber('recipient')
            ->missing(
                fn () => response()->json([
                    'message' => 'Request not found.',
                ], 404),
            )->name('request-forms.shares.destroy');
        Route::post(
            '/request-forms/stock-requisitions',
            [RequestFormController::class, 'storeStockRequisition']
        )->middleware(['throttle:50,1', 'signature.required'])
            ->name('request-forms.stock-requisitions.store');
        Route::post(
            '/request-forms/purchase-orders',
            [RequestFormController::class, 'storePurchaseOrder']
        )->middleware(['throttle:50,1', 'signature.required'])
            ->name('request-forms.purchase-orders.store');
        Route::post(
            '/request-forms/cash-disbursements',
            [RequestFormController::class, 'storeCashDisbursement']
        )->middleware(['throttle:50,1', 'signature.required'])
            ->name('request-forms.cash-disbursements.store');
        Route::get('/request-forms', [RequestFormController::class, 'index'])->name('request-forms.index');
        Route::get('/request-forms/{requestForm}', [RequestFormController::class, 'show'])->missing(
            fn () => response()->json([
                'message' => 'Request not found.',
            ], 404),
        )->name('request-forms.show');
        Route::get(
            '/request-forms/{requestForm}/attachments/{attachment}/preview',
            [RequestFormController::class, 'previewAttachment']
        )->scopeBindings()
            ->name('request-forms.attachments.preview');
        Route::get(
            '/request-forms/{requestForm}/attachments/{attachment}/download',
            [RequestFormController::class, 'downloadAttachment']
        )->scopeBindings()
            ->name('request-forms.attachments.download');
        Route::get(
            '/request-forms/{requestForm}/approval-steps/{approvalStep}/attachments/{approvalAttachment}/preview',
            [RequestFormController::class, 'previewApprovalAttachment']
        )->scopeBindings()
            ->name('request-forms.approval-attachments.preview');
        Route::get(
            '/request-forms/{requestForm}/approval-steps/{approvalStep}/attachments/{approvalAttachment}/download',
            [RequestFormController::class, 'downloadApprovalAttachment']
        )->scopeBindings()
            ->name('request-forms.approval-attachments.download');
        Route::patch(
            '/request-forms/{requestForm}/cancel',
            [RequestFormController::class, 'cancel'],
        )
            ->missing(
                fn () => response()->json([
                    'message' => 'Request not found.',
                ], 404),
            )
            ->name('request-forms.cancel');
        Route::get(
            '/request-forms/{requestForm}/print',
            [RequestFormController::class, 'showPrintable'],
        )->missing(
            fn () => response()->json([
                'message' => 'Request not found.',
            ], 404),
        )->name('request-forms.print.show');
        Route::get(
            '/request-forms/{requestForm}/print/signatures/{signatureOwner}',
            [RequestFormController::class, 'viewPrintableSignature'],
        )->missing(
            fn () => response()->json([
                'message' => 'Request or signature not found.',
            ], 404),
        )->name('request-forms.print.signatures.show');
        Route::patch(
            '/request-forms/{requestForm}/stock-requisition',
            [RequestFormController::class, 'updateStockRequisition'],
        )->middleware(['throttle:50,1', 'signature.required'])
            ->missing(
                fn () => response()->json([
                    'message' => 'Request not found.',
                ], 404),
            )->name('request-forms.stock-requisitions.update');
        Route::patch(
            '/request-forms/{requestForm}/purchase-order',
            [RequestFormController::class, 'updatePurchaseOrder'],
        )->middleware('signature.required')
            ->missing(
                fn () => response()->json([
                    'message' => 'Request not found.',
                ], 404),
            )->name('request-forms.purchase-orders.update');
        Route::patch(
            '/request-forms/{requestForm}/cash-disbursement',
            [RequestFormController::class, 'updateCashDisbursement'],
        )->middleware(['throttle:50,1', 'signature.required'])
            ->missing(
                fn () => response()->json([
                    'message' => 'Request not found.',
                ], 404),
            )->name('request-forms.cash-disbursements.update');
        Route::patch(
            '/request-forms/{requestForm}/check-issuance',
            [RequestFormController::class, 'updateCheckIssuance'],
        )->middleware(['throttle:50,1', 'signature.required'])
            ->missing(
                fn () => response()->json([
                    'message' => 'Request not found.',
                ], 404),
            )
            ->name('request-forms.check-issuances.update');
        Route::delete(
            '/request-forms/{requestForm}',
            [RequestFormController::class, 'destroy'],
        )
            ->middleware('throttle:50,1')
            ->missing(
                fn () => response()->json([
                    'message' => 'Request not found.',
                ], 404),
            )->name('request-forms.destroy');

        Route::get(
            '/approver/request-forms',
            [RequestFormController::class, 'assignedRequests'],
        )->name('approver.request-forms.index');
        Route::get(
            '/approver/request-forms/{requestForm}',
            [RequestFormController::class, 'showAssignedRequest'],
        )->missing(
            fn () => response()->json([
                'message' => 'Assigned request not found.',
            ], 404),
        )->name('approver.request-forms.show');
        Route::patch(
            '/approver/request-forms/{requestForm}/decision',
            [RequestFormController::class, 'decideAssignedRequest'],
        )->middleware('signature.required')
            ->missing(
                fn () => response()->json([
                    'message' => 'Assigned request not found.',
                ], 404),
            )
            ->name('approver.request-forms.decision');

        /*
        * Recipient-side shared request endpoints.
        */
        Route::get(
            '/shared-requests',
            [RequestFormController::class, 'sharedRequests'],
        )->name('shared-requests.index');
        Route::get(
            '/shared-requests/{requestForm}',
            [RequestFormController::class, 'showSharedRequest'],
        )->missing(
            fn () => response()->json([
                'message' => 'Shared request not found.',
            ], 404),
        )->name('shared-requests.show');

        // Supplier List Endpoint
        Route::get(
            '/suppliers',
            [SupplierController::class, 'index'],
        )->name('suppliers.index');

        // Bank List Endpoint
        Route::get(
            '/banks',
            [BankController::class, 'index'],
        )->name('banks.index');

        // Check Issuance Requisition Slip endpoints
        Route::post(
            '/request-forms/check-issuances',
            [RequestFormController::class, 'storeCheckIssuance'],
        )->middleware(['throttle:50,1', 'signature.required'])
            ->name('request-forms.check-issuances.store');

        // Admin Reports endpoint
        Route::get(
            '/admin/request-reports',
            [RequestReportController::class, 'index'],
        )->name('admin.request-reports.index');
        Route::get(
            '/admin/request-reports/{requestForm}',
            [RequestReportController::class, 'show'],
        )
            ->whereNumber('requestForm')
            ->missing(
                fn () => response()->json([
                    'message' => 'Request report not found.',
                ], 404),
            )
            ->name('admin.request-reports.show');

        // Profile Page endpoints
        Route::get(
            '/profile/picture',
            [ProfileController::class, 'showPicture'],
        )->name('profile.picture.show');
        Route::post(
            '/profile/picture',
            [ProfileController::class, 'updatePicture'],
        )->name('profile.picture.update');
        Route::delete(
            '/profile/picture',
            [ProfileController::class, 'destroyPicture'],
        )->name('profile.picture.destroy');
        Route::get(
            '/profile/signature',
            [ProfileSignatureController::class, 'show'],
        )->name('profile.signature.show');
        Route::post(
            '/profile/signature',
            [ProfileSignatureController::class, 'update'],
        )->name('profile.signature.update');
        Route::delete(
            '/profile/signature',
            [ProfileSignatureController::class, 'destroy'],
        )->name('profile.signature.destroy');
        Route::put(
            '/profile/password',
            [ProfilePasswordController::class, 'update'],
        )->name('profile.password.update');

        // Feedback Viewing endpoint
        Route::get(
            '/admin/feedbacks',
            [FeedbackController::class, 'index'],
        )->name('admin.feedbacks.index');

        /*
         * Authenticated notification read endpoints. Both are scoped to the
         * authenticated user's own notifications; no identity selector is
         * accepted.
         */
        Route::get(
            '/notifications/unread-count',
            [NotificationController::class, 'unreadCount'],
        )->name('notifications.unread-count');
        Route::get(
            '/notifications',
            [NotificationController::class, 'index'],
        )->name('notifications.index');

        /*
         * Authenticated notification mutations. Ownership is resolved through
         * the authenticated user's relationship, never a global UUID lookup.
         */
        Route::patch(
            '/notifications/read-all',
            [NotificationController::class, 'markAllAsRead'],
        )->name('notifications.read-all');
        Route::patch(
            '/notifications/{notification}/read',
            [NotificationController::class, 'markAsRead'],
        )->name('notifications.read');
    });
});
