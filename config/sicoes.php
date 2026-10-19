<?php

$cdpPort = min(65535, max(1, (int) env('SICOES_CDP_PORT', 9222)));

return [
    'refresh_downloads' => filter_var(
        env('SICOES_REFRESH_DOWNLOADS', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'assisted_download' => filter_var(
        env('SICOES_ASSISTED_DOWNLOAD', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'downloads' => [
        'attempts' => min(3, max(1, (int) env('SICOES_DOWNLOAD_ATTEMPTS', 2))),
        'attempt_timeout_ms' => min(180000, max(30000, (int) env('SICOES_DOWNLOAD_ATTEMPT_TIMEOUT_MS', 120000))),
        'replay_timeout_ms' => min(90000, max(10000, (int) env('SICOES_REPLAY_TIMEOUT_MS', 45000))),
    ],

    'process' => [
        'timeout' => max(60, (int) env('SICOES_PROCESS_TIMEOUT', 7200)),
        'idle_timeout' => max(30, (int) env('SICOES_PROCESS_IDLE_TIMEOUT', 240)),
    ],

    'navigation' => [
        'token_timeout_ms' => max(10000, (int) env('SICOES_TOKEN_TIMEOUT_MS', 60000)),
        'table_timeout_ms' => max(10000, (int) env('SICOES_TABLE_TIMEOUT_MS', 60000)),
    ],

    'manual_download' => [
        'timeout_ms' => max(1000, (int) env('SICOES_MANUAL_DOWNLOAD_TIMEOUT_MS', 600000)),
        'directory' => env('SICOES_MANUAL_DOWNLOAD_DIR') ?: null,
    ],

    'node' => [
        'path' => env('SICOES_NODE_PATH') ?: null,
    ],

    'pdf_to_text' => [
        'path' => env('SICOES_PDFTOTEXT_PATH') ?: null,
        'timeout' => max(10, (int) env('SICOES_PDFTOTEXT_TIMEOUT', 60)),
    ],

    'browser' => [
        'path' => env('SICOES_BROWSER_PATH') ?: null,
        'cdp_port' => $cdpPort,
        'cdp_url' => env('SICOES_CDP_URL') ?: "http://127.0.0.1:{$cdpPort}",
    ],

    'ai' => [
        'provider' => strtolower((string) env('SICOES_AI_PROVIDER', 'anthropic')),
        'retries' => min(5, max(1, (int) env('SICOES_AI_RETRIES', 2))),
        'timeout' => min(300, max(5, (int) env('SICOES_AI_TIMEOUT', 120))),
        'max_text_chars' => min(500000, max(1000, (int) env('SICOES_AI_MAX_TEXT_CHARS', 250000))),
        'max_visual_pdf_bytes' => min(
            32 * 1024 * 1024,
            max(1024, (int) env('SICOES_AI_MAX_VISUAL_PDF_BYTES', 20 * 1024 * 1024)),
        ),
    ],

    'location' => [
        'automatic_confidence' => min(1, max(0, (float) env('SICOES_LOCATION_AUTOMATIC_CONFIDENCE', 0.75))),
    ],
];
