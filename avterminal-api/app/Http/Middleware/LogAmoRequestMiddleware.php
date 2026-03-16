<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAmoRequestMiddleware
{
    private const LOG_FILE = 'amo_requests.log';
    private const MAX_RAW_BODY_LENGTH = 20000;
    private const REDACTED = '***redacted***';
    private const SENSITIVE_KEYS = [
        'secret',
        'token',
        'authorization',
        'api_key',
        'apikey',
        'password',
        'pass',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();
        $shouldSkip = str_contains($path, 'api/amocrm/logs');

        $start = microtime(true);
        $response = null;
        $exception = null;

        try {
            /** @var Response $response */
            $response = $next($request);
            return $response;
        } catch (\Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            if (!$shouldSkip) {
                $this->writeLog($request, $response, $exception, $start);
            }
        }
    }

    private function writeLog(Request $request, ?Response $response, ?\Throwable $exception, float $start): void
    {
        $rawBody = (string) $request->getContent();
        $payload = $request->all();

        if (empty($payload) && $rawBody !== '') {
            $parsed = [];
            parse_str($rawBody, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                $payload = $parsed;
            }
        }

        $sanitizedQuery = $this->sanitizeArray($request->query());
        $sanitizedPayload = $this->sanitizeArray($payload);
        $sanitizedRawBody = $this->sanitizeRawBody(mb_substr($rawBody, 0, self::MAX_RAW_BODY_LENGTH));

        $record = [
            'at' => now()->toIso8601String(),
            'method' => $request->method(),
            'url' => $request->url(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'user_agent' => $request->userAgent(),
            'query' => $sanitizedQuery,
            'payload' => $sanitizedPayload,
            'raw_body' => $sanitizedRawBody,
            'has_authorization_header' => $request->headers->has('Authorization'),
            'response_status' => $response?->getStatusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'exception' => $exception?->getMessage(),
        ];

        \Illuminate\Support\Facades\File::append(
            storage_path('logs/' . self::LOG_FILE),
            json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL
        );
    }

    private function sanitizeArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value);
                continue;
            }

            if (is_string($value)) {
                $result[$key] = $this->sanitizeInlineString($value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function sanitizeRawBody(string $rawBody): string
    {
        if ($rawBody === '') {
            return '';
        }

        $patterns = [
            '/(?<=^|[&?])(secret|token|authorization|api_key|apikey|password|pass)=([^&]*)/iu',
            '/"(secret|token|authorization|api_key|apikey|password|pass)"\s*:\s*"([^"]*)"/iu',
            '/(secret%3D)([^&]*)/iu',
            '/(token%3D)([^&]*)/iu',
        ];

        foreach ($patterns as $pattern) {
            $rawBody = preg_replace_callback($pattern, function (array $m) {
                $key = $m[1] ?? 'secret';
                if (str_starts_with((string) $m[0], '"')) {
                    return '"' . $key . '":"' . self::REDACTED . '"';
                }
                if (str_ends_with((string) $key, '%3D')) {
                    return $key . self::REDACTED;
                }

                return $key . '=' . self::REDACTED;
            }, $rawBody) ?? $rawBody;
        }

        return $rawBody;
    }

    private function sanitizeInlineString(string $value): string
    {
        // Маскируем секреты в query-параметрах, даже если они вложены в другие поля (например, target URL).
        return preg_replace_callback(
            '/([?&])(secret|token|authorization|api_key|apikey|password|pass)=([^&#]*)/iu',
            fn (array $m) => $m[1] . $m[2] . '=' . self::REDACTED,
            $value
        ) ?? $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
