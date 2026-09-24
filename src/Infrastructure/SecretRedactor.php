<?php
declare(strict_types=1);
namespace App\Infrastructure;

final class SecretRedactor
{
    private const SENSITIVE_PATTERNS = [
        '/authorization/i', '/token/i', '/secret/i', '/password/i',
        '/cookie/i', '/api[_-]?key/i', '/credential/i', '/bank/i',
        '/private[_-]?key/i', '/bearer/i', '/session/i', '/verification[_-]?code/i',
        '/reset[_-]?token/i', '/access[_-]?token/i', '/refresh[_-]?token/i',
        '/card[_-]?number/i', '/cvv/i', '/expiry/i', '/ssn/i',
    ];

    private const URL_RE = '~^https?://~';

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $k = (string)$key;
                foreach (self::SENSITIVE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $k)) {
                        $out[$k] = '[REDACTED]';
                        continue 2;
                    }
                }
                $out[$k] = self::redact($item);
            }
            return $out;
        }
        if (is_string($value)) {
            if (preg_match(self::URL_RE, $value)) {
                return preg_replace('/(.{12}).*(.{4})$/', '$1********$2', $value);
            }
            return $value;
        }
        return $value;
    }

    public static function redactJson(string $json): string
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return json_encode(self::redact($data), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
