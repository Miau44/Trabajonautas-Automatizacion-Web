<?php

namespace App\Services\Bot;

use App\Models\Announcement;
use App\Models\BotCompany;
use App\Models\BotVacancyPreview;
use App\Models\Location;
use App\Services\ProfessionAssignmentService;
use App\Support\SensitiveDataSanitizer;
use App\Support\TlsVerification;
use Carbon\Carbon;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class EvaluarScraperService
{
    public function __construct(
        private GeminiVacancyAnalyzer $analyzer,
        private BotVacancyNormalizer $normalizer,
        private ProfessionAssignmentService $professionAssignments,
    ) {}

    public function scrapeCompany(BotCompany $company, ?string $startDate = null, ?string $endDate = null, ?string $batchId = null): array
    {
        $from = $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(15)->startOfDay();
        $to = $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay();

        $summary = [
            'company' => $company->name,
            'feed_url' => null,
            'feeds_tested' => [],
            'status' => 'NO_FEED',
            'total_items_feed' => 0,
            'saved' => 0,
            'updated' => 0,
            'shown_in_batch' => 0,
            'skipped_internship' => 0,
            'skipped_out_of_range' => 0,
            'skipped_out_of_range_details' => [],
            'already_published' => 0,
            'already_previewed' => 0,
            'reactivated_deleted' => 0,
            'removed_from_batch' => 0,
            'gemini_enabled' => (bool) config('services.anthropic.api_key'),
            'gemini_model' => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
            'gemini_errors' => 0,
            'gemini_calls' => 0,
            'gemini_skipped_existing_preview' => 0,
            'gemini_prompt_tokens' => 0,
            'gemini_candidates_tokens' => 0,
            'gemini_total_tokens' => 0,
            'gemini_thoughts_tokens' => 0,
            'gemini_retries' => 0,
            'gemini_processed' => 0,
            'gemini_errors_by_type' => [],
            'gemini_quota_exceeded' => false,
            'errors' => [],
        ];
        $batchState = [
            'gemini_quota_exceeded' => false,
        ];

        try {
            $feedUrls = $this->getFeeds($company->evaluar_url);
        } catch (\Throwable $exception) {
            $summary['status'] = 'ERROR';
            $safeException = SensitiveDataSanitizer::exception($exception, 300);
            $this->appendSummaryError($summary, $safeException['message']);
            Log::warning('BOT de empleos no pudo descubrir feeds.', [
                'company_id' => $company->id,
                ...$safeException,
            ]);

            return $summary;
        }

        foreach ($feedUrls as $feedUrl) {
            $feedReport = [
                'url' => SensitiveDataSanitizer::url($feedUrl, 1000),
                'status_code' => null,
                'parsed_xml' => false,
                'items_count' => 0,
                'error' => null,
            ];

            try {
                $response = $this->jobBoardGet($feedUrl, [
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
                ]);

                $feedReport['status_code'] = $response->status();

                if (! $response->successful()) {
                    $summary['feeds_tested'][] = $feedReport;

                    continue;
                }

                $items = $this->parseItemsFromXml($response->body());
                $feedReport['parsed_xml'] = true;
                $feedReport['items_count'] = count($items);
                $summary['feeds_tested'][] = $feedReport;

                if (! $items) {
                    $summary['status'] = 'NO_ITEMS';

                    continue;
                }

                $summary['feed_url'] = $feedUrl;
                $summary['status'] = 'OK';
                $summary['total_items_feed'] = count($items);

                foreach ($items as $item) {
                    try {
                        $result = $this->processItem($company, $feedUrl, $item, $from, $to, $batchId, $batchState);
                        $resultStatus = is_array($result) ? ($result['status'] ?? null) : $result;

                        if ($resultStatus === 'saved') {
                            $summary['saved']++;
                        } elseif ($resultStatus === 'updated') {
                            $summary['updated']++;
                        } elseif ($resultStatus === 'already_previewed') {
                            $summary['already_previewed']++;
                        } elseif ($resultStatus === 'already_published') {
                            $summary['already_published']++;
                        } elseif ($resultStatus === 'reactivated_deleted') {
                            $summary['reactivated_deleted']++;
                        } elseif ($resultStatus === 'internship') {
                            $summary['skipped_internship']++;
                        } elseif ($resultStatus === 'out_of_range') {
                            $summary['skipped_out_of_range']++;
                            if (is_array($result) && isset($result['detail'])) {
                                $summary['skipped_out_of_range_details'][] = $result['detail'];
                            }
                        }

                        if (is_array($result) && ! empty($result['gemini_skipped_existing_preview'])) {
                            $summary['gemini_skipped_existing_preview']++;
                        }

                        if (is_array($result) && ! empty($result['gemini_used'])) {
                            $summary['gemini_calls']++;
                            $summary['gemini_prompt_tokens'] += (int) ($result['gemini_prompt_tokens'] ?? 0);
                            $summary['gemini_candidates_tokens'] += (int) ($result['gemini_candidates_tokens'] ?? 0);
                            $summary['gemini_total_tokens'] += (int) ($result['gemini_total_tokens'] ?? 0);
                            $summary['gemini_thoughts_tokens'] += (int) ($result['gemini_thoughts_tokens'] ?? 0);
                            $summary['gemini_retries'] += max(0, (int) ($result['gemini_attempts'] ?? 1) - 1);
                        }

                        if (is_array($result) && empty($result['gemini_skipped_existing_preview']) && array_key_exists('gemini_success', $result)) {
                            $summary['gemini_processed']++;

                            if (empty($result['gemini_success'])) {
                                $summary['gemini_errors']++;
                                $type = $result['gemini_error_type'] ?? 'unknown';
                                $summary['gemini_errors_by_type'][$type] = ($summary['gemini_errors_by_type'][$type] ?? 0) + 1;
                            }

                            if (($result['gemini_error_type'] ?? null) === 'quota_exceeded') {
                                $summary['gemini_quota_exceeded'] = true;
                            }
                        }
                    } catch (\Throwable $exception) {
                        $safeException = SensitiveDataSanitizer::exception($exception, 300);
                        $this->appendSummaryError($summary, $safeException['message']);
                        Log::warning('BOT de empleos no pudo procesar un item.', [
                            'company_id' => $company->id,
                            'feed_url' => SensitiveDataSanitizer::url($feedUrl, 1000),
                            ...$safeException,
                        ]);
                    }
                }

                break;
            } catch (\Throwable $exception) {
                $safeException = SensitiveDataSanitizer::exception($exception, 300);
                $feedReport['error'] = $safeException['message'];
                $summary['feeds_tested'][] = $feedReport;
                $this->appendSummaryError(
                    $summary,
                    (SensitiveDataSanitizer::url($feedUrl, 1000) ?: 'feed').': '.$safeException['message'],
                );
                $summary['status'] = 'ERROR';
                Log::warning('BOT de empleos no pudo leer un feed.', [
                    'company_id' => $company->id,
                    'feed_url' => SensitiveDataSanitizer::url($feedUrl, 1000),
                    ...$safeException,
                ]);
            }
        }

        if ($batchId) {
            $summary['shown_in_batch'] = BotVacancyPreview::where('bot_company_id', $company->id)
                ->where('scrape_batch_id', $batchId)
                ->whereNull('removed_from_batch_at')
                ->whereIn('status', ['preview', 'edited', 'error'])
                ->count();
        }

        return $summary;
    }

    public function getFeeds(string $url): array
    {
        $url = $this->assertSafeJobBoardUrl($url, resolveDns: false);
        $parts = parse_url($url);
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));

        $origin = rtrim("{$scheme}://{$host}", '/');
        $path = trim($parts['path'] ?? '', '/');

        $feeds = [
            "{$origin}/job-listings/feed/",
        ];

        if ($path !== '') {
            $feeds[] = "{$origin}/{$path}/feed/";
            $feeds[] = "{$origin}/{$path}/job-listings/feed/";
        }

        return array_values(array_unique($feeds));
    }

    public function feedUrls(string $url): array
    {
        return $this->getFeeds($url);
    }

    protected function jobBoardGet(string $url, array $headers): HttpResponse
    {
        $url = $this->assertSafeJobBoardUrl($url);
        $settings = $this->portalSettingsForUrl($url);
        $configKey = $settings['config_key'];
        $maxRedirects = (int) config("services.{$configKey}.max_redirects", 3);
        $redirectOptions = $maxRedirects > 0
            ? [
                'max' => $maxRedirects,
                'strict' => true,
                'referer' => false,
                'protocols' => ['https'],
                'track_redirects' => false,
                'on_redirect' => function (
                    RequestInterface $request,
                    ResponseInterface $response,
                    UriInterface $uri,
                ): void {
                    $this->assertSafeJobBoardUrl((string) $uri);
                },
            ]
            : false;
        $verify = TlsVerification::option(
            $settings['label'],
            (bool) config("services.{$configKey}.verify_ssl", true),
            config("services.{$configKey}.ca_bundle"),
        );
        $requestOptions = [
            'verify' => $verify,
            'allow_redirects' => $redirectOptions,
        ];

        if (
            (bool) config("services.{$configKey}.use_native_ca", false)
            && defined('CURLOPT_SSL_OPTIONS')
            && defined('CURLSSLOPT_NATIVE_CA')
        ) {
            $requestOptions['curl'] = [
                constant('CURLOPT_SSL_OPTIONS') => constant('CURLSSLOPT_NATIVE_CA'),
            ];
        }

        return Http::connectTimeout((int) config("services.{$configKey}.connect_timeout", 10))
            ->timeout((int) config("services.{$configKey}.timeout", 30))
            ->withHeaders($headers)
            ->withOptions($requestOptions)
            ->get($url);
    }

    protected function assertSafeJobBoardUrl(string $url, bool $resolveDns = true): string
    {
        $url = trim($url);

        if (
            $url === ''
            || strlen($url) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new \InvalidArgumentException('La URL del portal de empleos no tiene un formato seguro.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('Las URLs del portal de empleos deben usar HTTPS.');
        }

        if (
            $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ($port !== null && $port !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
        ) {
            throw new \InvalidArgumentException('El host del portal de empleos no tiene un formato permitido.');
        }

        $this->portalSettingsForHost($host);

        if ($resolveDns && ! app()->environment('testing')) {
            $this->assertPublicDns($host);
        }

        return $url;
    }

    private function portalSettingsForUrl(string $url): array
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));

        return $this->portalSettingsForHost($host);
    }

    private function portalSettingsForHost(string $host): array
    {
        $portals = [
            [
                'config_key' => 'evaluar',
                'label' => 'Evaluar',
            ],
            [
                'config_key' => 'etalent',
                'label' => 'E-Talent',
            ],
        ];

        foreach ($portals as $portal) {
            $allowed = collect(config("services.{$portal['config_key']}.allowed_host_suffixes", []))
                ->filter(fn (mixed $suffix): bool => is_string($suffix) && trim($suffix) !== '')
                ->map(fn (string $suffix): string => strtolower(trim($suffix, " .\t\n\r\0\x0B")))
                ->contains(
                    fn (string $suffix): bool => $host === $suffix || str_ends_with($host, '.'.$suffix),
                );

            if ($allowed) {
                return $portal;
            }
        }

        throw new \InvalidArgumentException(
            'El host no pertenece a un portal de empleos permitido.',
        );
    }

    private function assertPublicDns(string $host): void
    {
        $recordTypes = DNS_A | DNS_AAAA;
        $records = @dns_get_record($host, $recordTypes);
        $addresses = [];

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        $ipv4Addresses = @gethostbynamel($host);
        if (is_array($ipv4Addresses)) {
            $addresses = [...$addresses, ...$ipv4Addresses];
        }

        $addresses = array_values(array_unique(array_filter($addresses, 'is_string')));

        if ($addresses === []) {
            throw new \RuntimeException('No se pudo resolver el host del portal de empleos.');
        }

        $publicFlags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, $publicFlags) === false) {
                throw new \RuntimeException('El host del portal de empleos resolvió a una red no permitida.');
            }
        }
    }

    private function parseItemsFromXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="item"]');

        if (! $nodes) {
            return [];
        }

        $items = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $items[] = $node;
            }
        }

        return $items;
    }

    private function processItem(
        BotCompany $company,
        string $feedUrl,
        \DOMElement $item,
        Carbon $from,
        Carbon $to,
        ?string $batchId,
        array &$batchState = []
    ): array {
        $title = $this->cleanText($this->childText($item, 'title'));
        $sourceUrl = $this->cleanText($this->childText($item, 'link'));
        $pubDateOriginal = $this->cleanText($this->childText($item, 'pubDate'));

        if (! $title || ! $sourceUrl) {
            throw new \RuntimeException('RSS item sin titulo o link.');
        }
        $sourceUrl = $this->assertSafeJobBoardUrl($sourceUrl, resolveDns: false);

        $originalDescription = $this->cleanDescription($this->extractDescriptionFromItem($item));

        if ($this->isInternshipOrPractice($title, $originalDescription, $item->textContent)) {
            $this->removeUnpublishedInternshipPreview($sourceUrl);

            return ['status' => 'internship'];
        }

        $publishedAt = $this->parsePublishedAt($pubDateOriginal);
        if ($publishedAt && ! $publishedAt->betweenIncluded($from, $to)) {
            return [
                'status' => 'out_of_range',
                'detail' => [
                    'title' => $title,
                    'pubDate_original' => $pubDateOriginal,
                    'reason' => 'fuera del rango',
                ],
            ];
        }

        $sourceHash = hash('sha256', $sourceUrl);
        $publishedPreview = BotVacancyPreview::where('source_url', $sourceUrl)
            ->where('status', 'published')
            ->first();

        if ($publishedPreview) {
            $locationRefresh = $this->refreshPreviewLocationFromPage(
                $publishedPreview,
                'published_preview_location_backfill',
            );
            $this->markPublishedPreviewAsSeen($sourceUrl, [
                'feed_url' => $feedUrl,
                'pubDate_original' => $pubDateOriginal,
                'published_at' => $publishedAt?->toIso8601String(),
                'date_parse_error' => $pubDateOriginal !== '' && ! $publishedAt,
                'scraped_at' => now()->toIso8601String(),
                'company_url' => $company->evaluar_url,
                'already_published_detected' => true,
                'published_preview_preserved_without_reanalysis' => true,
                'published_location_refresh' => $locationRefresh,
            ]);

            return ['status' => 'already_published'];
        }

        if (! $publishedPreview && $this->existingPublishedAnnouncementExists($sourceUrl, $sourceHash)) {
            return ['status' => 'already_published'];
        }

        $contentHash = $this->contentHash($title, $company->name, $originalDescription);
        $existingPreview = $this->existingReusablePreview($company, $sourceUrl);

        if ($existingPreview) {
            return $this->skipExistingPreview(
                preview: $existingPreview,
                company: $company,
                feedUrl: $feedUrl,
                pubDateOriginal: $pubDateOriginal,
                publishedAt: $publishedAt,
                from: $from,
                to: $to,
                batchId: $batchId,
                contentHash: $contentHash,
                originalDescription: $originalDescription,
                batchState: $batchState,
            );
        }

        $xmlExpiration = $this->expirationFromXml($item);
        $descriptionExpiration = $this->expirationFromDescription($originalDescription);
        $pageMetadata = $this->pageMetadata($sourceUrl);
        $pageExpiration = $pageMetadata['valid_through'];

        $expirationCandidate = $xmlExpiration ?: $descriptionExpiration ?: $pageExpiration;
        $expirationSource = $xmlExpiration
            ? 'xml'
            : ($descriptionExpiration ? 'description' : ($pageExpiration ? 'page' : null));
        $analysisResult = $this->analyzeVacancy(
            title: $title,
            company: $company,
            originalDescription: $originalDescription,
            sourceUrl: $sourceUrl,
            rawData: [
                'source' => $this->sourceTypeForCompany($company),
                'feed_url' => $feedUrl,
                'pubDate_original' => $pubDateOriginal,
                'published_at' => $publishedAt?->toIso8601String(),
                'date_parse_error' => $pubDateOriginal !== '' && ! $publishedAt,
                'scraped_at' => now()->toIso8601String(),
                'company_url' => $company->evaluar_url,
                'content_hash' => $contentHash,
                'scrape_range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'expiration_sources' => [
                    'title' => null,
                    'xml' => $xmlExpiration,
                    'description' => $descriptionExpiration,
                    'page' => $pageExpiration,
                ],
                'page_location' => $pageMetadata['location'],
                'page_department' => $pageMetadata['department'],
                'page_location_country' => $pageMetadata['country'],
                'page_locations' => $pageMetadata['locations'],
                'page_metadata_fetched' => $pageMetadata['fetched'],
            ],
            expirationCandidate: $expirationCandidate,
            expirationSource: $expirationSource,
            batchState: $batchState,
            reanalysisReason: 'initial_analysis',
        );
        $gemini = $analysisResult['gemini'];
        $attributes = $analysisResult['attributes'];

        $status = $this->savePreview($company, $sourceUrl, array_merge($attributes, [
            'bot_company_id' => $company->id,
            'scrape_batch_id' => $batchId,
            'removed_from_batch_at' => null,
        ]));
        $preview = BotVacancyPreview::where('source_url', $sourceUrl)->first();

        $this->professionAssignments->logDecision([
            'source' => $this->sourceTypeForCompany($company),
            'raw_ai_areas' => [],
            'raw_ai_professions' => data_get($attributes, 'raw_data.profesiones_originales', []),
            'resolved_area_ids' => data_get($attributes, 'raw_data.resolved_area_ids', []),
            'professions_before' => [],
            'professions_from_ai' => $attributes['selected_profession_ids'] ?? [],
            'professions_after' => $preview?->selected_profession_ids ?? [],
            'preview_id' => $preview?->id,
            'scrape_batch_id' => $batchId,
            'professions_edited_manually' => $preview ? $this->professionAssignments->professionsEditedManually($preview) : false,
        ]);

        return [
            'status' => $status,
            'gemini_used' => (bool) ($gemini['used'] ?? false),
            'gemini_success' => (bool) ($gemini['success'] ?? false),
            'gemini_error' => $gemini['error'] ?? null,
            'gemini_error_type' => $gemini['error_type'] ?? null,
            'gemini_attempts' => $gemini['gemini_attempts'] ?? null,
            'gemini_prompt_tokens' => $gemini['prompt_tokens'] ?? null,
            'gemini_candidates_tokens' => $gemini['candidates_tokens'] ?? null,
            'gemini_total_tokens' => $gemini['total_tokens'] ?? null,
            'gemini_thoughts_tokens' => $gemini['thoughts_tokens'] ?? null,
        ];
    }

    public function reanalyzePreview(
        BotVacancyPreview $preview,
        string $reason = 'explicit_admin_request',
        ?array &$batchState = null,
    ): array {
        if ($preview->status === 'published') {
            return [
                'status' => 'skipped',
                'reason' => 'published_preview',
                'gemini_used' => false,
            ];
        }

        $preview->loadMissing('botCompany');
        $company = $preview->botCompany;

        if (! $company) {
            return [
                'status' => 'error',
                'reason' => 'missing_company',
                'gemini_used' => false,
                'error' => 'El preview no tiene una empresa BOT asociada.',
            ];
        }

        $rawData = $preview->raw_data ?? [];
        $pageMetadata = $this->pageMetadata($preview->source_url);
        $rawData = array_merge($rawData, [
            'page_location' => $pageMetadata['location'],
            'page_department' => $pageMetadata['department'],
            'page_location_country' => $pageMetadata['country'],
            'page_locations' => $pageMetadata['locations'],
            'page_metadata_fetched' => $pageMetadata['fetched'],
        ]);
        if ($pageMetadata['valid_through']) {
            data_set($rawData, 'expiration_sources.page', $pageMetadata['valid_through']);
        }
        $expirationCandidate = data_get($rawData, 'expiration_sources.xml')
            ?: data_get($rawData, 'expiration_sources.description')
            ?: data_get($rawData, 'expiration_sources.page');
        $expirationSource = data_get($rawData, 'expiration_sources.xml')
            ? 'xml'
            : (data_get($rawData, 'expiration_sources.description')
                ? 'description'
                : (data_get($rawData, 'expiration_sources.page') ? 'page' : null));
        $batchState ??= ['gemini_quota_exceeded' => false];
        $result = $this->analyzeVacancy(
            title: $preview->title,
            company: $company,
            originalDescription: (string) ($preview->original_description ?: $preview->description),
            sourceUrl: $preview->source_url,
            rawData: array_merge($rawData, [
                'reanalyzed_from_status' => $preview->status,
                'reanalyzed_requested_at' => now()->toIso8601String(),
            ]),
            expirationCandidate: is_string($expirationCandidate) ? $expirationCandidate : null,
            expirationSource: $expirationSource,
            batchState: $batchState,
            reanalysisReason: $reason,
        );

        $manualSelection = $this->professionAssignments->professionsEditedManually($preview);
        $attributes = $result['attributes'];

        if ($manualSelection) {
            $attributes = [
                'raw_data' => array_merge($attributes['raw_data'], [
                    'manual_changes_preserved' => true,
                    'manual_changes_preserved_at' => now()->toIso8601String(),
                ]),
            ];
        }

        $preview->update($attributes);

        return [
            'status' => ! empty($result['gemini']['success'])
                ? ($manualSelection ? 'analyzed_manual_preserved' : 'reanalyzed')
                : 'error',
            'reason' => $reason,
            'gemini_used' => (bool) ($result['gemini']['used'] ?? false),
            'gemini_success' => (bool) ($result['gemini']['success'] ?? false),
            'gemini_error' => $result['gemini']['error'] ?? null,
            'gemini_error_type' => $result['gemini']['error_type'] ?? null,
            'gemini_attempts' => $result['gemini']['gemini_attempts'] ?? null,
            'gemini_prompt_tokens' => $result['gemini']['prompt_tokens'] ?? null,
            'gemini_candidates_tokens' => $result['gemini']['candidates_tokens'] ?? null,
            'gemini_total_tokens' => $result['gemini']['total_tokens'] ?? null,
            'gemini_thoughts_tokens' => $result['gemini']['thoughts_tokens'] ?? null,
            'manual_changes_preserved' => $manualSelection,
        ];
    }

    public function refreshPreviewLocationFromPage(
        BotVacancyPreview $preview,
        string $reason = 'explicit_location_refresh',
        bool $onlyWhenMissing = true,
    ): array {
        if ($onlyWhenMissing && ! $this->locationIsMissing($preview->department, $preview->location)) {
            return [
                'status' => 'skipped',
                'reason' => 'location_already_present',
                'updated' => false,
                'department' => $preview->department,
                'location' => $preview->location,
            ];
        }

        $metadata = $this->pageMetadata($preview->source_url);
        $rawData = array_merge($preview->raw_data ?? [], [
            'page_location' => $metadata['location'],
            'page_department' => $metadata['department'],
            'page_location_country' => $metadata['country'],
            'page_locations' => $metadata['locations'],
            'page_metadata_fetched' => $metadata['fetched'],
            'location_refresh_reason' => $reason,
            'location_refresh_attempted_at' => now()->toIso8601String(),
        ]);

        $normalized = $this->normalizer->normalize(
            title: $preview->title,
            description: (string) ($preview->original_description ?: $preview->description),
            analysis: [
                'department' => $preview->department,
                'location' => $preview->location,
                'salary' => $preview->salary,
                'expiration_date' => $preview->expiration_date,
            ],
            rawData: $rawData,
        );
        $missingLocationReason = $this->missingLocationReason(
            sourceUrl: $preview->source_url,
        );

        if ($this->locationIsMissing($normalized['department'], $normalized['location'])) {
            $reviewReasons = $this->withLocationReviewReason(
                (array) data_get($rawData, 'motivos_revision', []),
                $missingLocationReason,
            );
            $manualReviewReasons = $this->withLocationReviewReason(
                (array) data_get($rawData, 'manual_review_reasons', []),
                $missingLocationReason,
            );
            $rawData = array_merge($rawData, [
                'motivos_revision' => $reviewReasons,
                'manual_review_required' => true,
                'manual_review_reasons' => $manualReviewReasons,
                'location_source' => $normalized['location_source'],
                'location_detected_text' => $normalized['location_detected_text'],
                'location_refresh_error' => $missingLocationReason,
            ]);

            if (! in_array($preview->status, ['edited', 'published'], true)) {
                $preview->update([
                    'raw_data' => $rawData,
                    'status' => 'error',
                ]);
            } else {
                $preview->update(['raw_data' => $rawData]);
            }

            return [
                'status' => 'error',
                'reason' => 'location_not_found',
                'updated' => false,
                'department' => $preview->department,
                'location' => $preview->location,
            ];
        }

        $locationIds = $this->locationIdsForDepartments(
            $normalized['departments'] ?? [$normalized['department']],
        );
        $reviewReasons = $this->withoutLocationReviewReason(
            (array) data_get($rawData, 'motivos_revision', []),
            $missingLocationReason,
        );
        $manualReviewReasons = $this->withoutLocationReviewReason(
            (array) data_get($rawData, 'manual_review_reasons', []),
            $missingLocationReason,
        );
        $requiresProfessionReview = (bool) data_get(
            $rawData,
            'profession_resolution.requiere_revision',
            false,
        );
        $geminiSucceeded = (bool) data_get($rawData, 'gemini_success', false);
        $status = $preview->status;

        if (
            $status === 'error'
            && $geminiSucceeded
            && ! $requiresProfessionReview
            && $reviewReasons === []
            && $manualReviewReasons === []
        ) {
            $status = 'preview';
        }

        $rawData = array_merge($rawData, [
            'motivos_revision' => $reviewReasons,
            'manual_review_required' => $requiresProfessionReview
                || $reviewReasons !== []
                || $manualReviewReasons !== [],
            'manual_review_reasons' => $manualReviewReasons,
            'location_source' => $normalized['location_source'],
            'location_detected_text' => $normalized['location_detected_text'],
            'municipality' => $normalized['municipality'],
            'municipalities' => $normalized['municipalities'] ?? [],
            'location_departments' => $normalized['departments'] ?? [$normalized['department']],
            'location_refresh_error' => null,
            'location_refreshed_at' => now()->toIso8601String(),
        ]);

        DB::transaction(function () use (
            $preview,
            $normalized,
            $locationIds,
            $rawData,
            $status,
        ): void {
            $updates = [
                'department' => $normalized['department'],
                'location' => $normalized['location'],
                'selected_location_ids' => $locationIds,
                'raw_data' => $rawData,
                'status' => $status,
            ];

            if (! in_array($preview->status, ['edited', 'published'], true)) {
                $preview->loadMissing('botCompany');
                $companyUrl = $preview->botCompany?->evaluar_url;

                if ($companyUrl) {
                    $updates['description'] = $this->trabajonautasDescription(
                        $normalized['location'],
                        $preview->source_url,
                        $companyUrl,
                        $normalized['municipalities'] ?? [],
                    );
                }
            }

            $preview->update($updates);

            $preview->announcement?->locations()->sync($locationIds);
        });

        return [
            'status' => 'updated',
            'reason' => $reason,
            'updated' => true,
            'department' => $normalized['department'],
            'location' => $normalized['location'],
            'location_ids' => $locationIds,
            'source' => $normalized['location_source'],
        ];
    }

    public function determineAutomaticReanalysisReason(
        BotVacancyPreview $preview,
        string $currentContentHash,
    ): ?string {
        if (
            $preview->status === 'published'
            || $preview->status === 'edited'
            || $this->professionAssignments->professionsEditedManually($preview)
        ) {
            return null;
        }

        $previousHash = data_get($preview->raw_data, 'content_hash');
        if (
            is_string($previousHash)
            && $previousHash !== ''
            && ! hash_equals($previousHash, $currentContentHash)
        ) {
            return 'source_content_changed';
        }

        if (
            data_get($preview->raw_data, 'classifier_version')
            !== (string) config('profession_matching.classifier_version')
        ) {
            return 'classifier_version_changed';
        }

        if (
            data_get($preview->raw_data, 'prompt_version')
            !== (string) config('profession_matching.prompt_version')
        ) {
            return 'prompt_version_changed';
        }

        if (
            $preview->status === 'error'
            && data_get($preview->raw_data, 'catalog_fingerprint')
                !== $this->professionAssignments->catalogFingerprint()
        ) {
            return 'profession_catalog_changed';
        }

        return null;
    }

    private function analyzeVacancy(
        string $title,
        BotCompany $company,
        string $originalDescription,
        string $sourceUrl,
        array $rawData,
        ?string $expirationCandidate,
        ?string $expirationSource,
        array &$batchState,
        string $reanalysisReason,
    ): array {
        $gemini = $this->analyzer->analyzeWithMeta($title, $company, $originalDescription, [
            'skip_due_to_quota' => ! empty($batchState['gemini_quota_exceeded']),
        ]);

        if (($gemini['error_type'] ?? null) === 'quota_exceeded') {
            $batchState['gemini_quota_exceeded'] = true;
        }

        $this->logGeminiDecision($company, $title, 'called', [
            'success' => (bool) ($gemini['success'] ?? false),
            'error_type' => $gemini['error_type'] ?? null,
            'attempts' => $gemini['gemini_attempts'] ?? null,
            'total_tokens' => $gemini['total_tokens'] ?? null,
            'reason' => $reanalysisReason,
        ]);

        $analysis = $gemini['data'];
        $analysisForNormalization = $analysis;
        if ($expirationCandidate) {
            $analysisForNormalization['expiration_date'] = $expirationCandidate;
        }

        $normalizedFields = $this->normalizer->normalize(
            title: $title,
            description: $originalDescription,
            analysis: $analysisForNormalization,
            rawData: $rawData,
        );

        if ($expirationCandidate) {
            $normalizedFields['expiration_source'] = $expirationSource;
            $normalizedFields['expiration_detected_text'] = $expirationCandidate;
        }

        $detectedProfessions = is_array($analysis['profesiones_encontradas'] ?? null)
            ? $analysis['profesiones_encontradas']
            : [];
        $resolution = $this->professionAssignments->resolveDetectedProfessions(
            $detectedProfessions,
            (bool) ($analysis['acepta_carreras_afines'] ?? false),
            $analysis['evidencia_carreras_afines'] ?? null,
            $analysis['area_principal_catalogo'] ?? null,
            (float) ($analysis['confianza_area_principal'] ?? 0),
            $analysis['evidencia_area_principal'] ?? null,
        );
        $locationMissing = $this->locationIsMissing(
            $normalizedFields['department'],
            $normalizedFields['location'],
        );
        $missingLocationReason = $this->missingLocationReason($company, $sourceUrl);
        $reviewReasons = array_values(array_unique(array_filter([
            ($gemini['success'] ?? false)
                ? null
                : ($gemini['error'] ?? 'Claude no pudo analizar la convocatoria.'),
            ...($resolution['motivos_revision'] ?? []),
            $locationMissing ? $missingLocationReason : null,
        ])));
        $requiresReview = ! ($gemini['success'] ?? false)
            || (bool) ($resolution['requiere_revision'] ?? true)
            || $locationMissing;
        $locationIds = $locationMissing
            ? []
            : $this->locationIdsForDepartments(
                $normalizedFields['departments'] ?? [$normalizedFields['department']],
            );
        $expirationSources = array_merge(
            is_array($rawData['expiration_sources'] ?? null) ? $rawData['expiration_sources'] : [],
            ['gemini' => $analysis['expiration_date'] ?? null],
        );
        $analysisTimestamp = $gemini['analyzed_at'] ?? now()->toIso8601String();
        $rawData = array_merge($rawData, [
            'expiration_sources' => $expirationSources,
            'expiration_source' => $normalizedFields['expiration_source'],
            'expiration_detected_text' => $normalizedFields['expiration_detected_text'],
            'location_source' => $normalizedFields['location_source'],
            'location_detected_text' => $normalizedFields['location_detected_text'],
            'salary_source' => $normalizedFields['salary_source'],
            'salary_detected_text' => $normalizedFields['salary_detected_text'],
            'gemini_used' => (bool) ($gemini['used'] ?? false),
            'gemini_success' => (bool) ($gemini['success'] ?? false),
            'gemini_model' => $gemini['model'] ?? config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
            'gemini_error' => $gemini['error'] ?? null,
            'gemini_error_type' => $gemini['error_type'] ?? null,
            'gemini_http_status' => $gemini['http_status'] ?? null,
            'gemini_attempts' => $gemini['gemini_attempts'] ?? null,
            'gemini_analyzed_at' => $analysisTimestamp,
            'gemini_usage_metadata' => $gemini['usage_metadata'] ?? null,
            'gemini_prompt_tokens' => $gemini['prompt_tokens'] ?? null,
            'gemini_candidates_tokens' => $gemini['candidates_tokens'] ?? null,
            'gemini_total_tokens' => $gemini['total_tokens'] ?? null,
            'gemini_thoughts_tokens' => $gemini['thoughts_tokens'] ?? null,
            'gemini_response_metadata' => $gemini['gemini_response_metadata'] ?? null,
            'gemini_finish_reason' => $gemini['gemini_finish_reason'] ?? null,
            'gemini_json_error' => $gemini['gemini_json_error'] ?? null,
            'gemini_validation_errors' => $gemini['gemini_validation_errors'] ?? [],
            'gemini_skipped_due_to_quota' => (bool) ($gemini['gemini_skipped_due_to_quota'] ?? false),
            'description_truncated_for_gemini' => (bool) ($gemini['description_truncated_for_gemini'] ?? false),
            'description_original_length' => $gemini['description_original_length'] ?? null,
            'description_sent_length' => $gemini['description_sent_length'] ?? null,
            'ai_analysis' => $analysis,
            'profession_resolution' => $resolution,
            'profesiones_originales' => $detectedProfessions,
            'profesiones_no_identificadas' => $resolution['profesiones_no_identificadas'] ?? [],
            'profesiones_ambiguas' => $resolution['profesiones_ambiguas'] ?? [],
            'areas_detectadas' => $resolution['areas_detectadas'] ?? [],
            'motivos_revision' => $reviewReasons,
            'resolved_area_ids' => $resolution['area_ids'] ?? [],
            'profession_assignment_source' => 'detected_professions_catalog',
            'profession_assignment_error' => $resolution['error'] ?? null,
            'manual_review_required' => $requiresReview,
            'manual_review_reasons' => $reviewReasons,
            'classifier_version' => $resolution['classifier_version']
                ?? (string) config('profession_matching.classifier_version'),
            'prompt_version' => $gemini['prompt_version']
                ?? (string) config('profession_matching.prompt_version'),
            'catalog_fingerprint' => $resolution['catalog_fingerprint']
                ?? $this->professionAssignments->catalogFingerprint(),
            'analysis_reason' => $reanalysisReason,
            'analysis_at' => $analysisTimestamp,
            'municipality' => $normalizedFields['municipality'],
            'municipalities' => $normalizedFields['municipalities'] ?? [],
            'location_departments' => $normalizedFields['departments']
                ?? [$normalizedFields['department']],
        ]);

        return [
            'gemini' => $gemini,
            'resolution' => $resolution,
            'attributes' => [
                'title' => Str::limit($title, 255, ''),
                'original_description' => $originalDescription,
                'description' => $this->trabajonautasDescription(
                    $normalizedFields['location'],
                    $sourceUrl,
                    $company->evaluar_url,
                    $normalizedFields['municipalities'] ?? [],
                ),
                'area' => ($resolution['area_names'] ?? []) !== []
                    ? implode(', ', $resolution['area_names'])
                    : 'No especificado',
                'professions' => ($resolution['profession_names'] ?? []) !== []
                    ? implode(', ', $resolution['profession_names'])
                    : 'No especificado',
                'department' => $normalizedFields['department'],
                'location' => $normalizedFields['location'],
                'expiration_date' => $normalizedFields['expiration_date'],
                'salary' => $normalizedFields['salary'],
                'raw_data' => $rawData,
                'status' => $requiresReview ? 'error' : 'preview',
                'selected_area_id' => $resolution['selected_area_id'] ?? null,
                'selected_profession_ids' => $resolution['profession_ids'] ?? [],
                'selected_location_ids' => $locationIds,
            ],
        ];
    }

    private function savePreview(BotCompany $company, string $sourceUrl, array $data): string
    {
        $existing = BotVacancyPreview::where('source_url', $sourceUrl)->first();

        if ($existing && $existing->status === 'published') {
            return 'already_published';
        }

        if ($existing && $existing->status === 'edited') {
            $update = [
                'bot_company_id' => $company->id,
                'raw_data' => array_merge($existing->raw_data ?? [], $data['raw_data']),
                'scrape_batch_id' => $data['scrape_batch_id'],
                'removed_from_batch_at' => null,
            ];

            $update['original_description'] = $data['original_description'];

            $existing->update($update);

            return 'already_previewed';
        }

        BotVacancyPreview::updateOrCreate(
            ['source_url' => $sourceUrl],
            $data,
        );

        return $existing ? 'updated' : 'saved';
    }

    private function existingReusablePreview(BotCompany $company, string $sourceUrl): ?BotVacancyPreview
    {
        return BotVacancyPreview::query()
            ->where('bot_company_id', $company->id)
            ->where('source_url', $sourceUrl)
            ->whereIn('status', ['preview', 'edited', 'error'])
            ->first();
    }

    private function skipExistingPreview(
        BotVacancyPreview $preview,
        BotCompany $company,
        string $feedUrl,
        string $pubDateOriginal,
        ?Carbon $publishedAt,
        Carbon $from,
        Carbon $to,
        ?string $batchId,
        string $contentHash,
        string $originalDescription,
        array &$batchState,
    ): array {
        $previousHash = data_get($preview->raw_data, 'content_hash');
        $contentChanged = is_string($previousHash)
            && $previousHash !== ''
            && ! hash_equals($previousHash, $contentHash);
        $manualSelection = $this->professionAssignments->professionsEditedManually($preview);
        $locationRefresh = null;

        if ($preview->status !== 'edited') {
            $locationRefresh = $this->refreshPreviewLocationFromPage(
                $preview,
                'reused_preview_location_backfill',
            );
            $preview->refresh();
        }

        $reanalyzeReason = $this->determineAutomaticReanalysisReason($preview, $contentHash);

        $rawData = array_merge($preview->raw_data ?? [], [
            'source' => $this->sourceTypeForCompany($company),
            'feed_url' => $feedUrl,
            'pubDate_original' => $pubDateOriginal,
            'published_at' => $publishedAt?->toIso8601String(),
            'date_parse_error' => $pubDateOriginal !== '' && ! $publishedAt,
            'scraped_at' => now()->toIso8601String(),
            'company_url' => $company->evaluar_url,
            'scrape_range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'previous_content_hash' => $previousHash,
            'content_hash' => $contentHash,
            'content_hash_changed' => $contentChanged,
            'latest_source_description' => $manualSelection && $contentChanged ? $originalDescription : null,
            'gemini_skipped_existing_preview' => $reanalyzeReason === null,
            'gemini_skip_reason' => $reanalyzeReason === null ? 'unchanged_existing_preview' : null,
            'gemini_skipped_at' => $reanalyzeReason === null ? now()->toIso8601String() : null,
            'reused_preview_location_refresh' => $locationRefresh,
        ]);
        $expirationCandidate = data_get($rawData, 'expiration_sources.xml')
            ?: data_get($rawData, 'expiration_sources.description')
            ?: data_get($rawData, 'expiration_sources.page');
        $expirationSource = data_get($rawData, 'expiration_sources.xml')
            ? 'xml'
            : (data_get($rawData, 'expiration_sources.description')
                ? 'description'
                : (data_get($rawData, 'expiration_sources.page') ? 'page' : null));

        if ($expirationCandidate && $preview->status !== 'edited') {
            $rawData['expiration_source'] = $expirationSource;
            $rawData['expiration_detected_text'] = $expirationCandidate;
        }

        $preview->update([
            'bot_company_id' => $company->id,
            'scrape_batch_id' => $batchId,
            'removed_from_batch_at' => null,
            'original_description' => ! $manualSelection && $contentChanged
                ? $originalDescription
                : $preview->original_description,
            'expiration_date' => $expirationCandidate && $preview->status !== 'edited'
                ? $this->normalizer->expirationForStorage((string) $expirationCandidate)
                : $preview->expiration_date,
            'raw_data' => $rawData,
        ]);
        $preview->refresh();

        if ($reanalyzeReason !== null) {
            $result = $this->reanalyzePreview($preview, $reanalyzeReason, $batchState);

            return [
                'status' => 'updated',
                'gemini_used' => (bool) ($result['gemini_used'] ?? false),
                'gemini_success' => (bool) ($result['gemini_success'] ?? false),
                'gemini_error' => $result['gemini_error'] ?? null,
                'gemini_error_type' => $result['gemini_error_type'] ?? null,
                'gemini_attempts' => $result['gemini_attempts'] ?? null,
                'gemini_prompt_tokens' => $result['gemini_prompt_tokens'] ?? null,
                'gemini_candidates_tokens' => $result['gemini_candidates_tokens'] ?? null,
                'gemini_total_tokens' => $result['gemini_total_tokens'] ?? null,
                'gemini_thoughts_tokens' => $result['gemini_thoughts_tokens'] ?? null,
                'reanalysis_reason' => $reanalyzeReason,
            ];
        }

        $this->logGeminiDecision($company, $preview->title, 'skipped', [
            'skip_reason' => 'unchanged_existing_preview',
            'preview_id' => $preview->id,
            'status' => $preview->status,
        ]);

        return [
            'status' => 'already_previewed',
            'gemini_used' => false,
            'gemini_skipped_existing_preview' => true,
            'gemini_skip_reason' => 'unchanged_existing_preview',
        ];
    }

    private function contentHash(string $title, string $company, string $description): string
    {
        return hash('sha256', implode('|', [
            $this->normalize($title),
            $this->normalize($company),
            $this->normalize($description),
        ]));
    }

    private function logGeminiDecision(BotCompany $company, string $title, string $status, array $context = []): void
    {
        Log::info('BOT Claude analysis decision', SensitiveDataSanitizer::context([
            'company' => $company->name,
            'title' => Str::limit($title, 120, ''),
            'status' => $status,
            ...$context,
        ], 300, 4, 40));
    }

    private function existingPublishedAnnouncementExists(string $sourceUrl, ?string $sourceHash = null, mixed $convocatoriaId = null): bool
    {
        if ($convocatoriaId && Announcement::query()->whereKey($convocatoriaId)->exists()) {
            return true;
        }

        if ($sourceUrl && Announcement::query()->where('source_url', $sourceUrl)->exists()) {
            return true;
        }

        if ($sourceHash && Announcement::query()->where('source_hash', $sourceHash)->exists()) {
            return true;
        }

        return false;
    }

    private function markPublishedPreviewAsSeen(string $sourceUrl, array $rawData): void
    {
        $preview = BotVacancyPreview::where('source_url', $sourceUrl)
            ->where('status', 'published')
            ->first();

        if (! $preview) {
            return;
        }

        $preview->update([
            'raw_data' => array_merge($preview->raw_data ?? [], $rawData),
        ]);
    }

    private function childText(\DOMElement $item, string $localName): string
    {
        foreach ($item->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->localName) === strtolower($localName)) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    private function extractDescriptionFromItem(\DOMElement $item): string
    {
        $encoded = $this->childText($item, 'encoded');

        return $encoded !== '' ? $encoded : $this->childText($item, 'description');
    }

    private function expirationFromXml(\DOMElement $item): ?string
    {
        $fields = [
            'job_expires',
            '_job_expires',
            'expires',
            'expiration_date',
            'dateexpires',
            'validthrough',
            'application_deadline',
            'deadline',
            'closing_date',
            'fecha_vencimiento',
            'vencimiento',
        ];

        foreach ($fields as $field) {
            $value = $this->cleanText($this->childText($item, $field));
            if ($value !== '') {
                return $this->normalizeDateText($value) ?: $value;
            }
        }

        return null;
    }

    private function expirationFromDescription(string $description): ?string
    {
        $keywords = [
            'postular hasta',
            'fecha limite',
            'fecha limite',
            'recepcion de postulaciones',
            'recepcion de postulaciones',
            'fecha de cierre',
            'cierre',
            'hasta el',
            'fecha de vencimiento',
            'vencimiento',
            'limite de postulacion',
        ];

        $normalizedDescription = Str::of($description)->ascii()->toString();

        foreach ($keywords as $keyword) {
            if (preg_match('/'.preg_quote($keyword, '/').'\s*:?\s*(?:el\s*)?([^\n\.]{3,100})/iu', $normalizedDescription, $matches)) {
                $candidate = trim($matches[1]);

                return $this->normalizeDateText($candidate) ?: $candidate;
            }
        }

        return null;
    }

    private function pageMetadata(string $url): array
    {
        $empty = [
            'valid_through' => null,
            'location' => null,
            'department' => null,
            'country' => null,
            'locations' => [],
            'fetched' => false,
        ];

        try {
            $response = $this->jobBoardGet($url, [
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ]);

            if (! $response->successful()) {
                return $empty;
            }

            return [
                ...$this->pageMetadataFromHtml($response->body()),
                'fetched' => true,
            ];
        } catch (\Throwable $exception) {
            Log::warning('BOT de empleos no pudo obtener los metadatos del detalle.', [
                'url' => SensitiveDataSanitizer::url($url, 1000),
                ...SensitiveDataSanitizer::exception($exception, 300),
            ]);

            return $empty;
        }
    }

    private function pageMetadataFromHtml(string $html): array
    {
        $metadata = [
            'valid_through' => null,
            'location' => null,
            'department' => null,
            'country' => null,
            'locations' => [],
        ];
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $metadata;
        }

        foreach ($dom->getElementsByTagName('script') as $script) {
            if (! Str::contains(strtolower($script->getAttribute('type')), 'ld+json')) {
                continue;
            }

            $decoded = json_decode(trim($script->textContent), true);
            $found = $this->findJobPostingMetadata($decoded);

            if ($found) {
                $metadata = array_merge($metadata, $found);

                break;
            }
        }

        if (! $metadata['location']) {
            $xpath = new \DOMXPath($dom);
            $locationNodes = $xpath->query(
                '//a[contains(concat(" ", normalize-space(@class), " "), " google_map_link ")]',
            );
            $locationNode = $locationNodes ? $locationNodes->item(0) : null;
            $location = $locationNode ? $this->cleanText($locationNode->textContent) : '';
            $metadata['location'] = $location !== '' ? $location : null;
            if ($metadata['location']) {
                $metadata['locations'] = [[
                    'location' => $metadata['location'],
                    'department' => null,
                    'country' => null,
                ]];
            }
        }

        if (! $metadata['valid_through']) {
            $metadata['valid_through'] = $this->expirationFromDescription(
                $this->cleanDescription($html),
            );
        }

        if ($metadata['valid_through']) {
            $metadata['valid_through'] = $this->normalizeDateText($metadata['valid_through'])
                ?: $metadata['valid_through'];
        }

        return $metadata;
    }

    private function findJobPostingMetadata(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $type = $value['@type'] ?? null;
        $typeText = is_array($type) ? implode(' ', $type) : (string) $type;

        if (Str::contains(strtolower($typeText), 'jobposting')) {
            $location = $this->locationFromJobPosting($value['jobLocation'] ?? null);

            return [
                'valid_through' => isset($value['validThrough'])
                    ? $this->cleanText((string) $value['validThrough'])
                    : null,
                'location' => $location['location'],
                'department' => $location['department'],
                'country' => $location['country'],
                'locations' => $location['locations'],
            ];
        }

        foreach ($value as $child) {
            $found = $this->findJobPostingMetadata($child);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function locationFromJobPosting(mixed $jobLocation): array
    {
        $result = [
            'location' => null,
            'department' => null,
            'country' => null,
            'locations' => [],
        ];

        if (! is_array($jobLocation)) {
            return $result;
        }

        $locations = array_is_list($jobLocation) ? $jobLocation : [$jobLocation];

        foreach ($locations as $place) {
            if (! is_array($place)) {
                continue;
            }

            $address = $place['address'] ?? null;
            if (is_string($address)) {
                $location = $this->cleanText($address) ?: null;
                if ($location) {
                    $result['locations'][] = [
                        'location' => $location,
                        'department' => null,
                        'country' => null,
                    ];
                }

                continue;
            }
            if (! is_array($address)) {
                continue;
            }

            $country = $address['addressCountry'] ?? null;
            if (is_array($country)) {
                $country = $country['name'] ?? $country['@id'] ?? null;
            }

            $entry = [
                'location' => $this->cleanText((string) ($address['addressLocality'] ?? '')) ?: null,
                'department' => $this->cleanText((string) ($address['addressRegion'] ?? '')) ?: null,
                'country' => $this->cleanText((string) $country) ?: null,
            ];

            if ($entry['location'] || $entry['department']) {
                $result['locations'][] = $entry;
            }
        }

        $result['locations'] = collect($result['locations'])
            ->unique(fn (array $entry): string => implode('|', [
                $this->normalize((string) ($entry['location'] ?? '')),
                $this->normalize((string) ($entry['department'] ?? '')),
            ]))
            ->values()
            ->all();
        $primary = $result['locations'][0] ?? null;

        if ($primary) {
            $result['location'] = $primary['location'];
            $result['department'] = $primary['department'];
            $result['country'] = $primary['country'];
        }

        return $result;
    }

    private function parsePublishedAt(?string $pubDate): ?Carbon
    {
        $pubDate = $this->cleanText((string) $pubDate);

        if ($pubDate === '') {
            return null;
        }

        $parsers = [
            fn () => Carbon::parse($pubDate),
            fn () => Carbon::createFromFormat('D, d M Y H:i:s O', $pubDate),
            fn () => Carbon::createFromFormat('D, d M Y H:i:s T', $pubDate),
        ];

        foreach ($parsers as $parser) {
            try {
                return $parser();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function normalizeDateText(string $value): ?string
    {
        $value = $this->cleanText($value);

        if ($value === '' || $this->normalize($value) === 'no especificado') {
            return null;
        }

        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $value, $matches)) {
            return Carbon::create((int) $matches[1], (int) $matches[2], (int) $matches[3])->toDateString();
        }

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $value, $matches)) {
            $year = (int) $matches[3];
            $year = $year < 100 ? 2000 + $year : $year;

            return Carbon::create($year, (int) $matches[2], (int) $matches[1])->toDateString();
        }

        $ascii = Str::of($value)->ascii()->lower()->toString();
        $months = [
            'enero' => 1,
            'febrero' => 2,
            'marzo' => 3,
            'abril' => 4,
            'mayo' => 5,
            'junio' => 6,
            'julio' => 7,
            'agosto' => 8,
            'septiembre' => 9,
            'setiembre' => 9,
            'octubre' => 10,
            'noviembre' => 11,
            'diciembre' => 12,
        ];

        if (preg_match('/\b(\d{1,2})\s+de\s+([a-z]+)(?:\s+de)?\s+(\d{4})\b/', $ascii, $matches)) {
            $month = $months[$matches[2]] ?? null;
            if ($month) {
                return Carbon::create((int) $matches[3], $month, (int) $matches[1])->toDateString();
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isInternshipOrPractice(string ...$texts): bool
    {
        $title = $this->normalizeInternshipText((string) ($texts[0] ?? ''));
        $text = $this->normalizeInternshipText(implode(' ', $texts));

        // "Practica(s)" por sí sola también aparece en requisitos como
        // "Buenas Prácticas de Almacenamiento"; no prueba que sea una pasantía.
        if (preg_match('/\b(pasantia|pasantias|pasante|pasantes|practicante|practicantes|internship|internships|intern|interns|trainee|trainees)\b/i', $text)) {
            return true;
        }

        if (preg_match(
            '/\bpracticas?\s+(?:(?:pre\s*)?profesionales?|preprofesionales?|laborales?|formativas?|universitarias?|remuneradas?)\b'
            .'|\b(?:programa|vacante|cargo|puesto|oportunidad|convocatoria)\s+(?:de|para)\s+practicas?\b'
            .'|\brealizar(?:a|an)?\s+(?:sus\s+)?practicas?\b/i',
            $text,
        )) {
            return true;
        }

        return preg_match(
            '/^(?:(?:programa|vacante|oportunidad|convocatoria)\s+(?:de|para)\s+)?practicas?\b/i',
            $title,
        ) === 1;
    }

    private function normalizeInternshipText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);

        return trim(preg_replace('/[^a-z0-9]+/i', ' ', $text) ?: '');
    }

    private function removeUnpublishedInternshipPreview(string $sourceUrl): void
    {
        $preview = BotVacancyPreview::where('source_url', $sourceUrl)->first();

        if (! $preview) {
            return;
        }

        if ($preview->status === 'published') {
            $preview->update([
                'raw_data' => array_merge($preview->raw_data ?? [], [
                    'internship_or_practice_detected' => true,
                    'internship_or_practice_detected_at' => now()->toIso8601String(),
                ]),
            ]);

            return;
        }

        $preview->delete();
    }

    private function locationIsMissing(mixed $department, mixed $location): bool
    {
        $normalizedDepartment = $this->normalize((string) $department);
        $normalizedLocation = $this->normalize((string) $location);
        $departments = [
            'la paz',
            'cochabamba',
            'santa cruz',
            'tarija',
            'beni',
            'pando',
            'oruro',
            'potosi',
            'chuquisaca',
        ];

        return $normalizedLocation === ''
            || $normalizedLocation === 'no especificado'
            || ! in_array($normalizedDepartment, $departments, true);
    }

    private function locationIdsForDepartments(array $departments): array
    {
        $locations = Location::query()->get();
        $ids = collect($departments)
            ->map(fn (mixed $department): string => trim((string) $department))
            ->filter()
            ->unique(fn (string $department): string => $this->normalize($department))
            ->map(function (string $department) use ($locations): int {
                $normalizedDepartment = $this->normalize($department);
                $location = $locations->first(
                    fn (Location $candidate): bool => $this->normalize($candidate->location_name)
                        === $normalizedDepartment,
                );

                if (! $location) {
                    $location = Location::query()->firstOrCreate([
                        'location_name' => Str::limit($department, 255, ''),
                    ]);
                    $locations->push($location);
                }

                return (int) $location->id;
            })
            ->values()
            ->all();

        Cache::forget('locations');

        return $ids;
    }

    private function withLocationReviewReason(array $reasons, string $missingLocationReason): array
    {
        return array_values(array_unique([
            ...array_filter(array_map('strval', $reasons)),
            $missingLocationReason,
        ]));
    }

    private function withoutLocationReviewReason(array $reasons, string $missingLocationReason): array
    {
        return array_values(array_filter(
            array_map('strval', $reasons),
            fn (string $reason): bool => $reason !== $missingLocationReason,
        ));
    }

    private function cleanDescription(string $html): string
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/<\s*(br|\/p|\/li)\s*\/?>/i', "\n", $html);
        $text = strip_tags($html);
        $text = $this->cleanText($text);
        $lines = array_filter(array_map('trim', preg_split('/\R+/', $text) ?: []));

        return implode("\n", $lines);
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(
            ["\u{2013}", "\u{2014}", "\u{00A0}", '\\u2013', '\\u2014', '\\xa0'],
            ['-', '-', ' ', '-', '-', ' '],
            $text
        );

        return trim($text);
    }

    private function trabajonautasDescription(
        string $location,
        string $link,
        string $companyUrl,
        array $municipalities = [],
    ): string
    {
        $linkEscaped = e($link);
        $companyUrlEscaped = e($companyUrl);

        $description = implode('', [
            '<p><strong>MODALIDAD DE POSTULACIÓN:</strong></p>',
            '<p>De manera digital utilizando el siguiente enlace de color rojo:</p>',
            '<p><br></p>',
            '<p><a class="text-tbn-primary" href="'.$linkEscaped.'" target="_blank" rel="noopener">'.$linkEscaped.'</a></p>',
            '<p><br></p>',
            '<p>¡Impulsa tu futuro profesional!</p>',
            '<p><br></p>',
            '<p>Esta convocatoria fue verificada por el equipo de <strong style="font-weight: 500;"><b>TRABAJONAUTAS.COM</b></strong> y representa una excelente oportunidad de crecimiento para ti. ¡No la dejes pasar!</p>',
            '<p><br></p>',
            '<p><strong style="font-weight:700;">Fuente:</strong> <a class="text-tbn-primary" href="'.$companyUrlEscaped.'" target="_blank" rel="noopener">'.$companyUrlEscaped.'</a></p>',
            '<p><br></p>',
            '<p>Descarga todos los detalles en el/los archivo(s) adjunto(s):</p>',
        ]);

        return $this->prependWorkplaceToDescription($description, $municipalities);
    }

    private function prependWorkplaceToDescription(string $description, array $municipalities): string
    {
        if (isset($municipalities['municipality'])) {
            $municipalities = [$municipalities];
        }

        $municipalities = collect($municipalities)
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->filter(
                fn (array $entry): bool => trim((string) ($entry['municipality'] ?? '')) !== ''
                    && trim((string) ($entry['department'] ?? '')) !== '',
            )
            ->unique(
                fn (array $entry): string => $this->normalize((string) $entry['municipality'])
                    .'|'.$this->normalize((string) $entry['department']),
            )
            ->values();

        if (
            $municipalities->isEmpty()
            || (
                $municipalities->count() === 1
                && ! empty($municipalities->first()['is_main_city'])
                && ($municipalities->first()['source'] ?? null) !== 'title'
            )
        ) {
            return $description;
        }

        if (Str::contains($this->normalize($description), 'lugar de trabajo')) {
            return $description;
        }

        $workplace = '<p><strong>LUGAR DE TRABAJO:</strong><br> </p>';
        $workplace = $workplace.'<p>'
            .$municipalities
                ->map(
                    fn (array $entry): string => e(trim((string) $entry['municipality']))
                        .', '.e(trim((string) $entry['department'])),
                )
                ->implode('<br>')
            .'<br></p><br>';

        return $workplace.$description;
    }

    private function sourceTypeForCompany(BotCompany $company): string
    {
        $company->loadMissing('source');
        $sourceType = (string) ($company->source?->scraper_type ?? '');

        if (in_array($sourceType, ['evaluar', 'etalent'], true)) {
            return $sourceType;
        }

        return $this->isETalentUrl($company->evaluar_url) ? 'etalent' : 'evaluar';
    }

    private function missingLocationReason(
        ?BotCompany $company = null,
        ?string $sourceUrl = null,
    ): string {
        $isETalent = $company
            ? $this->sourceTypeForCompany($company) === 'etalent'
            : $this->isETalentUrl((string) $sourceUrl);
        $portal = $isETalent ? 'E-Talent' : 'Evaluar';

        return "La ubicación es obligatoria y no pudo obtenerse de la publicación de {$portal}.";
    }

    private function isETalentUrl(string $url): bool
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        $suffixes = collect(config('services.etalent.allowed_host_suffixes', ['e-talent.jobs']))
            ->filter(fn (mixed $suffix): bool => is_string($suffix) && trim($suffix) !== '')
            ->map(fn (string $suffix): string => strtolower(trim($suffix, " .\t\n\r\0\x0B")));

        return $suffixes->contains(
            fn (string $suffix): bool => $host === $suffix || str_ends_with($host, '.'.$suffix),
        );
    }

    private function appendSummaryError(array &$summary, mixed $error): void
    {
        if (count($summary['errors'] ?? []) >= 25) {
            return;
        }

        $safe = SensitiveDataSanitizer::text($error, 500);

        if ($safe !== null && $safe !== '') {
            $summary['errors'][] = $safe;
        }
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
