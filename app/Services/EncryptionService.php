<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

final class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;
    private const KEY_LEN = 32;

    /**
     * Encrypt plaintext with AES-256-GCM (iv + tag + ciphertext, base64-encoded).
     */
    public function encrypt(string $plaintext): string
    {
        $key = $this->deriveKey();
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);

        if ($encrypted === false) {
            Log::channel('safechat')->error('Encryption failed');
            throw new RuntimeException('Encryption failed');
        }

        return base64_encode($iv.$tag.$encrypted);
    }

    /**
     * Decrypt AES-256-GCM payload produced by encrypt().
     * Returns '[decryption error]' on failure rather than throwing,
     * so callers can handle gracefully.
     */
    public function decrypt(string $ciphertext): string
    {
        try {
            $key = $this->deriveKey();
            $raw = base64_decode($ciphertext, true);
            $minLen = self::IV_LEN + self::TAG_LEN + 1;

            if ($raw === false || strlen($raw) < $minLen) {
                Log::channel('safechat')->warning('Decryption failed: invalid ciphertext length');

                return '[decryption error]';
            }

            $iv = substr($raw, 0, self::IV_LEN);
            $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
            $encryptedData = substr($raw, self::IV_LEN + self::TAG_LEN);

            $decrypted = openssl_decrypt($encryptedData, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

            if ($decrypted === false) {
                Log::channel('safechat')->warning('Decryption failed: authentication tag mismatch');

                return '[decryption error]';
            }

            return $decrypted;
        } catch (\Throwable $e) {
            Log::channel('safechat')->warning('Decryption exception', ['exception' => $e::class]);

            return '[decryption error]';
        }
    }

    /**
     * Validate an RSA public key supplied in JWK format.
     */
    public function validateRsaPublicKey(string $jwkJson): bool
    {
        $decoded = json_decode($jwkJson, true);

        if (! is_array($decoded)) {
            return false;
        }

        if (($decoded['kty'] ?? '') !== 'RSA') {
            return false;
        }

        $n = $decoded['n'] ?? '';
        $e = $decoded['e'] ?? '';

        if ($n === '' || $e === '') {
            return false;
        }

        $nDecoded = $this->base64UrlDecode($n);

        // Require at least 2048-bit key (256 bytes)
        return $nDecoded !== false && strlen($nDecoded) >= 256;
    }

    public function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        if (! is_string($hash) || $hash === '') {
            Log::channel('safechat')->error('Password hashing failed');
            throw new RuntimeException('Password hashing failed');
        }

        return $hash;
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Derive a 256-bit key from ENCRYPTION_KEY using HKDF-SHA256.
     * This is cryptographically sound key derivation (replaces bare SHA256).
     */
    private function deriveKey(): string
    {
        $rawKey = (string) config('safechat.encryption_key');

        if ($rawKey === '') {
            throw new RuntimeException('ENCRYPTION_KEY is not configured');
        }

        // HKDF with SHA-256: extract + expand with application-specific info
        return hash_hkdf('sha256', $rawKey, self::KEY_LEN, 'safechat-public-messages', '');
    }

    private function base64UrlDecode(string $data): string|false
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($data, true);
    }
}
