<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentMatch extends Model
{
    protected $fillable = ['tenant_id', 'tournament_id', 'court_id', 'player_one_registration_id', 'player_two_registration_id', 'winner_registration_id', 'round_number', 'match_number', 'starts_at', 'status', 'score'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }
}
