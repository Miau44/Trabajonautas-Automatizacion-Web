<?php

return [

    'site_publishing' => [
        'user_id' => env('SITE_PUBLISH_USER_ID'),
        'announcement_type_slug' => env('SITE_PUBLISH_ANNOUNCEMENT_TYPE_SLUG'),
        'files' => [
            'host' => env('SITE_FILES_HOST'),
            'port' => (int) env('SITE_FILES_PORT', 21),
            'username' => env('SITE_FILES_USERNAME'),
            'password' => env('SITE_FILES_PASSWORD'),
            'root' => env('SITE_FILES_ROOT'),
            'timeout' => (int) env('SITE_FILES_TIMEOUT', 20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    // Servidor de Google para Inicio de Sesión
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI')
    ],
    // ID de Google Analytics
    'google_analytics' => [
        'id' => env('GOOGLE_ANALYTICS_ID')
    ],
    // VAPID Clave Pública de Firebase para notificaciones
    'firebase' => [
        'vapid_key' => env('FIREBASE_VAPID_KEY'),
        'web' => [
            'apiKey' => env('FIREBASE_API_KEY'),
            'authDomain' => env('FIREBASE_AUTH_DOMAIN'),
            'projectId' => env('FIREBASE_PROJECT_ID'),
            'storageBucket' => env('FIREBASE_STORAGE_BUCKET'),
            'messagingSenderId' => env('FIREBASE_MESSAGING_SENDER_ID'),
            'appId' => env('FIREBASE_APP_ID'),
            'measurementId' => env('FIREBASE_MEASUREMENT_ID'),
        ],
    ],

    'evaluar' => [
        'verify_ssl' => filter_var(
            env('EVALUAR_VERIFY_SSL', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'ca_bundle' => env('EVALUAR_CA_BUNDLE') ?: null,
        'timeout' => max(1, min(120, (int) env('EVALUAR_TIMEOUT', 30))),
        'connect_timeout' => max(1, min(60, (int) env('EVALUAR_CONNECT_TIMEOUT', 10))),
        'max_redirects' => max(0, min(5, (int) env('EVALUAR_MAX_REDIRECTS', 3))),
        'allowed_host_suffixes' => [
            'evaluar.com',
            'evaluarjobs.com',
        ],
    ],

    'etalent' => [
        'verify_ssl' => filter_var(
            env('ETALENT_VERIFY_SSL', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'ca_bundle' => env('ETALENT_CA_BUNDLE')
            ?: env('EVALUAR_CA_BUNDLE')
            ?: null,
        'timeout' => max(1, min(120, (int) env('ETALENT_TIMEOUT', 30))),
        'connect_timeout' => max(1, min(60, (int) env('ETALENT_CONNECT_TIMEOUT', 10))),
        'max_redirects' => max(0, min(5, (int) env('ETALENT_MAX_REDIRECTS', 3))),
        'feed_per_page' => max(10, min(100, (int) env('ETALENT_FEED_PER_PAGE', 100))),
        'use_native_ca' => filter_var(
            env('ETALENT_USE_NATIVE_CA', PHP_OS_FAMILY === 'Windows'),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'allowed_host_suffixes' => [
            'e-talent.jobs',
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001',
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'max_tokens' => max(1, (int) env('ANTHROPIC_MAX_TOKENS', 4000)),
        'timeout' => max(1, min(300, (int) env('ANTHROPIC_TIMEOUT', 60))),
        'connect_timeout' => max(1, min(60, (int) env('ANTHROPIC_CONNECT_TIMEOUT', 10))),
        'max_description_chars' => max(1000, (int) env('ANTHROPIC_MAX_DESCRIPTION_CHARS', 6000)),
        'debug_ssl' => filter_var(env('ANTHROPIC_DEBUG_SSL', false), FILTER_VALIDATE_BOOLEAN),
        'verify_ssl' => filter_var(
            env('ANTHROPIC_VERIFY_SSL', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'ca_bundle' => env('ANTHROPIC_CA_BUNDLE') ?: null,
    ],
];
