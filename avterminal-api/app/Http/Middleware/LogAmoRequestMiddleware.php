<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAmoRequestMiddleware
{
    private const LOG_FILE = 'amo_requests.log';
    private const MAX_RAW_BODY_LENGTH = 20000;

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

        $record = [
            'at' => now()->toIso8601String(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'user_agent' => $request->userAgent(),
            'query' => $request->query(),
            'payload' => $payload,
            'raw_body' => mb_substr($rawBody, 0, self::MAX_RAW_BODY_LENGTH),
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
}
