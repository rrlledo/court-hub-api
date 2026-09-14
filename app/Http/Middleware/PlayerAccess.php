<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PlayerAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        // Customer-only accounts use explicitly supported, owner-scoped APIs.
        if ($user->hasRole('player') && $user->getRoleNames()->count() === 1) {
            $route = $request->route()->uri();
            $allowed = [
                'auth/logout', 'auth/me', 'auth/change-password', 'auth/refresh-token', 'auth/sessions',
                'auth/sessions/{token}', 'auth/email/verification-notification', 'auth/email/verify',
                'auth/two-factor/setup', 'auth/two-factor/confirm', 'auth/two-factor/disable',
                'profile', 'profile/avatar', 'notifications', 'notifications/read-all',
                'notifications/{notification}/read', 'notification-preferences',
                'booking-facilities', 'facilities/{facility}/branches', 'branches/{branch}/courts',
                'courts/{court}/availability', 'bookings/history', 'bookings',
                'bookings/{booking}/cancel', 'bookings/{booking}/reschedule',
                'bookings/{booking}/payment-status', 'payments/intents', 'payments/providers', 'payments/{payment}/invoice',
            ];
            abort_unless(in_array($route, array_map(fn ($path) => 'api/v1/'.$path, $allowed), true), 403, 'This feature is not available for player accounts yet.');
            if ($request->isMethod('POST') && in_array($route, ['api/v1/bookings', 'api/v1/payments/intents'], true)) {
                abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email before booking or paying.');
            }
        }
        return $next($request);
    }
}
