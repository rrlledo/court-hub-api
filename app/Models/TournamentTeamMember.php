<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentTeamMember extends Model
{
    protected $fillable = ['tournament_team_id', 'user_id'];
}
