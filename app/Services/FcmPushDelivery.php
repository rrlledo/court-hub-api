<?php

namespace App\Services;

use App\Exceptions\InvalidPushDeviceToken;
use App\Models\PushDevice;
use App\Models\UserNotification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmPushDelivery
{
    public function send(PushDevice $device, UserNotification $notification): void
    {
        $credentials = $this->credentials();
        $projectId = config('services.firebase.project_id') ?: ($credentials['project_id'] ?? null);
        if (! is_string($projectId) || $projectId === '') {
            throw new RuntimeException('Firebase project ID is not configured.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->withToken($this->accessToken($credentials))
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $notification->title, 'body' => $notification->message],
                    'data' => $this->data($notification),
                    'android' => ['priority' => 'high'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        if ($this->hasInvalidToken($response)) {
            throw new InvalidPushDeviceToken('The FCM registration token is no longer valid.');
        }

        $response->throw();
    }

    /** @return array<string, mixed> */
    private function credentials(): array
    {
        $path = config('services.firebase.service_account_path');
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw new RuntimeException('Firebase service-account file is not configured or readable.');
        }

        $credentials = json_decode((string) file_get_contents($path), true);
        if (! is_array($credentials)
            || ! isset($credentials['client_email'], $credentials['private_key'])
            || ! is_string($credentials['client_email'])
            || ! is_string($credentials['private_key'])) {
            throw new RuntimeException('Firebase service-account file is invalid.');
        }

        return $credentials;
    }

    /** @param array<string, mixed> $credentials */
    private function accessToken(array $credentials): string
    {
        return Cache::remember('firebase:fcm-access-token', now()->addMinutes(50), function () use ($credentials): string {
            $issuedAt = now()->timestamp;
            $assertion = $this->signedAssertion($credentials, $issuedAt);
            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
            $response->throw();
            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Firebase OAuth response did not include an access token.');
            }

            return $token;
        });
    }

    /** @param array<string, mixed> $credentials */
    private function signedAssertion(array $credentials, int $issuedAt): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = "{$header}.{$payload}";
        if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Firebase service-account assertion could not be signed.');
        }

        return "{$unsigned}.".$this->base64Url($signature);
    }

    /** @return array<string, string> */
    private function data(UserNotification $notification): array
    {
        $data = ['notification_id' => (string) $notification->id, 'type' => $notification->type];
        foreach ($notification->data ?? [] as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $data[$key] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    private function hasInvalidToken(Response $response): bool
    {
        $body = json_encode($response->json(), JSON_THROW_ON_ERROR) ?: '';

        return str_contains($body, 'UNREGISTERED');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
