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
            // Route URI includes the API prefix in HTTP requests but may omit it
            // in tests or framework integrations; authorise by the stable v1 path.
            $route = preg_replace('#^(?:api/)?v1/#', '', $request->route()->uri());
            $allowed = [
                'auth/logout', 'auth/me', 'auth/change-password', 'auth/refresh-token', 'auth/sessions',
                'auth/sessions/{token}', 'auth/email/verification-notification', 'auth/email/verify',
                'auth/two-factor/setup', 'auth/two-factor/confirm', 'auth/two-factor/disable',
                'profile', 'profile/avatar', 'notifications', 'notifications/read-all',
                'notifications/{notification}/read', 'notification-preferences',
                'push/devices',
                'booking-facilities', 'facilities/{facility}/branches', 'branches/{branch}/courts',
                'courts/{court}/availability', 'bookings/history', 'bookings',
                'bookings/{booking}/cancel', 'bookings/{booking}/reschedule',
                'bookings/{booking}/payment-status', 'bookings/{booking}/qr-code', 'payments/intents', 'payments/providers', 'payments/{payment}/invoice',
                'payments/mock/xendit/{payment}/complete',
                'rentals', 'rentals/{rental}',
                'player/membership-plans', 'player/memberships', 'player/memberships/{membership}',
                'player/memberships/{membership}/renew', 'player/memberships/{membership}/payment-status',
            ];
            abort_unless(in_array($route, $allowed, true), 403, 'This feature is not available for player accounts yet.');
            if ($request->isMethod('POST') && in_array($route, ['bookings', 'payments/intents', 'player/memberships', 'player/memberships/{membership}/renew'], true)) {
                abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email before booking or paying.');
            }
        }

        return $next($request);
    }
}
