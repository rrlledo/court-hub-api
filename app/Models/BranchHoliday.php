<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchHoliday extends Model
{
    protected $fillable = ['tenant_id', 'branch_id', 'holiday_date', 'name', 'is_closed'];

    protected function casts(): array
    {
        return ['holiday_date' => 'date', 'is_closed' => 'boolean'];
    }
}
