<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Court;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\TournamentTeam;
use App\Models\TournamentTeamMember;
use App\Models\User;
use Illuminate\Http\Request;

class OrganizerWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        return Tournament::where('tenant_id', $request->user()->tenant_id)->latest('starts_at')->paginate(30);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:150'], 'format' => ['required', 'in:singles,doubles,team'], 'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'entry_fee' => ['nullable', 'numeric', 'min:0'], 'capacity' => ['nullable', 'integer', 'min:1']]);
        Branch::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['branch_id']);

        return response()->json(['data' => Tournament::create($data + ['tenant_id' => $request->user()->tenant_id])], 201);
    }

    public function show(Request $request, int $tournament)
    {
        $model = $this->tournament($request, $tournament);

        return response()->json(['data' => $model->toArray() + [
            'registrations' => TournamentRegistration::where('tournament_id', $model->id)->latest('id')->get()->map(fn (TournamentRegistration $registration) => $registration->toArray() + ['player' => User::find($registration->user_id)?->only(['id', 'name'])]),
            'matches' => TournamentMatch::where('tournament_id', $model->id)->orderBy('round_number')->orderBy('match_number')->get(),
            'teams' => $this->teamsData($model),
        ]]);
    }

    public function update(Request $request, int $tournament)
    {
        $model = $this->tournament($request, $tournament);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:150'], 'status' => ['sometimes', 'in:draft,open,ongoing,completed,cancelled'], 'capacity' => ['nullable', 'integer', 'min:1'], 'ends_at' => ['nullable', 'date']]));

        return response()->json(['data' => $model]);
    }

    public function players(Request $request)
    {
        return response()->json(['data' => User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->role('player')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])]);
    }

    public function register(Request $request, int $tournament)
    {
        $model = $this->tournament($request, $tournament);
        abort_if(! in_array($model->status, ['draft', 'open'], true), 422, 'Tournament registration is closed.');
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        User::where('tenant_id', $request->user()->tenant_id)->role('player')->findOrFail($data['user_id']);
        abort_if($model->capacity && TournamentRegistration::where('tournament_id', $model->id)->where('status', '!=', 'cancelled')->count() >= $model->capacity, 409, 'Tournament capacity has been reached.');

        $registration = TournamentRegistration::firstOrCreate(
            ['tournament_id' => $model->id, 'user_id' => $data['user_id']],
            ['tenant_id' => $model->tenant_id, 'status' => 'registered'],
        );
        if ($registration->status === 'cancelled') {
            $registration->update(['status' => 'registered']);
        }

        return response()->json(['data' => $registration->fresh()], $registration->wasRecentlyCreated ? 201 : 200);
    }

    public function cancelRegistration(Request $request, int $tournament, int $registration)
    {
        $model = TournamentRegistration::where('tournament_id', $this->tournament($request, $tournament)->id)->findOrFail($registration);
        $model->update(['status' => 'cancelled']);

        return response()->json(['data' => $model]);
    }

    public function createMatch(Request $request, int $tournament)
    {
        $model = $this->tournament($request, $tournament);
        $data = $request->validate(['court_id' => ['nullable', 'integer'], 'player_one_registration_id' => ['nullable', 'integer'], 'player_two_registration_id' => ['nullable', 'integer'], 'round_number' => ['required', 'integer', 'min:1'], 'match_number' => ['required', 'integer', 'min:1'], 'starts_at' => ['nullable', 'date']]);
        if (isset($data['court_id'])) {
            Court::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['court_id']);
        }
        foreach (['player_one_registration_id', 'player_two_registration_id'] as $key) {
            if (isset($data[$key])) {
                TournamentRegistration::where('tournament_id', $model->id)->findOrFail($data[$key]);
            }
        }

        return response()->json(['data' => TournamentMatch::create($data + ['tenant_id' => $request->user()->tenant_id, 'tournament_id' => $model->id])], 201);
    }

    public function teams(Request $request, int $tournament)
    {
        return response()->json(['data' => $this->teamsData($this->tournament($request, $tournament))]);
    }

    public function createTeam(Request $request, int $tournament)
    {
        $model = $this->tournament($request, $tournament);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $team = TournamentTeam::firstOrCreate(['tournament_id' => $model->id, 'name' => $data['name']], ['tenant_id' => $model->tenant_id]);

        return response()->json(['data' => $this->teamData($team)], $team->wasRecentlyCreated ? 201 : 200);
    }

    public function addTeamMember(Request $request, int $tournament, int $team)
    {
        $model = $this->tournament($request, $tournament);
        $teamModel = TournamentTeam::where('tournament_id', $model->id)->findOrFail($team);
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        User::where('tenant_id', $model->tenant_id)->role('player')->findOrFail($data['user_id']);
        TournamentTeamMember::firstOrCreate(['tournament_team_id' => $teamModel->id, 'user_id' => $data['user_id']]);
        TournamentRegistration::updateOrCreate(['tournament_id' => $model->id, 'user_id' => $data['user_id']], ['tenant_id' => $model->tenant_id, 'tournament_team_id' => $teamModel->id, 'status' => 'registered']);

        return response()->json(['data' => $this->teamData($teamModel)], 201);
    }

    public function checkIn(Request $request, int $tournament, int $registration)
    {
        $model = TournamentRegistration::where('tournament_id', $this->tournament($request, $tournament)->id)->findOrFail($registration);
        abort_if($model->status === 'cancelled', 422, 'Cancelled registrations cannot check in.');
        $model->update(['checked_in_at' => $model->checked_in_at ?? now(), 'checked_in_by' => $request->user()->id]);

        return response()->json(['data' => $model->fresh()]);
    }

    public function updateMatch(Request $request, int $match)
    {
        $model = TournamentMatch::where('tenant_id', $request->user()->tenant_id)->findOrFail($match);
        $data = $request->validate(['winner_registration_id' => ['nullable', 'integer'], 'score' => ['nullable', 'string', 'max:100'], 'status' => ['sometimes', 'in:scheduled,ongoing,completed,cancelled'], 'starts_at' => ['nullable', 'date']]);
        if (isset($data['winner_registration_id'])) {
            TournamentRegistration::where('tournament_id', $model->tournament_id)->findOrFail($data['winner_registration_id']);
        }
        $model->update($data);

        return response()->json(['data' => $model]);
    }

    private function tournament(Request $request, int $id): Tournament
    {
        return Tournament::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function teamsData(Tournament $tournament)
    {
        return TournamentTeam::where('tournament_id', $tournament->id)->orderBy('name')->get()->map(fn (TournamentTeam $team) => $this->teamData($team));
    }

    private function teamData(TournamentTeam $team): array
    {
        return $team->toArray() + ['members' => TournamentTeamMember::where('tournament_team_id', $team->id)->get()->map(fn (TournamentTeamMember $member) => User::find($member->user_id)?->only(['id', 'name', 'email']))->filter()->values()];
    }
}
