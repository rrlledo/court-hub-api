<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentRegistration extends Model
{
    protected $fillable = ['tenant_id', 'tournament_id', 'user_id', 'tournament_team_id', 'status', 'checked_in_at', 'checked_in_by'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
