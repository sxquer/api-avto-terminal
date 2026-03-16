<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class AmoRequestLogController extends Controller
{
    private const LOG_FILE = 'amo_requests.log';

    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 100);
        $limit = max(1, min($limit, 500));

        $logPath = storage_path('logs/' . self::LOG_FILE);
        if (!File::exists($logPath)) {
            return response('<h2>amo requests log is empty</h2><p>Файл лога пока не создан.</p>', 200)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);

        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        $rows = array_reverse($rows);

        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>amo requests log</title>';
        $html .= '<style>body{font-family:Segoe UI,Tahoma,sans-serif;padding:20px;background:#f6f8fb;color:#1f2937}';
        $html .= '.card{background:#fff;border:1px solid #dbe2ea;border-radius:10px;padding:12px;margin-bottom:12px}';
        $html .= 'pre{white-space:pre-wrap;word-break:break-word;background:#0b1020;color:#d1d5db;padding:10px;border-radius:8px}';
        $html .= '.muted{color:#4b5563}</style></head><body>';
        $html .= '<h1>amo requests log</h1>';
        $html .= '<p class="muted">Показаны последние ' . count($rows) . ' записей.</p>';

        foreach ($rows as $row) {
            $html .= '<div class="card">';
            $html .= '<div><strong>' . e((string) ($row['at'] ?? '-')) . '</strong></div>';
            $html .= '<div>' . e((string) ($row['method'] ?? '-')) . ' ' . e((string) ($row['path'] ?? '-')) . '</div>';
            $html .= '<div class="muted">status=' . e((string) ($row['response_status'] ?? '-')) . ', duration=' . e((string) ($row['duration_ms'] ?? '-')) . 'ms, ip=' . e((string) ($row['ip'] ?? '-')) . '</div>';
            $html .= '<pre>' . e(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
            $html .= '</div>';
        }

        $html .= '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
