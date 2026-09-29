<?php

namespace App\Support;

class SpamClassifier
{
    /**
     * Header signals from the message itself. Cloudflare's own spam flag is read separately.
     *
     * @param  array<string, string>  $headers
     */
    public static function fromHeaders(array $headers): bool
    {
        $flag = strtolower(trim($headers['x-spam-flag'] ?? ''));

        if (in_array($flag, ['yes', 'true', '1'], true)) {
            return true;
        }

        $status = strtolower(trim($headers['x-spam-status'] ?? ''));

        if (str_starts_with($status, 'yes')) {
            return true;
        }

        $score = $headers['x-spam-score'] ?? null;

        if (is_numeric($score) && (float) $score >= 5) {
            return true;
        }

        foreach (['authentication-results', 'arc-authentication-results'] as $name) {
            $value = strtolower($headers[$name] ?? '');

            if ($value !== '' && preg_match('/\bdmarc=fail\b/', $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
