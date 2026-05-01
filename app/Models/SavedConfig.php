<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SavedConfig extends Model
{
    use HasUuids;

    protected $fillable = ['mageos_version', 'selection'];

    protected $casts = [
        'selection' => 'array',
    ];
}
