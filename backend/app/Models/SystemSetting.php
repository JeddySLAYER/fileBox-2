<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'type', 'description'])]
class SystemSetting extends Model
{
    protected $table = 'system_settings';
}
