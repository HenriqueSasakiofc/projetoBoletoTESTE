<?php
namespace App\Services;

use App\Models\UploadBatch;

class ImportStorageService
{
    private const BASE_DIRECTORY = __DIR__ . '/../../storage/imports';

    private static function validateKind(string $kind): void
    {
        if (!in_array($kind, ['customers', 'receivables'], true)) {
            throw new \InvalidArgumentException('Tipo de arquivo de importacao invalido.');
        }
    }

    private static function normalizeExtension(string $filename): string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension);

        return $extension !== '' ? $extension : 'bin';
    }

    private static function companyDirectory(int $companyId): string
    {
        return self::BASE_DIRECTORY . DIRECTORY_SEPARATOR . 'company_' . $companyId;
    }

    private static function buildStoredPath(int $companyId, string $kind, string $hash, string $filename): string
    {
        self::validateKind($kind);

        $directory = self::companyDirectory($companyId);
        $extension = self::normalizeExtension($filename);

        return $directory . DIRECTORY_SEPARATOR . $kind . '_' . $hash . '.' . $extension;
    }

    public static function getBatchFilePath(UploadBatch $batch, string $kind): ?string
    {
        self::validateKind($kind);

        $hash = $kind === 'customers' ? $batch->customers_hash : $batch->receivables_hash;
        $filename = $kind === 'customers' ? $batch->customers_filename : $batch->receivables_filename;

        if (!$hash || !$filename) {
            return null;
        }

        return self::buildStoredPath((int) $batch->company_id, $kind, (string) $hash, (string) $filename);
    }

    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nao foi possivel preparar o diretorio de importacoes.');
        }
    }

    public static function persistUploadedFile(
        int $companyId,
        string $sourcePath,
        string $hash,
        string $originalFilename,
        string $kind
    ): string {
        $targetPath = self::buildStoredPath($companyId, $kind, $hash, $originalFilename);
        self::ensureDirectory(dirname($targetPath));

        if (!is_file($targetPath)) {
            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Nao foi possivel salvar uma copia da planilha enviada.');
            }
        }

        return $targetPath;
    }

    public static function resolveBatchFile(UploadBatch $batch, string $kind): ?string
    {
        $path = self::getBatchFilePath($batch, $kind);

        return $path && is_file($path) ? $path : null;
    }

    public static function hasStoredFiles(UploadBatch $batch): bool
    {
        return self::resolveBatchFile($batch, 'customers') !== null
            && self::resolveBatchFile($batch, 'receivables') !== null;
    }
}
