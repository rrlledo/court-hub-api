<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Facility, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class PlayerRegistrationController extends Controller
{
    private function available()
    {
        return Facility::where('registration_open', true)->whereHas('branches.courts', fn ($q) => $q->where('status', 'active'));
    }
    public function facilities(Request $request)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = $this->available();
        if (!empty($data['search'])) $query->where('name', 'like', '%'.$data['search'].'%');
        return $query->select(['id', 'name', 'address', 'timezone'])->orderBy('name')->orderBy('id')->paginate(20);
    }
    public function register(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'facility_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'tenant_id' => ['prohibited'], 'roles' => ['prohibited'], 'role' => ['prohibited'],
        ]);
        $user = DB::transaction(function () use ($data) {
            $facility = $this->available()->lockForUpdate()->findOrFail($data['facility_id']);
            Role::findOrCreate('player', 'web');
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                'tenant_id' => $facility->tenant_id, 'home_facility_id' => $facility->id]);
            $user->assignRole('player');
            return $user;
        });
        $user->sendEmailVerificationNotification();
        return response()->json(['data' => ['user' => $user->only(['id', 'name', 'email', 'home_facility_id']),
            'token' => $user->createToken('Court Hub Mobile')->plainTextToken]], 201);
    }
}
