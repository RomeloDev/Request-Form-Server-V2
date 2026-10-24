<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable([
    'branch_code',
    'branch_name',
    'branch',
    'acronym',
])]

class Branch extends Model
{
    use HasFactory;
}
