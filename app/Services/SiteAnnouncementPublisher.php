<?php

namespace App\Services;

use App\Models\BotVacancyPreview;
use App\Models\Company;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SiteAnnouncementPublisher
{
    /**
     * Publish one reviewed local preview to the website database.
     * Local preview tables are deliberately never queried through the site connection.
     *
     * @param  array<int, string>  $professionNames
     * @param  array<int, string>  $locationNames
     * @param  array<string, mixed>  $announcement
     * @return array{id: int, created: bool, attachment_count: int}
     */
    public function publish(
        BotVacancyPreview $preview,
        Company $company,
        array $professionNames,
        array $locationNames,
        array $announcement,
    ): array {
        $this->assertConfigured();
        $this->assertFileTransferConfigured($preview);
        $db = DB::connection('site');
        $schema = $db->getSchemaBuilder();

        foreach ([
            'announcements', 'announcement_files', 'companies', 'users',
            'locations', 'profesions', 'announcement_profesion', 'announcement_location',
        ] as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException("La base del sitio no contiene la tabla requerida: {$table}.");
            }
        }

        $this->assertColumns($schema, 'announcements', [
            'announce_title', 'description', 'expiration_time', 'salary', 'pro', 'scheduled_at',
            'notification_sent', 'user_id', 'company_id', 'source_url', 'source_hash',
        ]);
        $this->assertColumns($schema, 'announcement_files', ['announcement_id', 'url', 'original_name']);

        $sourceUrl = trim((string) $preview->source_url);
        if ($sourceUrl === '') {
            throw new RuntimeException('La convocatoria no tiene URL de origen para deduplicar.');
        }

        $sourceHash = hash('sha256', $sourceUrl);
        $professionIds = $this->mapCatalogIds($db, 'profesions', 'profesion_name', $professionNames, 'profesión');
        $locationIds = $this->mapCatalogIds($db, 'locations', 'location_name', $locationNames, 'ubicación');
        return $db->transaction(function () use (
            $db,
            $preview,
            $company,
            $professionIds,
            $locationIds,
            $announcement,
            $sourceUrl,
            $sourceHash,
        ): array {
            $attachments = $this->attachmentsForPublication($preview);
            $remoteCompany = $this->resolveCompany($db, $company);
            $userId = $this->resolvePublisherUserId($db, (string) ($remoteCompany->user_id ?? ''));
            $record = $db->table('announcements')
                ->where(function ($query) use ($sourceHash, $sourceUrl): void {
                    $query->where('source_hash', $sourceHash)->orWhere('source_url', $sourceUrl);
                })
                ->lockForUpdate()
                ->first();

            $isNew = $record === null;
            $data = [
                'announce_title' => $announcement['announce_title'],
                'description' => $announcement['description'],
                'expiration_time' => $announcement['expiration_time'],
                'salary' => $announcement['salary'],
                'pro' => $announcement['pro'],
                'scheduled_at' => null,
                'notification_sent' => false,
                'company_id' => $remoteCompany->id,
                'user_id' => $userId,
                'source_url' => $sourceUrl,
                'source_hash' => $sourceHash,
                'updated_at' => now(),
            ];

            if ($this->hasColumn($db, 'announcements', 'announcement_type_id')) {
                $data['announcement_type_id'] = $this->resolveAnnouncementTypeId($db);
            }
            if ($this->hasColumn($db, 'announcements', 'deleted_at')) {
                $data['deleted_at'] = null;
            }

            if ($isNew) {
                $data['created_at'] = now();
                $remoteAnnouncementId = (int) $db->table('announcements')->insertGetId($data);
            } else {
                $remoteAnnouncementId = (int) $record->id;
                $db->table('announcements')->where('id', $remoteAnnouncementId)->update($data);
            }

            $this->syncPivot($db, 'announcement_profesion', 'profesion_id', $remoteAnnouncementId, $professionIds);
            $this->syncPivot($db, 'announcement_location', 'location_id', $remoteAnnouncementId, $locationIds);
            $this->syncAttachments($db, $remoteAnnouncementId, $attachments);

            return [
                'id' => $remoteAnnouncementId,
                'created' => $isNew,
                'attachment_count' => count($attachments),
            ];
        }, 3);
    }

    private function assertConfigured(): void
    {
        if (trim((string) config('database.connections.site.url')) !== '') {
            return;
        }

        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (trim((string) config("database.connections.site.{$key}")) === '') {
                throw new RuntimeException('La conexión del sitio no está configurada completamente (SITE_DB_*).');
            }
        }
    }

    private function assertColumns($schema, string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                throw new RuntimeException("La tabla {$table} del sitio no contiene la columna requerida: {$column}.");
            }
        }
    }

    private function hasColumn(Connection $db, string $table, string $column): bool
    {
        return $db->getSchemaBuilder()->hasColumn($table, $column);
    }

    private function mapCatalogIds(
        Connection $db,
        string $table,
        string $nameColumn,
        array $names,
        string $label,
    ): array {
        $wanted = collect($names)
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique(fn (string $name): string => $this->normalize($name));

        if ($wanted->isEmpty()) {
            throw new RuntimeException("La convocatoria no tiene {$label} seleccionada.");
        }

        $catalog = $db->table($table)->get(['id', $nameColumn]);
        $byName = $catalog->keyBy(fn ($item): string => $this->normalize((string) $item->{$nameColumn}));
        $ids = [];
        $missing = [];
        foreach ($wanted as $name) {
            $item = $byName->get($this->normalize($name));
            if (! $item) {
                $missing[] = $name;
                continue;
            }
            $ids[] = (int) $item->id;
        }

        if ($missing !== []) {
            throw new RuntimeException('No se encontraron en el catálogo del sitio: '.implode(', ', $missing).'.');
        }

        return array_values(array_unique($ids));
    }

    private function resolveCompany(Connection $db, Company $company): object
    {
        $siteCompanyId = (int) ($company->site_company_id ?? 0);
        if ($siteCompanyId > 0) {
            $query = $db->table('companies')->where('id', $siteCompanyId);
            if ($this->hasColumn($db, 'companies', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $remote = $query->first();
            if ($remote) {
                return $remote;
            }
        }

        $wanted = $this->normalizeCompanyName((string) $company->company_name);
        $query = $db->table('companies');
        if ($this->hasColumn($db, 'companies', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $columns = ['id', 'company_name'];
        if ($this->hasColumn($db, 'companies', 'deleted_at')) {
            $columns[] = 'deleted_at';
        }
        if ($this->hasColumn($db, 'companies', 'user_id')) {
            $columns[] = 'user_id';
        }
        $matches = $query->get($columns)
            ->filter(fn ($row): bool => $this->normalizeCompanyName((string) $row->company_name) === $wanted)
            ->values();

        if ($matches->count() > 1) {
            throw new RuntimeException('Hay varias empresas equivalentes en el sitio; se detuvo la publicacion para evitar asociarla incorrectamente.');
        }

        if ($matches->isEmpty()) {
            throw new RuntimeException('La empresa no existe activa en la web. No se creo una empresa nueva; revisa el nombre o registra la empresa en el sitio antes de publicar.');
        }

        return $matches->first();
    }
    private function resolvePublisherUserId(Connection $db, string $companyOwnerId): string
    {
        $schema = $db->getSchemaBuilder();
        foreach (['roles', 'model_has_roles'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException('No se pudo verificar el rol ADMIN de autorÃ­a en el sitio.');
            }
        }

        $adminRoleId = $db->table('roles')->where('name', config('trabajonautas.roles.admin', 'ADMIN'))->value('id');
        if (! $adminRoleId) {
            throw new RuntimeException('El sitio no tiene un rol ADMIN para atribuir la publicaciÃ³n.');
        }

        $admins = $db->table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', 'App\\Models\\User');
            })
            ->where('model_has_roles.role_id', $adminRoleId)
            ->whereNull('users.deleted_at')
            ->where('users.actived', true)
            ->distinct()
            ->pluck('users.id');

        $configuredId = trim((string) config('site_publishing.user_id'));
        if ($configuredId !== '') {
            if (! $admins->contains($configuredId)) {
                throw new RuntimeException('El usuario configurado para publicar no es un administrador activo del sitio.');
            }

            return $configuredId;
        }

        if ($companyOwnerId !== '' && $admins->contains($companyOwnerId)) {
            return $companyOwnerId;
        }
        if ($admins->count() === 1) {
            return (string) $admins->first();
        }

        throw new RuntimeException('No se pudo elegir un autor ADMIN único. Configura SITE_PUBLISH_USER_ID con el UUID de un administrador activo.');
    }

    private function resolveAnnouncementTypeId(Connection $db): ?int
    {
        $slug = trim((string) config('site_publishing.announcement_type_slug'));
        if ($slug === '') {
            return null;
        }
        if (! $db->getSchemaBuilder()->hasTable('announcement_types')) {
            throw new RuntimeException('Se configuró un tipo de convocatoria, pero el sitio no tiene announcement_types.');
        }

        $id = $db->table('announcement_types')->where('slug', $slug)->where('is_active', true)->value('id');
        if (! $id) {
            throw new RuntimeException('El slug configurado en SITE_PUBLISH_ANNOUNCEMENT_TYPE_SLUG no está activo en el sitio.');
        }

        return (int) $id;
    }

    private function syncPivot(Connection $db, string $table, string $relatedColumn, int $announcementId, array $relatedIds): void
    {
        $db->table($table)->where('announcement_id', $announcementId)->delete();
        $now = now();
        foreach ($relatedIds as $relatedId) {
            $row = [
                'announcement_id' => $announcementId,
                $relatedColumn => $relatedId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($table === 'announcement_location' && $this->hasColumn($db, $table, 'region')) {
                $row['region'] = null;
            }
            $db->table($table)->insert($row);
        }
    }

    private function attachmentsForPublication(BotVacancyPreview $preview): array
    {
        $attachments = is_array($preview->attachments) ? $preview->attachments : [];
        if ($attachments === []) {
            return [];
        }

        $settings = (array) config('site_publishing.files', []);
        $password = (string) ($settings['password'] ?? '');
        $root = rtrim(str_replace('\\', '/', trim((string) $settings['root'])), '/');
        return $this->transferAttachments($settings, $root, $attachments, (string) $preview->source_url);
    }

    private function assertFileTransferConfigured(BotVacancyPreview $preview): void
    {
        if (! is_array($preview->attachments) || $preview->attachments === []) {
            return;
        }

        $settings = (array) config('site_publishing.files', []);
        foreach (['host', 'username', 'password', 'root'] as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                throw new RuntimeException('Publicación cancelada: falta configurar el acceso FTPS explícito para los archivos del sitio (SITE_FILES_*).');
            }
        }
        $root = rtrim(str_replace('\\', '/', trim((string) $settings['root'])), '/');
        if ($root === '' || preg_match('#(^|/)\.\.?(/|$)#', $root)) {
            throw new RuntimeException('SITE_FILES_ROOT debe ser la ruta FTP segura que corresponde a storage/app/public.');
        }
    }

    private function transferAttachments(array $settings, string $root, array $attachments, string $sourceUrl): array
    {
        $sourceHash = hash('sha256', $sourceUrl);
        $uploaded = [];

        foreach (array_values($attachments) as $index => $attachment) {
            if (! is_array($attachment) || empty($attachment['url'])) {
                continue;
            }

            $localPath = trim((string) ($attachment['local_path'] ?? ''));
            if ($localPath === '' || ! is_file($localPath)) {
                $localPath = Storage::disk('public')->path(ltrim(str_replace('\\', '/', (string) $attachment['url']), '/'));
            }
            if (! is_file($localPath) || ! is_readable($localPath)) {
                throw new RuntimeException('Publicación cancelada: no se encontró localmente un archivo adjunto requerido.');
            }

            $originalName = basename((string) ($attachment['original_name'] ?? $attachment['url']));
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $stem = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'archivo';
            $contentHash = hash_file('sha256', $localPath);
            $relativePath = 'convocatorias/bot/'.$sourceHash.'/'.($index + 1).'-'.substr($contentHash, 0, 16).'-'.$stem
                .($extension !== '' ? '.'.strtolower($extension) : '');
            $this->uploadWithExplicitFtps($settings, $root.'/'.$relativePath, $localPath);

            $uploaded[] = [
                'url' => $relativePath,
                'original_name' => $originalName,
            ];
        }

        return $uploaded;
    }

    private function uploadWithExplicitFtps(array $settings, string $remotePath, string $localPath): void
    {
        if (! extension_loaded('curl') || ! defined('CURLUSESSL_ALL')) {
            throw new RuntimeException('No está disponible cURL con soporte FTPS explícito para transferir archivos.');
        }

        $host = trim((string) $settings['host']);
        $port = (int) ($settings['port'] ?? 21);
        $timeout = max(1, (int) ($settings['timeout'] ?? 20));
        $segments = array_map('rawurlencode', explode('/', ltrim($remotePath, '/')));
        $url = 'ftp://'.$host.':'.$port.'/'.implode('/', $segments);
        $input = fopen($localPath, 'rb');
        if ($input === false) {
            throw new RuntimeException('No se pudo abrir localmente un archivo adjunto para transferir.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($input);
            throw new RuntimeException('No se pudo iniciar el cliente FTPS.');
        }

        curl_setopt_array($curl, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $input,
            CURLOPT_INFILESIZE => (int) filesize($localPath),
            CURLOPT_USERPWD => (string) $settings['username'].':'.(string) $settings['password'],
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => max($timeout, 300),
            CURLOPT_FAILONERROR => true,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        try {
            $result = curl_exec($curl);
            if ($result === false) {
                // Deliberately omit cURL's raw error text: some transports include endpoint details.
                throw new RuntimeException('Falló la transferencia FTPS explícita o la verificación TLS del servidor.');
            }
            $responseCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode < 200 || $responseCode >= 400) {
                throw new RuntimeException('El servidor FTPS no confirmó la transferencia del archivo.');
            }
        } finally {
            curl_close($curl);
            fclose($input);
        }
    }

    private function syncAttachments(Connection $db, int $announcementId, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $exists = $db->table('announcement_files')
                ->where('announcement_id', $announcementId)
                ->where('url', $attachment['url'])
                ->exists();
            if ($exists) {
                continue;
            }
            $db->table('announcement_files')->insert([
                'announcement_id' => $announcementId,
                'url' => $attachment['url'],
                'original_name' => $attachment['original_name'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function normalizeCompanyName(string $value): string
    {
        $normalized = $this->normalize($value);

        return trim((string) preg_replace(
            '/(?:^|\\s)(?:s a|sa|s r l|srl|s a s|sas|s a c|sac|s c|sc|ltda|limitada|inc|llc|corp|corporation)(?:$|\\s)/',
            ' ',
            ' '.$normalized.' '
        ));
    }
}
