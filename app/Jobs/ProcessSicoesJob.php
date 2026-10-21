<?php

namespace App\Jobs;

use App\Models\BotCompany;
use App\Models\SicoesScrapeBatch;
use App\Services\Bot\SicoesDocumentImporterService;
use App\Services\Bot\SicoesRunnerService;
use App\Support\SensitiveDataSanitizer;
use App\Support\SicoesProgressCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessSicoesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const RETRY_WINDOW_HOURS = 8;

    private const PROGRESS_FIELDS = [
        'run_id',
        'status',
        'date',
        'source_type',
        'total',
        'processed',
        'saved',
        'updated',
        'failed',
        'discarded',
        'preclassified_discards',
        'discarded_not_individual_consultant',
        'discarded_company_or_goods',
        'ai_calls',
        'ai_cache_hits',
        'ai_errors',
        'shown_in_batch',
        'last_cuce',
        'last_preview_id',
        'last_document',
        'last_step',
        'started_at',
        'updated_at',
        'finished_at',
        'failed_at',
    ];

    public int $timeout = 7500;

    // Es un respaldo para workers externos. Laravel prioriza retryUntil() para
    // liberaciones por solapamiento y maxExceptions para excepciones reales.
    public int $tries = 135;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public \DateTimeImmutable $retryUntilAt;

    public function __construct(
        public string $date,
        public int $botCompanyId,
        public string $userId,
        public ?string $runId = null,
        public string $sourceType = SicoesScrapeBatch::SOURCE_CONSULTING,
    ) {
        $this->retryUntilAt = new \DateTimeImmutable('+'.self::RETRY_WINDOW_HOURS.' hours');
        $this->onQueue('sicoes');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryUntilAt;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sicoes-scraper'))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(SicoesRunnerService $runner, SicoesDocumentImporterService $importer): array
    {
        $claim = $this->claimBatch();
        $batch = $claim['batch'];

        foreach ($claim['recovered_others'] as $recoveredBatch) {
            $this->syncRecoveredBatchProgress($recoveredBatch);
        }

        if ($claim['recovered_others'] !== []) {
            $this->safeLog('warning', 'SICOES recupero lotes running vencidos antes de reclamar uno nuevo.', [
                'date' => $this->date,
                'run_id' => $this->runId,
                'bot_company_id' => $this->botCompanyId,
                'recovered_batches' => count($claim['recovered_others']),
            ]);
        }

        if ($this->runId && ! $batch) {
            throw new \RuntimeException('El lote SICOES no existe o no pertenece a la empresa indicada.');
        }

        if (! $claim['claimed']) {
            if ($claim['release']) {
                $this->release(60);
                $this->safeLog('warning', 'SICOES libero una entrega porque el lote running aun esta dentro de su lease.', [
                    'date' => $this->date,
                    'run_id' => $this->runId,
                    'bot_company_id' => $this->botCompanyId,
                    'batch_status' => $batch?->status,
                    'attempt' => $this->attempts(),
                ]);

                return [
                    'status' => strtoupper((string) $batch?->status),
                    'runner_status' => 'RELEASED_ACTIVE',
                    'batch_status' => $batch?->status,
                    'released_active_batch' => true,
                ];
            }

            $isTerminal = $batch?->isTerminal() ?? false;
            $runnerStatus = $claim['recovered']
                ? 'RECOVERED_ABANDONED'
                : ($isTerminal ? 'SKIPPED_TERMINAL' : 'SKIPPED_ACTIVE');

            if ($claim['recovered']) {
                try {
                    $this->putProgress([
                        'run_id' => $this->runId,
                        'status' => SicoesScrapeBatch::STATUS_FAILED,
                        'date' => $this->date,
                        'failed' => 1,
                        'last_step' => 'Se recupero como fallido un lote en ejecucion sin bloqueo activo.',
                        'failed_at' => now()->toDateTimeString(),
                    ]);
                } catch (\Throwable $progressException) {
                    $this->safeLog('warning', 'SICOES recupero un lote abandonado, pero no pudo actualizar la cache.', [
                        'date' => $this->date,
                        'run_id' => $this->runId,
                        'bot_company_id' => $this->botCompanyId,
                        'exception_type' => $this->exceptionType($progressException),
                    ]);
                }
            }

            $this->safeLog('warning', 'SICOES omitio una entrega que no pudo reclamar el lote.', [
                'date' => $this->date,
                'bot_company_id' => $this->botCompanyId,
                'run_id' => $this->runId,
                'batch_status' => $batch?->status,
                'reason' => $runnerStatus,
            ]);

            return [
                'status' => strtoupper((string) $batch?->status),
                'runner_status' => $runnerStatus,
                'batch_status' => $batch?->status,
                'skipped_terminal_batch' => $isTerminal,
                'skipped_active_batch' => ! $isTerminal,
                'recovered_abandoned_batch' => $claim['recovered'],
            ];
        }

        $this->putProgress([
            'run_id' => $this->runId,
            'status' => SicoesScrapeBatch::STATUS_RUNNING,
            'date' => $this->date,
            'source_type' => $this->sourceType,
            'total' => 0,
            'processed' => 0,
            'saved' => 0,
            'updated' => 0,
            'failed' => 0,
            'last_step' => 'Proceso iniciado',
            'started_at' => now()->toDateTimeString(),
        ]);

        $import = $this->emptyImportSummary();
        $run = $runner->run(
            $this->date,
            onProgress: function (array $payload): void {
                $this->safeLog('info', 'SICOES progreso.', $this->progressLogContext([
                    'date' => $this->date,
                    'run_id' => $this->runId,
                    ...$payload,
                ]));

                $this->putProgress([
                    'status' => 'running',
                    'total' => (int) ($payload['total'] ?? Cache::get($this->progressKey(), [])['total'] ?? 0),
                    'last_cuce' => $payload['cuce'] ?? null,
                    'last_step' => SensitiveDataSanitizer::text($payload['message'] ?? 'SICOES progreso', 240),
                    'updated_at' => now()->toDateTimeString(),
                ]);
            },
            sourceType: $this->sourceType,
        );
        $runnerStatus = strtoupper((string) ($run['status'] ?? 'UNKNOWN'));

        $import = $importer->importRun(
            run: $run,
            botCompanyId: $this->botCompanyId,
            userId: $this->userId,
            batchId: $this->runId,
            onProgress: function (array $payload) use (&$import): void {
                $this->safeLog('info', 'SICOES documento procesado.', $this->progressLogContext([
                    'date' => $this->date,
                    'run_id' => $this->runId,
                    ...$payload,
                ]));

                $this->putProgress([
                    'status' => 'running',
                    'total' => (int) ($payload['total'] ?? ($import['total_items_feed'] ?? 0)),
                    'processed' => (int) ($payload['processed'] ?? ($import['document_processed'] ?? 0)),
                    'saved' => (int) ($payload['saved'] ?? ($import['saved'] ?? 0)),
                    'updated' => (int) ($payload['updated'] ?? ($import['updated'] ?? 0)),
                    'failed' => (int) ($payload['failed'] ?? (($import['document_errors'] ?? 0) ?: count($import['errors'] ?? []))),
                    'discarded' => (int) ($payload['discarded'] ?? ($import['document_discarded'] ?? 0)),
                    'ai_calls' => (int) ($payload['ai_calls'] ?? ($import['ai_calls'] ?? 0)),
                    'ai_cache_hits' => (int) ($payload['ai_cache_hits'] ?? ($import['ai_cache_hits'] ?? 0)),
                    'ai_errors' => (int) ($payload['ai_errors'] ?? ($import['ai_errors'] ?? 0)),
                    'last_cuce' => $payload['cuce'] ?? null,
                    'last_preview_id' => $payload['preview_id'] ?? null,
                    'last_document' => SensitiveDataSanitizer::basename($payload['document'] ?? null),
                    'last_step' => SensitiveDataSanitizer::text($payload['message'] ?? 'SICOES procesando documento con IA', 240),
                    'updated_at' => now()->toDateTimeString(),
                ]);
            },
        );

        $result = [
            ...$run,
            ...$import,
        ];
        $errorCount = $this->errorCount($result);
        $batchStatus = $this->terminalStatus($runnerStatus, $result, $errorCount);
        $result['runner_status'] = $runnerStatus;
        $result['batch_status'] = $batchStatus;

        $persistedStatus = $this->finishBatch($batchStatus, $run, $result, $errorCount);

        if ($this->runId && $persistedStatus === null) {
            throw new \RuntimeException('El lote SICOES desaparecio antes de guardar su resultado.');
        }

        if ($this->runId && $persistedStatus !== $batchStatus) {
            $result['batch_status'] = $persistedStatus;
            $result['state_write_skipped'] = true;

            $this->safeLog('warning', 'SICOES preservo un estado de lote que cambio durante el procesamiento.', [
                'date' => $this->date,
                'run_id' => $this->runId,
                'bot_company_id' => $this->botCompanyId,
                'calculated_status' => $batchStatus,
                'persisted_status' => $persistedStatus,
            ]);

            return $result;
        }

        $this->safeLog(
            'info',
            $batchStatus === SicoesScrapeBatch::STATUS_COMPLETED
                ? 'SICOES documentos previsualizados correctamente.'
                : 'SICOES finalizo con resultados parciales.',
            [
                'date' => $this->date,
                'run_id' => $this->runId,
                'bot_company_id' => $this->botCompanyId,
                'runner_status' => $runnerStatus,
                'batch_status' => $batchStatus,
                'total' => $result['total_items_feed'] ?? null,
                'saved' => $result['saved'] ?? null,
                'updated' => $result['updated'] ?? null,
                'document_processed' => $result['document_processed'] ?? null,
                'document_errors' => $errorCount,
                'document_discarded' => $result['document_discarded'] ?? null,
                'preclassified_discards' => $result['preclassified_discards'] ?? null,
                'discarded_not_individual_consultant' => $result['discarded_not_individual_consultant'] ?? null,
                'discarded_company_or_goods' => $result['discarded_company_or_goods'] ?? null,
                'ai_provider' => $result['ai_provider'] ?? null,
                'ai_calls' => $result['ai_calls'] ?? null,
                'ai_cache_hits' => $result['ai_cache_hits'] ?? null,
                'ai_errors' => $result['ai_errors'] ?? null,
            ],
        );

        try {
            $this->putProgress([
                'status' => $batchStatus,
                'total' => $result['total_items_feed'] ?? 0,
                'processed' => $result['document_processed'] ?? ($result['total_items_feed'] ?? 0),
                'saved' => $result['saved'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'failed' => $errorCount,
                'discarded' => $result['document_discarded'] ?? 0,
                'preclassified_discards' => $result['preclassified_discards'] ?? 0,
                'discarded_not_individual_consultant' => $result['discarded_not_individual_consultant'] ?? 0,
                'discarded_company_or_goods' => $result['discarded_company_or_goods'] ?? 0,
                'ai_calls' => $result['ai_calls'] ?? 0,
                'ai_cache_hits' => $result['ai_cache_hits'] ?? 0,
                'ai_errors' => $result['ai_errors'] ?? 0,
                'shown_in_batch' => $result['shown_in_batch'] ?? 0,
                'last_step' => ! empty($result['no_results'])
                    ? 'SICOES completado sin convocatorias para la fecha solicitada.'
                    : ($batchStatus === SicoesScrapeBatch::STATUS_COMPLETED
                        ? 'SICOES completado. Revisa las previsualizaciones por documento antes de publicar.'
                        : 'SICOES finalizo parcialmente. Revisa los errores y las previsualizaciones antes de publicar.'
                    ),
                'finished_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $progressException) {
            $this->safeLog('warning', 'SICOES completo el lote, pero no pudo actualizar la cache de progreso.', [
                'date' => $this->date,
                'run_id' => $this->runId,
                'bot_company_id' => $this->botCompanyId,
                'exception_type' => $this->exceptionType($progressException),
            ]);
        }

        return $result;
    }

    public function failed(\Throwable $exception): void
    {
        $exceptionType = $this->exceptionType($exception);
        $markedAsFailed = false;

        try {
            $markedAsFailed = $this->markBatchFailed($exceptionType, 1);
        } catch (\Throwable $databaseException) {
            $this->safeLog('error', 'SICOES no pudo persistir el estado fallido del lote.', [
                'date' => $this->date,
                'run_id' => $this->runId,
                'bot_company_id' => $this->botCompanyId,
                'exception_type' => $this->exceptionType($databaseException),
            ]);

            return;
        }

        if ($this->runId && ! $markedAsFailed) {
            $batch = $this->batch();
            $this->safeLog('warning', 'SICOES job fallo despues de que el lote cambio de estado; se preservo la base y la cache.', [
                'date' => $this->date,
                'bot_company_id' => $this->botCompanyId,
                'run_id' => $this->runId,
                'batch_status' => $batch?->status,
                'exception_type' => $exceptionType,
            ]);

            return;
        }

        try {
            $progressMarkedAsFailed = $this->markProgressFailed();
        } catch (\Throwable $cacheException) {
            $this->safeLog('warning', 'SICOES marco el lote fallido, pero no pudo guardar la cache de progreso.', [
                'date' => $this->date,
                'bot_company_id' => $this->botCompanyId,
                'run_id' => $this->runId,
                'exception_type' => $this->exceptionType($cacheException),
            ]);

            $progressMarkedAsFailed = null;
        }

        if ($progressMarkedAsFailed === false) {
            $this->safeLog('warning', 'SICOES preservo el progreso de una ejecucion distinta al fallar el job.', [
                'date' => $this->date,
                'bot_company_id' => $this->botCompanyId,
                'run_id' => $this->runId,
                'exception_type' => $exceptionType,
            ]);
        }

        $this->safeLog('error', 'SICOES job fallo.', [
            'date' => $this->date,
            'bot_company_id' => $this->botCompanyId,
            'run_id' => $this->runId,
            'exception_type' => $exceptionType,
            'exception_code' => $exception->getCode(),
        ]);
    }

    private function markProgressFailed(): bool
    {
        return SicoesProgressCache::update($this->date, function (array $current): ?array {
            if (! $this->canWriteProgress($current)) {
                return null;
            }

            $progress = array_intersect_key([
                ...$current,
                'run_id' => $this->runId,
                'status' => SicoesScrapeBatch::STATUS_FAILED,
                'failed' => max(1, (int) ($current['failed'] ?? 0) + 1),
                'last_step' => 'El proceso SICOES finalizó con error.',
                'failed_at' => now()->toDateTimeString(),
            ], array_flip(self::PROGRESS_FIELDS));

            return $this->sanitizeProgress($progress);
        }, $this->sourceType);
    }

    private function emptyImportSummary(): array
    {
        return [
            'total_items_feed' => 0,
            'saved' => 0,
            'updated' => 0,
            'shown_in_batch' => 0,
            'already_published' => 0,
            'already_previewed' => 0,
            'reactivated_deleted' => 0,
            'skipped_without_cuce' => 0,
            'ai_provider' => config('sicoes.ai.provider', 'anthropic'),
            'ai_enabled' => (bool) config('services.anthropic.api_key'),
            'ai_model' => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
            'ai_calls' => 0,
            'ai_cache_hits' => 0,
            'ai_errors' => 0,
            'ai_prompt_tokens' => 0,
            'ai_output_tokens' => 0,
            'ai_total_tokens' => 0,
            'anthropic_enabled' => (bool) config('services.anthropic.api_key'),
            'anthropic_calls' => 0,
            'anthropic_errors' => 0,
            'document_processed' => 0,
            'document_errors' => 0,
            'document_discarded' => 0,
            'preclassified_discards' => 0,
            'discarded_not_individual_consultant' => 0,
            'discarded_company_or_goods' => 0,
            'discarded_by_type' => [],
            'discarded_details' => [],
            'preview_ids' => [],
            'announcement_ids' => [],
            'errors' => [],
        ];
    }

    private function putProgress(array $data): bool
    {
        return SicoesProgressCache::update($this->date, function (array $current) use ($data): ?array {
            if (! $this->canWriteProgress($current) || $this->hasTerminalProgress($current)) {
                return null;
            }

            $progress = array_intersect_key([
                ...$current,
                ...$data,
            ], array_flip(self::PROGRESS_FIELDS));

            return $this->sanitizeProgress($progress);
        }, $this->sourceType);
    }

    private function canWriteProgress(array $current): bool
    {
        $currentRunId = $current['run_id'] ?? null;

        if ($currentRunId && ! $this->runId) {
            return false;
        }

        if ($currentRunId && $this->runId && ! hash_equals((string) $currentRunId, (string) $this->runId)) {
            return false;
        }

        return true;
    }

    private function hasTerminalProgress(array $progress): bool
    {
        return in_array(
            strtolower((string) ($progress['status'] ?? '')),
            SicoesScrapeBatch::TERMINAL_STATUSES,
            true,
        );
    }

    private function progressKey(): string
    {
        return $this->progressKeyForDate($this->date);
    }

    private function progressKeyForDate(string $date): string
    {
        return SicoesProgressCache::key($date, $this->sourceType);
    }

    /**
     * @return array{
     *     batch: SicoesScrapeBatch|null,
     *     claimed: bool,
     *     recovered: bool,
     *     release: bool,
     *     recovered_others: array<int, array{run_id: string, date: string}>
     * }
     */
    private function claimBatch(): array
    {
        if (! $this->runId) {
            return [
                'batch' => null,
                'claimed' => true,
                'recovered' => false,
                'release' => false,
                'recovered_others' => [],
            ];
        }

        return DB::transaction(function (): array {
            $company = BotCompany::query()
                ->whereKey($this->botCompanyId)
                ->lockForUpdate()
                ->first(['id']);

            if (! $company) {
                return [
                    'batch' => null,
                    'claimed' => false,
                    'recovered' => false,
                    'release' => false,
                    'recovered_others' => [],
                ];
            }

            $batch = SicoesScrapeBatch::query()
                ->whereKey($this->runId)
                ->where('bot_company_id', $this->botCompanyId)
                ->lockForUpdate()
                ->first();

            if (! $batch) {
                return [
                    'batch' => null,
                    'claimed' => false,
                    'recovered' => false,
                    'release' => false,
                    'recovered_others' => [],
                ];
            }

            if ($batch->status === SicoesScrapeBatch::STATUS_RUNNING) {
                if (! $this->batchLeaseExpired($batch)) {
                    return [
                        'batch' => $batch,
                        'claimed' => false,
                        'recovered' => false,
                        'release' => true,
                        'recovered_others' => [],
                    ];
                }

                $this->failAbandonedBatch($batch);

                return [
                    'batch' => $batch->fresh(),
                    'claimed' => false,
                    'recovered' => true,
                    'release' => false,
                    'recovered_others' => [],
                ];
            }

            if ($batch->status !== SicoesScrapeBatch::STATUS_QUEUED) {
                return [
                    'batch' => $batch,
                    'claimed' => false,
                    'recovered' => false,
                    'release' => false,
                    'recovered_others' => [],
                ];
            }

            $runningBatches = SicoesScrapeBatch::query()
                ->where('bot_company_id', $this->botCompanyId)
                ->whereKeyNot($batch->getKey())
                ->where('status', SicoesScrapeBatch::STATUS_RUNNING)
                ->lockForUpdate()
                ->get();
            $recoveredOthers = [];

            foreach ($runningBatches as $runningBatch) {
                if (! $this->batchLeaseExpired($runningBatch)) {
                    return [
                        'batch' => $batch,
                        'claimed' => false,
                        'recovered' => false,
                        'release' => true,
                        'recovered_others' => $recoveredOthers,
                    ];
                }

                $this->failAbandonedBatch($runningBatch);
                $recoveredOthers[] = [
                    'run_id' => (string) $runningBatch->getKey(),
                    'date' => $runningBatch->requested_date->format('Y-m-d'),
                    'source_type' => (string) ($runningBatch->source_type ?: SicoesScrapeBatch::SOURCE_CONSULTING),
                ];
            }

            $batch->update([
                'status' => SicoesScrapeBatch::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
            ]);

            return [
                'batch' => $batch->fresh(),
                'claimed' => true,
                'recovered' => false,
                'release' => false,
                'recovered_others' => $recoveredOthers,
            ];
        }, 3);
    }

    /**
     * @param  array{run_id: string, date: string, source_type: string}  $recoveredBatch
     */
    private function syncRecoveredBatchProgress(array $recoveredBatch): void
    {
        $runId = $recoveredBatch['run_id'];
        $date = $recoveredBatch['date'];

        try {
            SicoesProgressCache::update($date, function (array $current) use ($runId, $date): ?array {
                if (
                    ! isset($current['run_id'])
                    || ! hash_equals((string) $current['run_id'], $runId)
                ) {
                    return null;
                }

                $progress = array_intersect_key([
                    ...$current,
                    'run_id' => $runId,
                    'status' => SicoesScrapeBatch::STATUS_FAILED,
                    'date' => $date,
                    'failed' => max(1, (int) ($current['failed'] ?? 0) + 1),
                    'last_step' => 'Se recupero como fallido un lote en ejecucion sin bloqueo activo.',
                    'failed_at' => now()->toDateTimeString(),
                ], array_flip(self::PROGRESS_FIELDS));

                return $this->sanitizeProgress($progress);
            }, $recoveredBatch['source_type'] ?? SicoesScrapeBatch::SOURCE_CONSULTING);
        } catch (\Throwable $exception) {
            $this->safeLog('warning', 'SICOES recupero un lote abandonado, pero no pudo sincronizar su cache.', [
                'date' => $date,
                'run_id' => $runId,
                'bot_company_id' => $this->botCompanyId,
                'exception_type' => $this->exceptionType($exception),
            ]);
        }
    }

    private function sanitizeProgress(array $progress): array
    {
        if (array_key_exists('last_document', $progress)) {
            $progress['last_document'] = SensitiveDataSanitizer::basename($progress['last_document']);
        }

        if (array_key_exists('last_step', $progress)) {
            $progress['last_step'] = SensitiveDataSanitizer::text($progress['last_step'], 240);
        }

        return SensitiveDataSanitizer::context($progress, 240, 3, 40);
    }

    private function batchLeaseExpired(SicoesScrapeBatch $batch): bool
    {
        $leaseStartedAt = $batch->started_at ?? $batch->updated_at ?? $batch->created_at;

        return (bool) $leaseStartedAt
            && $leaseStartedAt->copy()->addSeconds($this->timeout + 300)->isPast();
    }

    private function failAbandonedBatch(SicoesScrapeBatch $batch): void
    {
        $summary = is_array($batch->summary) ? $batch->summary : [];
        $summary['batch_status'] = SicoesScrapeBatch::STATUS_FAILED;
        $summary['failure'] = [
            'type' => 'AbandonedRunningBatch',
            'failed_at' => now()->toIso8601String(),
        ];
        $batch->update([
            'status' => SicoesScrapeBatch::STATUS_FAILED,
            'errors_count' => max(1, (int) $batch->errors_count + 1),
            'summary' => $summary,
            'finished_at' => now(),
        ]);
    }

    private function finishBatch(
        string $batchStatus,
        array $run,
        array $result,
        int $errorCount,
    ): ?string {
        if (! $this->runId) {
            return $batchStatus;
        }

        return DB::transaction(function () use ($batchStatus, $run, $result, $errorCount): ?string {
            $batch = SicoesScrapeBatch::query()
                ->whereKey($this->runId)
                ->where('bot_company_id', $this->botCompanyId)
                ->lockForUpdate()
                ->first();

            if (! $batch) {
                return null;
            }

            if ($batch->status !== SicoesScrapeBatch::STATUS_RUNNING) {
                return (string) $batch->status;
            }

            $batch->update([
                'status' => $batchStatus,
                'documents_found' => $this->documentsFound($run, $result),
                'documents_downloaded' => (int) ($result['total_items_feed'] ?? 0),
                'documents_processed' => (int) ($result['document_processed'] ?? 0),
                'previews_count' => (int) ($result['shown_in_batch'] ?? 0),
                'discarded_count' => (int) ($result['document_discarded'] ?? 0),
                'errors_count' => $errorCount,
                'ai_calls' => (int) ($result['ai_calls'] ?? 0),
                'ai_cache_hits' => (int) ($result['ai_cache_hits'] ?? 0),
                'summary' => $this->batchSummary($result),
                'finished_at' => now(),
            ]);

            return $batchStatus;
        }, 3);
    }

    private function batch(): ?SicoesScrapeBatch
    {
        return $this->runId
            ? SicoesScrapeBatch::query()
                ->whereKey($this->runId)
                ->where('bot_company_id', $this->botCompanyId)
                ->first()
            : null;
    }

    private function errorCount(array $result): int
    {
        $errors = $result['errors'] ?? [];

        return max(
            0,
            (int) ($result['document_errors'] ?? 0),
            is_countable($errors) ? count($errors) : 0,
        );
    }

    private function terminalStatus(string $runnerStatus, array $result, int $errorCount): string
    {
        $total = max(0, (int) ($result['total_items_feed'] ?? 0));
        $processed = max(0, (int) ($result['document_processed'] ?? 0));
        $hasValidEmptyResult = (bool) ($result['no_results'] ?? false);
        $hasImportedDocuments = $total > 0;

        return $runnerStatus === 'OK'
            && $errorCount === 0
            && ($hasValidEmptyResult || $hasImportedDocuments)
            && $processed >= $total
                ? SicoesScrapeBatch::STATUS_COMPLETED
                : SicoesScrapeBatch::STATUS_PARTIAL;
    }

    private function batchSummary(array $result): array
    {
        return [
            'runner_status' => Str::limit((string) ($result['runner_status'] ?? 'UNKNOWN'), 32, ''),
            'batch_status' => Str::limit((string) ($result['batch_status'] ?? SicoesScrapeBatch::STATUS_PARTIAL), 32, ''),
            'source_type' => $this->sourceType,
            'no_results' => (bool) ($result['no_results'] ?? false),
            'total_items_feed' => (int) ($result['total_items_feed'] ?? 0),
            'saved' => (int) ($result['saved'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'shown_in_batch' => (int) ($result['shown_in_batch'] ?? 0),
            'already_published' => (int) ($result['already_published'] ?? 0),
            'already_previewed' => (int) ($result['already_previewed'] ?? 0),
            'reactivated_deleted' => (int) ($result['reactivated_deleted'] ?? 0),
            'skipped_without_cuce' => (int) ($result['skipped_without_cuce'] ?? 0),
            'document_processed' => (int) ($result['document_processed'] ?? 0),
            'document_errors' => $this->errorCount($result),
            'document_discarded' => (int) ($result['document_discarded'] ?? 0),
            'preclassified_discards' => (int) ($result['preclassified_discards'] ?? 0),
            'discarded_not_individual_consultant' => (int) ($result['discarded_not_individual_consultant'] ?? 0),
            'discarded_company_or_goods' => (int) ($result['discarded_company_or_goods'] ?? 0),
            'ai_provider' => Str::limit((string) ($result['ai_provider'] ?? ''), 64, ''),
            'ai_enabled' => (bool) ($result['ai_enabled'] ?? $result['anthropic_enabled'] ?? false),
            'ai_model' => Str::limit((string) ($result['ai_model'] ?? ''), 120, ''),
            'ai_calls' => (int) ($result['ai_calls'] ?? 0),
            'ai_cache_hits' => (int) ($result['ai_cache_hits'] ?? 0),
            'ai_errors' => (int) ($result['ai_errors'] ?? 0),
            'ai_prompt_tokens' => (int) ($result['ai_prompt_tokens'] ?? 0),
            'ai_output_tokens' => (int) ($result['ai_output_tokens'] ?? 0),
            'ai_total_tokens' => (int) ($result['ai_total_tokens'] ?? 0),
        ];
    }

    private function markBatchFailed(string $exceptionType, int $errorCount): bool
    {
        if (! $this->runId) {
            return true;
        }

        return DB::transaction(function () use ($exceptionType, $errorCount): bool {
            $batch = SicoesScrapeBatch::query()
                ->whereKey($this->runId)
                ->where('bot_company_id', $this->botCompanyId)
                ->whereIn('status', [
                    SicoesScrapeBatch::STATUS_QUEUED,
                    SicoesScrapeBatch::STATUS_RUNNING,
                ])
                ->lockForUpdate()
                ->first();

            if (! $batch) {
                return false;
            }

            $summary = is_array($batch->summary) ? $batch->summary : [];
            $summary['batch_status'] = SicoesScrapeBatch::STATUS_FAILED;
            $summary['failure'] = [
                'type' => Str::limit($exceptionType, 160, ''),
                'failed_at' => now()->toIso8601String(),
            ];

            $batch->update([
                'status' => SicoesScrapeBatch::STATUS_FAILED,
                'errors_count' => max(1, (int) $batch->errors_count + 1, $errorCount),
                'summary' => $summary,
                'finished_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function exceptionType(\Throwable $exception): string
    {
        $type = preg_replace('/[^A-Za-z0-9_]/', '', class_basename($exception));

        return $type !== '' ? Str::limit($type, 160, '') : 'Throwable';
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log(
                $level,
                $message,
                SensitiveDataSanitizer::context($context, 240, 3, 40),
            );
        } catch (\Throwable) {
            // El diagnóstico nunca debe revertir un estado de lote ya persistido.
        }
    }

    private function documentsFound(array $run, array $result): int
    {
        $importedDocuments = max(0, (int) ($result['total_items_feed'] ?? 0));
        if ($importedDocuments > 0 || (bool) ($result['no_results'] ?? false)) {
            return $importedDocuments;
        }

        return max(0, (int) ($run['sicoes_items'] ?? 0));
    }

    private function progressLogContext(array $payload): array
    {
        $context = array_intersect_key($payload, array_flip([
            'date',
            'run_id',
            'status',
            'step',
            'message',
            'total',
            'processed',
            'saved',
            'updated',
            'failed',
            'discarded',
            'ai_calls',
            'ai_cache_hits',
            'ai_errors',
            'cuce',
            'preview_id',
            'document',
        ]));

        if (array_key_exists('document', $context)) {
            $context['document'] = SensitiveDataSanitizer::basename($context['document']);
        }

        return SensitiveDataSanitizer::context($context, 240, 3, 30);
    }
}
