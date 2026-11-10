<?php

namespace App\Models;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'form_type',
    'kind_of_request',
    'currency',
    'form_data',
    'cancellation_reason',
])]
class RequestForm extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'grand_total' => 'decimal:2',
            'form_type' => RequestFormType::class,
            'status' => RequestStatus::class,
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class)->orderBy('line_number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequestAttachment::class);
    }

    /**
     * The ordered approval chain resolved when this request was submitted.
     */
    public function approvalSteps(): HasMany
    {
        return $this->hasMany(RequestApprovalStep::class)
            ->orderBy('level');
    }

    /**
     * Users this request has been explicitly shared with.
     */
    public function sharedRecipients(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'request_shares',
            'request_form_id',
            'recipient_id',
        )->withTimestamps();
    }
}
