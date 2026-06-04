<?php

namespace App\Support;

final class InputSanitizer
{
    public static function sanitize(string $input, int $maxLength = 0): string
    {
        $input = trim($input);

        if ($maxLength > 0 && mb_strlen($input) > $maxLength) {
            $input = mb_substr($input, 0, $maxLength);
        }

        $input = str_replace("\0", '', $input);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $input);
    }
}
