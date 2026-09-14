<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtType extends Model
{
    protected $fillable = ['tenant_id', 'name', 'sport', 'description'];
}
