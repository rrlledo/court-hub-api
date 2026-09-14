<?php

namespace App\Services;

class TotpService
{
    public function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($index = 0; $index < 32; $index++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    public function verify(string $secret, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestamp = intdiv(time(), 30);
        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals($this->code($secret, $timestamp + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $secret, string $email): string
    {
        return 'otpauth://totp/'.rawurlencode('Court Hub:'.$email).'?secret='.$secret.'&issuer='.rawurlencode('Court Hub').'&algorithm=SHA1&digits=6&period=30';
    }

    private function code(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $this->decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (str_split(strtoupper($secret)) as $character) {
            $value = strpos($alphabet, $character);
            if ($value === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $output;
    }
}
