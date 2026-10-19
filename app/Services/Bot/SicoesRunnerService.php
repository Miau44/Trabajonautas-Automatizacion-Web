<?php

namespace App\Services\Bot;

use App\Support\SensitiveDataSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class SicoesRunnerService
{
    public function run(
        string $date,
        ?callable $onItem = null,
        ?callable $onProgress = null,
        string $sourceType = 'consulting_services',
    ): array {
        $displayDate = $this->displayDate($date);
        $dateSlug = str_replace('/', '-', $displayDate);
        $slug = $sourceType === 'personnel_requirements' ? $dateSlug.'-personal' : $dateSlug;
        $basePath = storage_path('app/bot/sicoes-scraper/Sicoes');
        $jsonPath = $basePath.DIRECTORY_SEPARATOR.'fichas-finales'.DIRECTORY_SEPARATOR.$slug.'.json';

        if (! is_file($basePath.DIRECTORY_SEPARATOR.'sicoes.js')) {
            throw new \RuntimeException("No se encontro sicoes.js en {$basePath}.");
        }

        $this->makeOutputWritable($basePath);
        @unlink($jsonPath);

        if ((bool) config('sicoes.refresh_downloads', false)) {
            $this->clearDownloadCacheForDate($basePath, $slug);
        }

        $assistedDownload = (bool) config('sicoes.assisted_download', false);
        $mode = $assistedDownload
            ? 'full-assisted'
            : 'full';

        $process = new Process([
            $this->nodeBinary(),
            'sicoes.js',
            "--mode={$mode}",
            "--fecha={$displayDate}",
            "--source={$sourceType}",
        ], $basePath);
        $process->setEnv($this->nodeEnvironment($basePath));
        $process->setTimeout((int) config('sicoes.process.timeout', 7200));
        $process->setIdleTimeout($this->processIdleTimeout($assistedDownload));

        $output = '';
        $lineBuffers = [
            Process::OUT => '',
            Process::ERR => '',
        ];
        $streamedItems = 0;

        try {
            $process->run(function (string $type, string $buffer) use (&$output, &$lineBuffers, &$streamedItems, $onItem, $onProgress): void {
                $stream = $type === Process::ERR ? Process::ERR : Process::OUT;
                $lineBuffers[$stream] .= $buffer;
                $lines = preg_split('/\r\n|\n|\r/', $lineBuffers[$stream]);
                $lineBuffers[$stream] = array_pop($lines) ?? '';

                foreach ($lines as $line) {
                    $isItem = $this->handleStreamLine($line, $onItem, $onProgress);

                    if ($isItem) {
                        $streamedItems++;
                    } else {
                        $this->appendDiagnosticLine($output, $line);
                    }
                }
            });

            foreach ($lineBuffers as $lineBuffer) {
                if ($lineBuffer === '') {
                    continue;
                }

                $isItem = $this->handleStreamLine($lineBuffer, $onItem, $onProgress);
                if ($isItem) {
                    $streamedItems++;
                } else {
                    $this->appendDiagnosticLine($output, $lineBuffer);
                }
            }
        } catch (\Throwable $exception) {
            $this->appendProcessDiagnostics($output, $process->getOutput(), $process->getErrorOutput());
            $failure = $this->failureLine($output);
            $message = SensitiveDataSanitizer::text($exception->getMessage(), 500) ?: 'Error al ejecutar el proceso SICOES.';

            throw new \RuntimeException(Str::limit(($failure ? $failure.PHP_EOL : '').$message.PHP_EOL.$output, 3000, ''));
        }

        if (! $process->isSuccessful()) {
            $this->appendProcessDiagnostics($output, $process->getOutput(), $process->getErrorOutput());
            $failure = $this->failureLine($output);

            if ($failure) {
                $output = $failure.PHP_EOL.$output;
            }

            if ($streamedItems > 0) {
                return $this->streamedResult($displayDate, $slug, $jsonPath, $streamedItems, $output, 'PARTIAL');
            }

            throw new \RuntimeException(Str::limit($output, 3000, ''));
        }

        if (! is_file($jsonPath)) {
            if ($streamedItems > 0) {
                return $this->streamedResult($displayDate, $slug, $jsonPath, $streamedItems, $output, 'STREAMED');
            }

            if ($this->isEmptyRun($basePath, $slug, $output)) {
                return [
                    'status' => 'OK',
                    'date' => $displayDate,
                    'slug' => $slug,
                    'json_path' => null,
                    'sicoes_items' => 0,
                    'no_results' => true,
                    'runner_output' => SensitiveDataSanitizer::text($output, 3000),
                ];
            }

            throw new \RuntimeException("SICOES no genero JSON final valido: {$jsonPath}");
        }

        $items = $this->validatedFinalItems(
            $jsonPath,
            allowEmpty: str_contains($output, '[SICOES_EMPTY] '),
        );

        return [
            'status' => str_contains($output, '[SICOES_PARTIAL] ') ? 'PARTIAL' : 'OK',
            'date' => $displayDate,
            'slug' => $slug,
            'source_type' => $sourceType,
            'json_path' => $jsonPath,
            'sicoes_items' => count($items),
            'no_results' => count($items) === 0,
            'runner_output' => SensitiveDataSanitizer::text($output, 3000),
        ];
    }

    private function streamedResult(string $displayDate, string $slug, string $jsonPath, int $streamedItems, string $output, string $status): array
    {
        return [
            'status' => $status,
            'date' => $displayDate,
            'slug' => $slug,
            'json_path' => $jsonPath,
            'sicoes_items' => $streamedItems,
            'runner_output' => SensitiveDataSanitizer::text($output, 3000),
        ];
    }

    private function handleStreamLine(string $line, ?callable $onItem, ?callable $onProgress): bool
    {
        $line = trim($line);

        if ($line === '') {
            return false;
        }

        if (str_starts_with($line, '[SICOES_ITEM] ')) {
            $payload = json_decode(substr($line, strlen('[SICOES_ITEM] ')), true);

            if (! is_array($payload) || ! is_array($payload['item'] ?? null)) {
                throw new \RuntimeException('SICOES emitio un item invalido en el canal de streaming.');
            }

            if ($onItem) {
                $onItem($payload);
            }

            return true;
        }

        if (str_starts_with($line, '[SICOES_PROGRESS] ')) {
            $payload = json_decode(substr($line, strlen('[SICOES_PROGRESS] ')), true);

            if (is_array($payload) && $onProgress) {
                $onProgress(SensitiveDataSanitizer::context($payload, 500));
            }

            return false;
        }

        if (preg_match('/^\[(STEP \d+|OK|FAIL|MANUAL_[A-Z_]+|DOWNLOAD_[A-Z_]+|PW_[A-Z_]+|REAL_BROWSER[A-Z_]*|CDP|CDP_TRACE|FETCH)\]/', $line)) {
            if ($onProgress) {
                $displayLine = preg_replace('/^\[STEP (\d+)\]/', '[PASO $1]', $line) ?: $line;
                $displayLine = preg_replace(
                    '/^\[(OK|FAIL|MANUAL_[A-Z_]+|DOWNLOAD_[A-Z_]+|PW_[A-Z_]+|REAL_BROWSER[A-Z_]*|CDP|CDP_TRACE|FETCH)\]\s*/',
                    '',
                    $displayLine,
                ) ?: $displayLine;
                $onProgress(['message' => SensitiveDataSanitizer::text($displayLine, 500)]);
            }
        }

        return false;
    }

    private function appendProcessDiagnostics(string &$output, string ...$streams): void
    {
        foreach ($streams as $stream) {
            foreach (preg_split('/\r\n|\n|\r/', $stream) ?: [] as $line) {
                if (str_starts_with(trim($line), '[SICOES_ITEM] ')) {
                    continue;
                }

                $this->appendDiagnosticLine($output, $line);
            }
        }
    }

    private function appendDiagnosticLine(string &$output, string $line): void
    {
        $line = SensitiveDataSanitizer::text(trim($line), 1000);

        if ($line === null || $line === '') {
            return;
        }

        $output = trim($output.PHP_EOL.$line);

        if (strlen($output) > 30000) {
            $output = substr($output, -30000);
        }
    }

    private function displayDate(string $date): string
    {
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            return $date;
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
            return str_replace('-', '/', $date);
        }

        return Carbon::parse($date)->format('d/m/Y');
    }

    private function makeOutputWritable(string $basePath): void
    {
        foreach (['entrada', 'salida', 'fichas-finales', 'runtime'] as $directory) {
            $path = $basePath.DIRECTORY_SEPARATOR.$directory;

            if (! is_dir($path)) {
                @mkdir($path, 0777, true);
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                @chmod($item->getPathname(), $item->isDir() ? 0777 : 0666);
            }

            @chmod($path, 0777);
        }
    }

    private function clearDownloadCacheForDate(string $basePath, string $slug): void
    {
        $wordsBase = realpath($basePath.DIRECTORY_SEPARATOR.'entrada'.DIRECTORY_SEPARATOR.'words');
        $target = realpath($basePath.DIRECTORY_SEPARATOR.'entrada'.DIRECTORY_SEPARATOR.'words'.DIRECTORY_SEPARATOR.$slug);

        if (! $wordsBase || ! $target) {
            return;
        }

        $wordsBase = rtrim($wordsBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $targetPrefix = rtrim($target, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($targetPrefix, $wordsBase)) {
            throw new \RuntimeException('La carpeta temporal SICOES quedo fuera de entrada/words.');
        }

        foreach (glob($target.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $isReport = basename($path) === "_descargas-{$slug}.json";

            if ($isReport || in_array($extension, ['doc', 'docx', 'pdf'], true)) {
                @unlink($path);
            }
        }
    }

    private function nodeEnvironment(string $basePath): array
    {
        $runtimePath = $basePath.DIRECTORY_SEPARATOR.'runtime';
        $tempPath = $runtimePath.DIRECTORY_SEPARATOR.'temp';
        $cachePath = $runtimePath.DIRECTORY_SEPARATOR.'puppeteer-cache';

        foreach ([$runtimePath, $tempPath, $cachePath] as $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0777, true);
            }

            @chmod($path, 0777);
        }

        return array_filter([
            'TEMP' => $tempPath,
            'TMP' => $tempPath,
            'TMPDIR' => $tempPath,
            'SICOES_ASSISTED_DOWNLOAD' => (bool) config('sicoes.assisted_download', false) ? '1' : '0',
            'SICOES_DOWNLOAD_ATTEMPTS' => (string) config('sicoes.downloads.attempts', 2),
            'SICOES_DOWNLOAD_ATTEMPT_TIMEOUT_MS' => (string) config('sicoes.downloads.attempt_timeout_ms', 120000),
            'SICOES_REPLAY_TIMEOUT_MS' => (string) config('sicoes.downloads.replay_timeout_ms', 45000),
            'SICOES_MANUAL_DOWNLOAD_TIMEOUT_MS' => (string) config('sicoes.manual_download.timeout_ms', 600000),
            'SICOES_TOKEN_TIMEOUT_MS' => (string) config('sicoes.navigation.token_timeout_ms', 60000),
            'SICOES_TABLE_TIMEOUT_MS' => (string) config('sicoes.navigation.table_timeout_ms', 60000),
            'SICOES_MANUAL_DOWNLOAD_DIR' => config('sicoes.manual_download.directory'),
            'SICOES_PDFTOTEXT_PATH' => $this->pdfToTextBinary(),
            'SICOES_BROWSER_PATH' => config('sicoes.browser.path'),
            'SICOES_CDP_PORT' => (string) config('sicoes.browser.cdp_port', 9222),
            'SICOES_CDP_URL' => config('sicoes.browser.cdp_url', 'http://127.0.0.1:9222'),
            'PATH' => getenv('PATH') ?: null,
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            'COMSPEC' => getenv('COMSPEC') ?: 'C:\\Windows\\System32\\cmd.exe',
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function pdfToTextBinary(): ?string
    {
        $configured = trim((string) config('sicoes.pdf_to_text.path'));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $found = (new ExecutableFinder)->find('pdftotext');
        if ($found) {
            return $found;
        }

        $laragonCandidate = dirname(PHP_BINARY, 3)
            .DIRECTORY_SEPARATOR.'git'
            .DIRECTORY_SEPARATOR.'mingw64'
            .DIRECTORY_SEPARATOR.'bin'
            .DIRECTORY_SEPARATOR.'pdftotext.exe';

        return is_file($laragonCandidate) ? $laragonCandidate : null;
    }

    private function failureLine(string $output): ?string
    {
        return preg_match('/\[FAIL\]\s*Fase\s*\d+:[^\r\n]*/', $output, $matches)
            ? $matches[0]
            : null;
    }

    private function validatedFinalItems(string $jsonPath, bool $allowEmpty = false): array
    {
        $decoded = json_decode(file_get_contents($jsonPath) ?: '', true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("SICOES genero un JSON invalido: {$jsonPath}");
        }

        $items = isset($decoded['fichas_finales']) && is_array($decoded['fichas_finales'])
            ? $decoded['fichas_finales']
            : (array_is_list($decoded) ? $decoded : []);

        if (count($items) === 0 && ! $allowEmpty) {
            throw new \RuntimeException("SICOES genero JSON final vacio. No se importara: {$jsonPath}");
        }

        $invalid = collect($items)
            ->map(fn ($item, $index) => ['item' => is_array($item) ? $item : [], 'index' => $index])
            ->filter(fn ($row): bool => ! $this->validFinalItem($row['item']))
            ->take(5)
            ->map(fn ($row): string => '#'.($row['index'] + 1).' CUCE='.($row['item']['cuce'] ?? 'sin_cuce'))
            ->values()
            ->all();

        if ($invalid) {
            throw new \RuntimeException('SICOES genero fichas finales incompletas: '.implode(', ', $invalid));
        }

        return $items;
    }

    private function validFinalItem(array $item): bool
    {
        return $this->hasValue($item['cuce'] ?? null)
            && $this->hasValue($item['titulo_convocatoria'] ?? $item['objeto_contratacion'] ?? null)
            && $this->hasValue($item['empresa'] ?? $item['entidad'] ?? null);
    }

    private function isEmptyRun(string $basePath, string $slug, string $output): bool
    {
        if (! str_contains($output, '[SICOES_EMPTY] ')) {
            return false;
        }

        $path = $basePath.DIRECTORY_SEPARATOR.'salida'.DIRECTORY_SEPARATOR
            .'convocatorias'.DIRECTORY_SEPARATOR.$slug.'.json';

        if (! is_file($path)) {
            return false;
        }

        $decoded = json_decode(file_get_contents($path) ?: '', true);

        return is_array($decoded) && $decoded === [];
    }

    private function hasValue(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->contains(fn ($item): bool => $this->hasValue($item));
        }

        if (is_object($value)) {
            return $this->hasValue((array) $value);
        }

        return trim((string) $value) !== '';
    }

    private function nodeBinary(): string
    {
        $configured = config('sicoes.node.path');

        if ($configured && is_file($configured)) {
            return $configured;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return 'node';
        }

        foreach ([
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'node';
    }

    private function processIdleTimeout(bool $assistedDownload): int
    {
        $configuredTimeout = (int) config('sicoes.process.idle_timeout', 240);

        if (! $assistedDownload) {
            return $configuredTimeout;
        }

        $manualDownloadSeconds = (int) ceil(
            ((int) config('sicoes.manual_download.timeout_ms', 600000)) / 1000,
        );

        return max($configuredTimeout, $manualDownloadSeconds + 60);
    }
}
