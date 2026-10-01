<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentTeam extends Model
{
    protected $fillable = ['tenant_id', 'tournament_id', 'name'];
}
