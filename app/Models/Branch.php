<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'branch_code',
    'branch_name',
    'branch',
    'acronym',
])]

class Branch extends Model
{
    
}
