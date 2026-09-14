<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    private const ASSIGNABLE_ROLES = ['facility-manager', 'front-desk', 'coach', 'event-organizer', 'player'];

    public function index(Request $request)
    {
        return response()->json(['data' => User::query()->where('tenant_id', $request->user()->tenant_id)->with('roles')->paginate()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'roles' => ['nullable', 'array'],
            'roles.*' => [Rule::in(self::ASSIGNABLE_ROLES)],
        ]);
        $this->ensureRoles($data['roles'] ?? ['player']);
        $user = User::create(['tenant_id' => $request->user()->tenant_id, 'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $user->syncRoles($data['roles'] ?? ['player']);
        $this->log($request, 'created', $user, ['roles' => $user->getRoleNames()->values()->all()]);

        return response()->json(['data' => $user->load('roles')], 201);
    }

    public function show(Request $request, int $user): JsonResponse
    {
        return response()->json(['data' => $this->user($request, $user)->load('roles')]);
    }

    public function update(Request $request, int $user): JsonResponse
    {
        $model = $this->user($request, $user);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($model->id)],
        ]);
        $model->update($data);
        $this->log($request, 'updated', $model, array_keys($data));

        return response()->json(['data' => $model->load('roles')]);
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        $model = $this->user($request, $user);
        abort_if($model->is($request->user()), 422, 'You cannot delete your own account.');
        $this->log($request, 'deleted', $model);
        $model->tokens()->delete();
        $model->delete();

        return response()->json(status: 204);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:12', 'confirmed'],
        ]);
        $user->update($data);
        $this->log($request, 'profile_updated', $user, array_keys($data));

        return response()->json(['data' => $user->load('roles')]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $data = $request->validate(['avatar' => ['required', 'image', 'max:2048']]);
        $user = $request->user();
        $user->update(['avatar_path' => $data['avatar']->store('avatars', 'public')]);
        $this->log($request, 'avatar_uploaded', $user);

        return response()->json(['data' => $user->only('id', 'avatar_path')]);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name'])]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name'])]);
    }

    public function syncRoles(Request $request, int $user): JsonResponse
    {
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::in(self::ASSIGNABLE_ROLES)]]);
        $model = $this->user($request, $user);
        abort_if($model->is($request->user()), 422, 'You cannot change your own roles.');
        $this->ensureRoles($data['roles']);
        $model->syncRoles($data['roles']);
        $this->log($request, 'roles_updated', $model, ['roles' => $data['roles']]);

        return response()->json(['data' => $model->load('roles')]);
    }

    public function activityLogs(Request $request): JsonResponse
    {
        return response()->json(['data' => ActivityLog::query()->where('tenant_id', $request->user()->tenant_id)->latest()->paginate()]);
    }

    private function user(Request $request, int $id): User
    {
        return User::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function log(Request $request, string $event, User $subject, array $properties = []): void
    {
        ActivityLog::create(['tenant_id' => $request->user()->tenant_id, 'actor_id' => $request->user()->id, 'subject_type' => User::class, 'subject_id' => $subject->id, 'event' => $event, 'properties' => $properties, 'ip_address' => $request->ip()]);
    }

    private function ensureRoles(array $roles): void
    {
        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
