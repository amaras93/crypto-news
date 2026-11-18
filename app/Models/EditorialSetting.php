<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'default_author_id', 'default_category_id'])]
class EditorialSetting extends Model
{
    use HasFactory;
}
