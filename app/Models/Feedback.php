<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'email',
    'phone',
    'department',
    'opinion',
    'other_opinion',
    'message',
    'feedback_code',
])]
class Feedback extends Model
{
    //
}
