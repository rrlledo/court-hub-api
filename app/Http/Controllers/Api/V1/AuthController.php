<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_name' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $tenant = Tenant::create([
                'name' => $data['tenant_name'],
                'slug' => Str::slug($data['tenant_name']).'-'.Str::lower(Str::random(6)),
            ]);
            Role::findOrCreate('court-owner', 'web');
            $user = User::create(['tenant_id' => $tenant->id, 'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->assignRole('court-owner');

            return $user;
        });
        $user->sendEmailVerificationNotification();

        return response()->json(['data' => ['user' => $user->only('id', 'tenant_id', 'name', 'email'), 'token' => $user->createToken($request->input('device_name', 'web'))->plainTextToken]], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'two_factor_code' => ['nullable', 'string'], 'device_name' => ['nullable', 'string', 'max:100']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'The supplied credentials are incorrect.'], 422);
        }
        if (! $user->hasRole('super-admin') && $user->tenant && ! $user->tenant->is_active) {
            return response()->json(['message' => 'This tenant has been suspended.'], 403);
        }
        if ($user->two_factor_confirmed_at && ! app(TotpService::class)->verify($user->two_factor_secret, $data['two_factor_code'] ?? '')) {
            return response()->json(['message' => 'A valid two-factor authentication code is required.'], 422);
        }

        return response()->json(['data' => ['user' => $user->only('id', 'tenant_id', 'name', 'email'), 'token' => $user->createToken($data['device_name'] ?? 'web')->plainTextToken]]);
    }

    public function socialLogin(Request $request, string $provider): JsonResponse
    {
        abort_if(! in_array($provider, ['google', 'apple', 'facebook'], true), 422, 'Unsupported social sign-in provider.');
        $data = $request->validate([
            'mock_subject' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'facility_id' => ['nullable', 'integer'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'two_factor_code' => ['nullable', 'string'],
        ]);
        $account = SocialAccount::where('provider', $provider)->where('provider_user_id', $data['mock_subject'])->first();
        if ($account) {
            $user = User::findOrFail($account->user_id);
        } else {
            abort_unless(isset($data['facility_id']), 422, 'Select a facility to create a player account.');
            $facility = Facility::where('registration_open', true)->findOrFail($data['facility_id']);
            $user = User::where('email', $data['email'])->first();
            if ($user) {
                abort_unless($user->tenant_id === $facility->tenant_id, 409, 'This email belongs to another tenant.');
            } else {
                Role::findOrCreate('player', 'web');
                $user = User::create(['tenant_id' => $facility->tenant_id, 'home_facility_id' => $facility->id, 'name' => $data['name'] ?? Str::before($data['email'], '@'), 'email' => $data['email'], 'password' => Hash::make(Str::random(48)), 'email_verified_at' => now()]);
                $user->assignRole('player');
            }
            SocialAccount::create(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $data['mock_subject']]);
        }
        if (! $user->hasRole('super-admin') && $user->tenant && ! $user->tenant->is_active) {
            return response()->json(['message' => 'This tenant has been suspended.'], 403);
        }
        if ($user->two_factor_confirmed_at && ! app(TotpService::class)->verify($user->two_factor_secret, $data['two_factor_code'] ?? '')) {
            return response()->json(['message' => 'A valid two-factor authentication code is required.'], 422);
        }

        return response()->json(['data' => ['user' => $user->only('id', 'tenant_id', 'name', 'email'), 'token' => $user->createToken($data['device_name'] ?? 'social-mock')->plainTextToken, 'mock' => true]], $account ? 200 : 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant');

        return response()->json(['data' => ['user' => $user->only('id', 'tenant_id', 'home_facility_id', 'name', 'email', 'email_verified_at', 'avatar_path'), 'facility' => $user->homeFacility?->only('id', 'name', 'address'), 'tenant' => $user->tenant?->only('id', 'name', 'slug', 'timezone', 'country_code'), 'roles' => $user->getRoleNames()->values(), 'two_factor_enabled' => (bool) $user->two_factor_confirmed_at]]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => $data['email']]);

        return response()->json(['message' => 'If the account exists, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
        });

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password has been reset.'])
            : response()->json(['message' => __($status)], 422);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'The current password is incorrect.');
        $request->user()->update(['password' => $data['password']]);
        $request->user()->tokens()->whereKeyNot($request->user()->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Password has been changed.']);
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $name = $request->input('device_name', $user->currentAccessToken()?->name ?? 'web');
        $user->currentAccessToken()?->delete();

        return response()->json(['data' => ['token' => $user->createToken($name)->plainTextToken]]);
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->tokens()->get(['id', 'name', 'last_used_at', 'created_at'])]);
    }

    public function destroySession(Request $request, int $token): JsonResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return response()->json(status: 204);
    }

    public function sendVerification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email address is already verified.']);
        }
        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Use the signed verification link sent to your email.');
        $data = $request->validate(['hash' => ['required', 'string']]);
        $user = $request->user();
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $data['hash']), 403, 'Invalid verification hash.');
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json(['message' => 'Email address has been verified.']);
    }

    public function setupTwoFactor(Request $request): JsonResponse
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $request->user()->update(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => null]);

        return response()->json(['data' => ['secret' => $secret, 'provisioning_uri' => $totp->provisioningUri($secret, $request->user()->email)]]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $secret = $this->twoFactorSecret($request->user());
        abort_unless($secret && app(TotpService::class)->verify($secret, $data['code']), 422, 'Invalid authenticator code.');
        $request->user()->update(['two_factor_confirmed_at' => now()]);

        return response()->json(['message' => 'Two-factor authentication enabled.']);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $secret = $this->twoFactorSecret($request->user());
        abort_unless($secret && app(TotpService::class)->verify($secret, $data['code']), 422, 'Invalid authenticator code.');
        $request->user()->update(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    private function twoFactorSecret(User $user): ?string
    {
        return $user->two_factor_secret ? decrypt($user->two_factor_secret) : null;
    }
}
