<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\ListFeedbackRequest;
use App\Http\Requests\Feedback\StoreFeedbackRequest;
use App\Http\Resources\FeedbackResource;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class FeedbackController extends Controller
{
    public function index(ListFeedbackRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $feedbacks = Feedback::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return FeedbackResource::collection($feedbacks)
            ->additional([
                'message' => 'Feedback retrieved successfully.',
            ])
            ->response();
    }

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $feedback = new Feedback($request->safe()->only([
            'name',
            'email',
            'phone',
            'department',
            'opinion',
            'other_opinion',
            'message',
        ]));

        $feedback->feedback_code = 'SMCT-'.Str::upper(Str::random(50));
        $feedback->save();

        return response()->json([
            'message' => 'Your feedback has been submitted successfully. We will contact you soon.',
            'feedback_code' => $feedback->feedback_code,
        ], 201);
    }
}
