<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentRegistration extends Model
{
    protected $fillable = ['tenant_id', 'tournament_id', 'user_id', 'status'];
}
