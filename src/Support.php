<?php

declare(strict_types=1);

namespace BKB;

final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class Request
{
    /**
     * @return array<string, mixed>
     */
    public static function json(int $maxBytes = 8_388_608): array
    {
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > $maxBytes) {
            throw new HttpException(413, 'PAYLOAD_TOO_LARGE', 'Die Anfrage ist zu groß.');
        }

        $raw = file_get_contents('php://input');
        if ($raw === false) {
            throw new HttpException(400, 'INVALID_BODY', 'Der Anfrageinhalt konnte nicht gelesen werden.');
        }

        if (strlen($raw) > $maxBytes) {
            throw new HttpException(413, 'PAYLOAD_TOO_LARGE', 'Die Anfrage ist zu groß.');
        }

        if (trim($raw) === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new HttpException(
                400,
                'INVALID_JSON',
                'Die Anfrage enthält kein gültiges JSON.',
                ['jsonError' => $exception->getMessage()]
            );
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new HttpException(400, 'INVALID_JSON_OBJECT', 'Ein JSON-Objekt wird erwartet.');
        }

        return $data;
    }

    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        $normalized = '/' . ltrim($path, '/');
        return $normalized !== '/' ? rtrim($normalized, '/') : '/';
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function header(string $name): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey])) {
            return trim((string) $_SERVER[$serverKey]);
        }

        return null;
    }
}

final class Response
{
    /**
     * @param mixed $data
     */
    public static function success($data = null, int $status = 200): never
    {
        self::json(
            [
                'ok' => true,
                'data' => $data,
            ],
            $status
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(
        int $status,
        string $code,
        string $message,
        array $details = []
    ): never {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        self::json(
            [
                'ok' => false,
                'error' => $error,
            ],
            $status
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        try {
            echo json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (\JsonException) {
            http_response_code(500);
            echo '{"ok":false,"error":{"code":"ENCODING_FAILED","message":"Die Antwort konnte nicht erzeugt werden."}}';
        }

        exit;
    }
}

final class Text
{
    public static function requiredString(
        mixed $value,
        string $field,
        int $maxLength = 255,
        int $minLength = 1
    ): string {
        if (!is_string($value)) {
            throw new HttpException(422, 'INVALID_FIELD', "Das Feld „{$field}“ muss Text enthalten.");
        }

        $value = trim($value);
        $length = self::length($value);
        if ($length < $minLength || $length > $maxLength) {
            throw new HttpException(
                422,
                'INVALID_FIELD_LENGTH',
                "Das Feld „{$field}“ muss zwischen {$minLength} und {$maxLength} Zeichen lang sein."
            );
        }

        return $value;
    }

    public static function optionalString(mixed $value, string $field, int $maxLength = 255): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredString($value, $field, $maxLength);
    }

    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public static function slug(string $title, int $fallbackId): string
    {
        $value = strtr(
            $title,
            [
                'Ä' => 'Ae',
                'Ö' => 'Oe',
                'Ü' => 'Ue',
                'ä' => 'ae',
                'ö' => 'oe',
                'ü' => 'ue',
                'ß' => 'ss',
            ]
        );

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? substr($value, 0, 120) : 'seite-' . $fallbackId;
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
    }
}
