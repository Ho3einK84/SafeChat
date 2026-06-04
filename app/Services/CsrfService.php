<?php

namespace App\Services;

final class CsrfService
{
    public function token(bool $regenerate = false): string
    {
        if (! session()->isStarted()) {
            session()->start();
        }

        if ($regenerate) {
            session()->forget($this->previousSessionKey());
            session()->put($this->sessionKey(), bin2hex(random_bytes(32)));
        } elseif (! session()->has($this->sessionKey())) {
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
        $previous = session($this->previousSessionKey());

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        if (hash_equals($stored, $token)) {
            session()->put($this->previousSessionKey(), $stored);
            session()->put($this->sessionKey(), bin2hex(random_bytes(32)));
            return true;
        }

        if (is_string($previous) && $previous !== '' && hash_equals($previous, $token)) {
            return true;
        }

        return false;
    }

    public function fieldName(): string
    {
        return (string) config('safechat.csrf_token_name', '_csrf');
    }

    private function sessionKey(): string
    {
        return 'safechat_csrf';
    }

    private function previousSessionKey(): string
    {
        return 'safechat_csrf_prev';
    }
}
