<?php

namespace App\Modules\BackupRecovery\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** CLI-only encrypted backup sets. Never changes application data or live storage. */
final class BackupArchive
{
    private const CHUNK = 1048576;

    private function reject(bool $condition, string $code): void
    {
        if ($condition) {
            throw new RuntimeException($code);
        }
    }

    public function safePath(string $path): string
    {
        $this->reject(! str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('#(^|/)\.{1,2}(/|$)#', $path) || str_contains($path, '//'), 'unsafe_path');
        $path = rtrim($path, '/');
        $this->reject($path === '', 'unsafe_path');
        $part = '';
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            $part .= '/'.$segment;
            $this->reject(is_link($part) || (file_exists($part) && ! is_dir($part) && $part !== $path), 'unsafe_path');
        }

        return $path;
    }

    private function nested(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b.'/');
    }

    private function outputPath(string $path): string
    {
        $path = $this->safePath($path);
        $this->reject($this->nested($path, base_path()) || $this->nested(base_path(), $path), 'unsafe_destination');
        foreach ($this->roots() as $root) {
            $this->reject($this->nested($path, $root) || $this->nested($root, $path), 'storage_destination_overlap');
        }

        return $path;
    }

    private function roots(): array
    {
        $this->reject(config('filesystems.disks.fleet_documents.driver') !== 'local' || config('filesystems.disks.local.driver') !== 'local', 'unsupported_storage');
        $roots = ['fleet_documents' => config('filesystems.disks.fleet_documents.root'), 'local' => config('filesystems.disks.local.root'), 'workshop' => config('workshop.storage_root')];
        foreach ($roots as &$root) {
            $root = $this->safePath((string) $root);
            $this->reject($this->nested(base_path(), $root), 'unsafe_storage');
            foreach ([public_path(), (string) config('filesystems.disks.public.root')] as $public) {
                $public = $this->safePath($public);
                $this->reject($this->nested($root, $public) || $this->nested($public, $root), 'public_storage_rejected');
            }
        }

        return $roots;
    }

    private function key(): string
    {
        $this->reject(! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'), 'sodium_required');
        $path = $this->safePath((string) config('backups.key_file'));
        clearstatcache(true, $path);
        $this->reject(! is_file($path) || filesize($path) > 128 || (fileperms($path) & 0077) !== 0 || $this->nested($path, base_path()), 'unsafe_key_file');
        foreach ($this->roots() as $root) {
            $this->reject($this->nested($path, $root), 'unsafe_key_file');
        }
        $key = base64_decode(trim(file_get_contents($path)), true);
        $this->reject(! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'invalid_encryption_key');

        return $key;
    }

    private function directory(string $path): void
    {
        $this->reject(! mkdir($path, 0700, true), 'directory_creation_failed');
    }

    private function tool(string $tool, array $arguments, array $config): string
    {
        $binary = rtrim((string) config('backups.pg_bin'), '/');
        $binary = $binary === '' ? $tool : $binary.'/'.$tool;
        $version = new Process([$binary, '--version']);
        $version->setTimeout(10)->run();
        $this->reject(! $version->isSuccessful() || ! preg_match('/\(PostgreSQL\) 18\./', $version->getOutput()), 'postgres_18_tools_required');
        $process = new Process([$binary, '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'], '--no-password', ...$arguments], null, [
            'PGPASSWORD' => (string) ($config['password'] ?? ''), 'PGSSLMODE' => $config['sslmode'] ?? 'prefer',
            'PGOPTIONS' => false, 'PGSERVICE' => false, 'PGSERVICEFILE' => false,
        ]);
        $process->setTimeout((int) config('backups.timeout_seconds', 600));
        $process->disableOutput(); // Never retain raw diagnostics: they can contain SQL, paths or credentials.
        $process->run();
        $this->reject(! $process->isSuccessful(), 'postgres_process_failed');

        return trim($version->getOutput());
    }

    private function connection(string $name): array
    {
        $connection = DB::connection($name);
        $config = $connection->getConfig(); // Includes resolved Laravel URL configuration, without logging it.
        $this->reject(($config['driver'] ?? '') !== 'pgsql' || ! is_string($config['host'] ?? null) || $config['host'] === '' || ! is_string($config['username'] ?? null) || $config['username'] === '' || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $config['database'] ?? ''), 'postgres_connection_required');
        $version = (int) $connection->selectOne("SELECT current_setting('server_version_num')::integer AS version")->version;
        $this->reject(intdiv($version, 10000) !== 18, 'postgres_18_required');

        return [$connection, $config];
    }

    private function databaseSettings(Connection $connection, string $name): array
    {
        return (array) $connection->selectOne("SELECT pg_encoding_to_char(encoding) AS encoding, datcollate AS collate, datctype AS ctype, datlocprovider AS provider, to_jsonb(d)->>'datlocale' AS locale, pg_database_collation_actual_version(oid) AS collation_version FROM pg_database d WHERE datname=?", [$name]);
    }

    private function files(string $root): array
    {
        if (! file_exists($root)) {
            return [];
        }
        $this->reject(! is_dir($root), 'unsafe_storage');
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $this->reject($file->isLink() || ! $file->isFile(), 'unsafe_storage_entry');
            $relative = substr($file->getPathname(), strlen($root) + 1);
            // Locks represent running processes, not recoverable business state.
            if ($relative === '.coordination.lock' || str_ends_with($relative, '/.coordination.lock') || str_starts_with($file->getPathname(), rtrim((string) config('workshop.storage_root'), '/').'/locks/')) {
                continue;
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }

    private function encrypt(string $source, string $target, string $key): array
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        $this->reject(! $input || ! $output, 'backup_io_failed');
        try {
            chmod($target, 0600);
            fwrite($output, $header);
            $hash = hash_init('sha256');
            $size = 0;
            do {
                $bytes = fread($input, self::CHUNK);
                $this->reject($bytes === false, 'backup_read_failed');
                $final = strlen($bytes) < self::CHUNK;
                hash_update($hash, $bytes);
                $size += strlen($bytes);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $bytes, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0);
                $this->reject(fwrite($output, $cipher) !== strlen($cipher), 'backup_write_failed');
            } while (! $final);
            $this->reject(! fflush($output) || ! fsync($output), 'backup_sync_failed');

            return ['size' => $size, 'sha256' => hash_final($hash), 'cipher_sha256' => hash_file('sha256', $target)];
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function decrypt(string $source, ?string $target, string $key, array $expected): void
    {
        $input = fopen($source, 'rb');
        $output = $target === null ? null : fopen($target, 'xb');
        $this->reject(! $input || ($target !== null && ! $output), 'restore_io_failed');
        try {
            if ($output) {
                chmod($target, 0600);
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            $hash = hash_init('sha256');
            $size = 0;
            $final = false;
            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ($chunk === '') {
                    break;
                }
                $this->reject($final, 'trailing_ciphertext');
                $plain = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
                $this->reject($plain === false, 'invalid_ciphertext');
                [$bytes, $tag] = $plain;
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                hash_update($hash, $bytes);
                $size += strlen($bytes);
                if ($output) {
                    $this->reject(fwrite($output, $bytes) !== strlen($bytes), 'restore_write_failed');
                }
            }
            $this->reject(! $final || $size !== $expected['size'] || ! hash_equals($expected['sha256'], hash_final($hash)), 'plaintext_integrity_failed');
            if ($output) {
                $this->reject(! fflush($output) || ! fsync($output), 'restore_sync_failed');
            }
        } finally {
            fclose($input);
            if ($output) {
                fclose($output);
            }
        }
    }

    private function syncDirectory(string $directory): void
    {
        $handle = fopen($directory, 'r');
        $this->reject(! $handle, 'directory_sync_failed');
        try {
            $this->reject(! fsync($handle), 'directory_sync_failed');
        } finally {
            fclose($handle);
        }
    }

    private function write(string $file, string $bytes): void
    {
        $handle = fopen($file, 'xb');
        $this->reject(! $handle, 'backup_io_failed');
        try {
            chmod($file, 0600);
            $this->reject(fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle), 'backup_write_failed');
        } finally {
            fclose($handle);
        }
    }

    public function create(string $connection, string $destination, bool $quiesced): string
    {
        $this->reject(! $quiesced || ! app()->isDownForMaintenance(), 'maintenance_and_stopped_writers_required');
        $key = $this->key();
        $destination = $this->outputPath($destination);
        if (! file_exists($destination)) {
            $this->directory($destination);
        }
        $this->reject(! is_dir($destination) || (fileperms($destination) & 0077) !== 0, 'private_destination_required');
        $this->reject(is_link($destination.'/.backup.lock'), 'unsafe_path');
        $lock = fopen($destination.'/.backup.lock', 'c');
        $this->reject(! $lock || ! flock($lock, LOCK_EX | LOCK_NB), 'backup_already_running');
        $id = (string) Str::uuid();
        $stage = $destination.'/.'.$id.'.partial';
        $database = null;
        $ownsTransaction = false;
        try {
            [$database, $config] = $this->connection($connection);
            $this->reject($database->transactionLevel() !== 0, 'root_transaction_required');
            $this->directory($stage);
            $database->beginTransaction();
            $ownsTransaction = true;
            $database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $database->statement("SET LOCAL lock_timeout = '5s'");
            $tables = $database->select("SELECT quote_ident(n.nspname)||'.'||quote_ident(c.relname) AS name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.relkind IN ('r','p') AND n.nspname NOT LIKE 'pg\\_%' AND n.nspname<>'information_schema' ORDER BY n.nspname,c.relname");
            if ($tables !== []) {
                $database->statement('LOCK TABLE '.implode(',', array_column($tables, 'name')).' IN SHARE MODE');
            }
            $snapshot = $database->selectOne('SELECT pg_export_snapshot() AS snapshot')->snapshot;
            $dumpVersion = $this->tool('pg_dump', ['--format=custom', '--no-owner', '--no-privileges', '--snapshot='.$snapshot, '--file='.$stage.'/database.tmp', '--dbname='.$config['database']], $config);
            chmod($stage.'/database.tmp', 0600);
            $this->tool('pg_restore', ['--list', $stage.'/database.tmp'], $config);
            $manifest = ['format' => 1, 'id' => $id, 'created_at' => CarbonImmutable::now('America/Costa_Rica')->toIso8601String(), 'timezone' => 'America/Costa_Rica', 'postgres' => $database->selectOne('SHOW server_version')->server_version, 'database_settings' => $this->databaseSettings($database, $config['database']), 'pg_dump' => $dumpVersion, 'sodium' => SODIUM_LIBRARY_VERSION, 'php' => PHP_VERSION, 'laravel' => app()->version(), 'search_path' => $database->selectOne("SELECT current_setting('search_path') AS path")->path, 'encryption' => 'secretstream-xchacha20poly1305', 'storage' => [], 'entries' => []];
            $manifest['entries'][] = ['path' => 'database.dump', 'blob' => '000000.enc', ...$this->encrypt($stage.'/database.tmp', $stage.'/000000.enc', $key)];
            unlink($stage.'/database.tmp');
            $roots = $this->roots();
            $copied = [];
            $paths = [];
            // A nested Workshop root is already included in the local private disk.
            uasort($roots, fn ($a, $b) => strlen($a) <=> strlen($b));
            foreach ($roots as $label => $root) {
                $parent = null;
                foreach ($copied as $parentLabel => $parentRoot) {
                    if ($this->nested($root, $parentRoot)) {
                        $parent = $parentLabel;
                        break;
                    }
                }
                $prefix = $parent === null ? $label : $parent.substr($root, strlen($copied[$parent]));
                $manifest['storage'][$label] = $prefix;
                if ($parent !== null) {
                    continue;
                }
                $copied[$label] = $root;
                foreach ($this->files($root) as $relative => $file) {
                    $path = 'private/'.$label.'/'.$relative;
                    $blob = sprintf('%06d.enc', count($manifest['entries']));
                    $entry = ['path' => $path, 'blob' => $blob, ...$this->encrypt($file, $stage.'/'.$blob, $key)];
                    $manifest['entries'][] = $entry;
                    $paths[$file] = $entry;
                }
            }
            $this->references($database->getPdo(), $paths, $roots);
            $database->rollBack();
            $ownsTransaction = false;
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->write($stage.'/manifest.json', $json);
            $this->write($stage.'/manifest.hmac', hash_hmac('sha256', 'transport-backup-v1:'.$json, $key));
            $this->verify($stage);
            $this->syncDirectory($stage);
            $final = $destination.'/'.$id;
            $this->reject(file_exists($final) || ! rename($stage, $final), 'backup_publish_failed');
            $this->syncDirectory($destination);

            return $id;
        } finally {
            if ($ownsTransaction && $database && $database->transactionLevel() > 0) {
                $database->rollBack();
            }
            // Only this invocation's unpublished staging directory is removed.
            if (is_dir($stage)) {
                $this->remove($stage);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function references(PDO $pdo, array $paths, array $roots): void
    {
        foreach ([['documents', 'fleet_documents', '', null, null], ['expense_receipts', 'fleet_documents', '', 'sha256', 'size_bytes'], ['workshop_attachments', 'workshop', 'bytes/', 'checksum', 'size_bytes']] as [$table, $label, $prefix, $hash, $size]) {
            if ($pdo->query("SELECT to_regclass('$table')")->fetchColumn() === null) {
                continue;
            }
            foreach ($pdo->query('SELECT storage_key'.($hash ? ','.$hash.','.$size : '').' FROM '.$table, PDO::FETCH_ASSOC) as $row) {
                $key = $row['storage_key'];
                $this->reject(! is_string($key) || ! preg_match('#\A[a-zA-Z0-9_./-]+\z#D', $key) || preg_match('#(^|/)\.\.(/|$)#', $key) || str_starts_with($key, '/'), 'invalid_document_reference');
                $entry = $paths[$roots[$label].'/'.$prefix.$key] ?? null;
                $this->reject($entry === null || ($hash && (! hash_equals($row[$hash], $entry['sha256']) || (int) $row[$size] !== $entry['size'])), 'private_reference_integrity_failed');
            }
        }
    }

    public function verify(string $directory): array
    {
        $directory = $this->safePath($directory);
        $key = $this->key();
        foreach (['manifest.json', 'manifest.hmac'] as $file) {
            $this->reject(is_link($directory.'/'.$file) || ! is_file($directory.'/'.$file) || filesize($directory.'/'.$file) > 16777216, 'invalid_manifest');
        }
        $json = file_get_contents($directory.'/manifest.json');
        $this->reject(! hash_equals(hash_hmac('sha256', 'transport-backup-v1:'.$json, $key), trim(file_get_contents($directory.'/manifest.hmac'))), 'manifest_authentication_failed');
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->reject(($manifest['format'] ?? null) !== 1 || ! Str::isUuid($manifest['id'] ?? '') || ($manifest['encryption'] ?? '') !== 'secretstream-xchacha20poly1305' || ! is_array($manifest['entries'] ?? null), 'invalid_manifest');
        $settings = $manifest['database_settings'] ?? null;
        $this->reject(! is_array($settings) || array_keys($settings) !== ['encoding', 'collate', 'ctype', 'provider', 'locale', 'collation_version'] || count(array_filter($settings, fn ($value) => $value !== null && ! is_string($value))) !== 0, 'invalid_database_settings');
        $storage = $manifest['storage'] ?? [];
        $labels = array_keys($storage);
        sort($labels);
        $this->reject($labels !== ['fleet_documents', 'local', 'workshop'], 'invalid_storage_map');
        foreach ($storage as $prefix) {
            $this->reject(! is_string($prefix) || ! preg_match('#\A[a-zA-Z0-9_./-]+\z#D', $prefix) || str_starts_with($prefix, '/') || preg_match('#(^|/)\.{1,2}(/|$)#', $prefix) || str_contains($prefix, '//'), 'invalid_storage_map');
        }
        $this->reject(! is_string($manifest['search_path'] ?? null) || strlen($manifest['search_path']) > 4096, 'invalid_search_path');
        $seen = [];
        $blobs = [];
        foreach ($manifest['entries'] as $entry) {
            $path = $entry['path'] ?? '';
            $blob = $entry['blob'] ?? '';
            $this->reject(! is_string($path) || (! str_starts_with($path, 'private/') && $path !== 'database.dump') || preg_match('#(^|/)\.{1,2}(/|$)#', $path) || str_contains($path, '//') || str_contains($path, "\0") || ! preg_match('/\A[0-9]{6,}\.enc\z/D', $blob) || isset($seen[$path]) || isset($blobs[$blob]) || ! is_int($entry['size'] ?? null) || $entry['size'] < 0 || ! preg_match('/\A[0-9a-f]{64}\z/D', $entry['sha256'] ?? '') || ! preg_match('/\A[0-9a-f]{64}\z/D', $entry['cipher_sha256'] ?? ''), 'invalid_manifest_entry');
            $file = $directory.'/'.$blob;
            $this->reject(is_link($file) || ! is_file($file) || ! hash_equals($entry['cipher_sha256'], hash_file('sha256', $file)), 'ciphertext_integrity_failed');
            $this->decrypt($file, null, $key, $entry);
            $seen[$path] = true;
            $blobs[$blob] = true;
        }
        $this->reject(! isset($seen['database.dump']) || count(scandir($directory)) !== count($blobs) + 4, 'incomplete_or_unexpected_archive');

        return $manifest;
    }

    public function restore(string $directory, string $connection, string $target, string $files, string $confirmation, bool $isolated): string
    {
        $this->reject(! $isolated || $connection === '' || $connection !== config('backups.restore_connection') || ! preg_match('/\Arestore_[a-z0-9_]{1,50}\z/D', $target), 'isolated_restore_required');
        $directory = $this->safePath($directory);
        $files = $this->outputPath($files);
        $this->reject($this->nested($files, $directory) || $this->nested($directory, $files), 'restore_archive_overlap');
        $this->reject(file_exists($files), 'restore_files_exist');
        [$source, $config] = $this->connection($connection);
        $default = DB::connection()->getConfig();
        if (app()->environment('testing', 'e2e')) {
            $this->reject(getenv('TEST_DB_ISOLATED') !== '1' || ! str_starts_with($config['host'], '/tmp/transporte-tests-'), 'isolated_test_server_required');
        } else {
            $this->reject($config['host'] === ($default['host'] ?? null) && (string) $config['port'] === (string) ($default['port'] ?? null), 'live_server_restore_rejected');
        }
        $identity = $config['host'].':'.$config['port'].'/'.$target.'|'.$files;
        $this->reject(! hash_equals($identity, $confirmation), 'destination_confirmation_required');
        $this->reject($source->transactionLevel() !== 0 || $source->selectOne('SELECT 1 AS present FROM pg_database WHERE datname=?', [$target]) !== null, 'restore_database_exists_or_transaction_active');
        $manifest = $this->verify($directory); // Authenticate and verify every byte BEFORE destination creation.
        $this->reject($this->databaseSettings($source, 'template0') !== $manifest['database_settings'], 'recovery_locale_mismatch');
        $key = $this->key();
        $this->directory($files);
        try {
            foreach ($manifest['entries'] as $entry) {
                $path = $files.'/'.$entry['path'];
                if (! is_dir(dirname($path))) {
                    $this->directory(dirname($path));
                }
                $this->decrypt($directory.'/'.$entry['blob'], $path, $key, $entry);
            }
            foreach ($manifest['storage'] as $prefix) {
                $root = $files.'/private/'.$prefix;
                if (! is_dir($root)) {
                    $this->directory($root);
                }
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($files, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if ($file->isDir()) {
                    $this->syncDirectory($file->getPathname());
                }
            }
            $this->syncDirectory($files);
            $source->statement('CREATE DATABASE "'.$target.'" TEMPLATE template0');
            $this->tool('pg_restore', ['--exit-on-error', '--single-transaction', '--no-owner', '--no-privileges', '--dbname='.$target, $files.'/database.dump'], $config);
            // Validate database-file references after restoration, including hashes where stored.
            $restored = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$target.';sslmode='.($config['sslmode'] ?? 'prefer'), $config['username'], $config['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $searchPath = $restored->prepare("SELECT set_config('search_path', ?, false)");
            $searchPath->execute([$manifest['search_path']]);
            $roots = [];
            foreach ($manifest['storage'] as $label => $prefix) {
                $roots[$label] = $files.'/private/'.$prefix;
            }
            $paths = [];
            foreach ($manifest['entries'] as $entry) {
                $paths[$files.'/'.$entry['path']] = $entry;
            }
            $this->references($restored, $paths, $roots);
            unlink($files.'/database.dump');
            $this->write($files.'/RESTORE_COMPLETE.json', json_encode(['backup_id' => $manifest['id'], 'completed_at' => CarbonImmutable::now('America/Costa_Rica')->toIso8601String()], JSON_THROW_ON_ERROR));

            $this->syncDirectory($files);

            return $manifest['id'];
        } catch (Throwable $error) {
            // Never drop a database or remove recovered files on an uncertain/partial result.
            if (is_file($files.'/RESTORE_COMPLETE.json')) {
                unlink($files.'/RESTORE_COMPLETE.json');
            }
            $this->write($files.'/RESTORE_FAILED', 'Partial isolated recovery; do not connect application. Inspect and retry in NEW targets.');
            throw new RuntimeException('isolated_restore_partial_failure', 0, $error);
        }
    }

    public function prune(string $destination): int
    {
        $days = config('backups.retention_days');
        $this->reject(filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 1, 'retention_policy_required');
        $destination = $this->outputPath($destination);
        $this->reject(! is_dir($destination) || (fileperms($destination) & 0077) !== 0, 'private_destination_required');
        $this->reject(is_link($destination.'/.backup.lock'), 'unsafe_path');
        $lock = fopen($destination.'/.backup.lock', 'c');
        $this->reject(! $lock || ! flock($lock, LOCK_EX | LOCK_NB), 'backup_already_running');
        $removed = 0;
        $candidates = [];
        try {
            foreach (scandir($destination) as $id) {
                if (! Str::isUuid($id)) {
                    continue;
                }
                $path = $destination.'/'.$id;
                $manifest = $this->verify($path);
                $this->reject($id !== $manifest['id'], 'retention_identity_mismatch');
                if (CarbonImmutable::parse($manifest['created_at'])->lt(CarbonImmutable::now()->subDays((int) $days))) {
                    $candidates[] = $path;
                }
            }

            // Validate every candidate before deleting any set; mixed keys/corruption stop pruning.
            foreach ($candidates as $path) {
                $this->remove($path);
                $removed++;
            }
            $this->syncDirectory($destination);

            return $removed;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function remove(string $directory): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
