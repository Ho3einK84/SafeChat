<?php

namespace App\Services;

final class CsrfService
{
    public function token(bool $regenerate = false): string
    {
        if (! session()->isStarted()) {
            session()->start();
        }

        if ($regenerate || ! session()->has($this->sessionKey())) {
            session()->put($this->sessionKey(), bin2hex(random_bytes(32)));
        }

        return (string) session($this->sessionKey());
    }

    public function verify(string $token): bool
    {
        if (! session()->isStarted()) {
            session()->start();
        }

        $stored = session($this->sessionKey());

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        $valid = hash_equals($stored, $token);

        // Rotate token on every valid submission to prevent replay attacks
        if ($valid) {
            session()->put($this->sessionKey(), bin2hex(random_bytes(32)));
        }

        return $valid;
    }

    public function fieldName(): string
    {
        return (string) config('safechat.csrf_token_name', '_csrf');
    }

    private function sessionKey(): string
    {
        return 'safechat_csrf';
    }
}
