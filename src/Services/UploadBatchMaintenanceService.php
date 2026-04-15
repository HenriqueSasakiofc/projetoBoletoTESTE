<?php
namespace App\Services;

use App\Models\OutboxMessage;
use App\Models\Receivable;
use App\Models\StagingCustomer;
use App\Models\StagingReceivable;
use App\Models\UploadBatch;
use Illuminate\Database\Capsule\Manager as Capsule;

class UploadBatchMaintenanceService
{
    private const DEFAULT_IMPORT_FILE_RETENTION_DAYS = 30;

    public static function importFileRetentionDays(): int
    {
        $value = $_ENV['IMPORT_FILE_RETENTION_DAYS'] ?? $_SERVER['IMPORT_FILE_RETENTION_DAYS'] ?? null;

        if ($value === null || $value === '') {
            return self::DEFAULT_IMPORT_FILE_RETENTION_DAYS;
        }

        return max(1, (int) $value);
    }

    public static function buildPurgeSummary(UploadBatch $seedBatch): array
    {
        $matchingBatches = UploadBatch::where('company_id', $seedBatch->company_id)
            ->where('customers_hash', $seedBatch->customers_hash)
            ->where('receivables_hash', $seedBatch->receivables_hash)
            ->orderBy('id')
            ->get();

        $batchIds = $matchingBatches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $receivableIds = empty($batchIds)
            ? []
            : Receivable::whereIn('upload_batch_id', $batchIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        $standardOutboxCount = empty($receivableIds)
            ? 0
            : OutboxMessage::whereIn('receivable_id', $receivableIds)->count();

        return [
            'company_id' => (int) $seedBatch->company_id,
            'source_batch_id' => (int) $seedBatch->id,
            'customers_hash' => (string) $seedBatch->customers_hash,
            'receivables_hash' => (string) $seedBatch->receivables_hash,
            'matching_batch_ids' => $batchIds,
            'matching_batches_count' => count($batchIds),
            'receivables_to_remove' => count($receivableIds),
            'standard_outbox_to_remove' => (int) $standardOutboxCount,
            'staging_customers_to_remove' => empty($batchIds)
                ? 0
                : (int) StagingCustomer::whereIn('upload_batch_id', $batchIds)->count(),
            'staging_receivables_to_remove' => empty($batchIds)
                ? 0
                : (int) StagingReceivable::whereIn('upload_batch_id', $batchIds)->count(),
        ];
    }

    public static function purgeMatchingBatches(UploadBatch $seedBatch): array
    {
        $summary = self::buildPurgeSummary($seedBatch);
        $batchIds = $summary['matching_batch_ids'];

        Capsule::connection()->transaction(function () use ($batchIds) {
            if (empty($batchIds)) {
                return;
            }

            $receivableIds = Receivable::whereIn('upload_batch_id', $batchIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (!empty($receivableIds)) {
                OutboxMessage::whereIn('receivable_id', $receivableIds)->delete();
                Receivable::whereIn('id', $receivableIds)->delete();
            }

            StagingCustomer::whereIn('upload_batch_id', $batchIds)->delete();
            StagingReceivable::whereIn('upload_batch_id', $batchIds)->delete();

            UploadBatch::whereIn('id', $batchIds)->update([
                'status' => 'purged',
                'error_message' => 'Batch purgado por rotina de limpeza para permitir reimportacao limpa.',
            ]);
        });

        $summary['dry_run'] = false;

        return $summary;
    }

    public static function cleanupStoredImportFiles(?int $retentionDays = null, bool $dryRun = false): array
    {
        $retentionDays = $retentionDays !== null ? max(1, $retentionDays) : self::importFileRetentionDays();
        $cutoff = (new \DateTimeImmutable('now'))->modify("-{$retentionDays} days");

        $expiredBatches = UploadBatch::where('created_at', '<', $cutoff->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get();

        $seenPaths = [];
        $files = [];

        foreach ($expiredBatches as $batch) {
            foreach (['customers', 'receivables'] as $kind) {
                $path = ImportStorageService::getBatchFilePath($batch, $kind);
                if (!$path || isset($seenPaths[$path])) {
                    continue;
                }

                $seenPaths[$path] = true;
                $hashColumn = $kind === 'customers' ? 'customers_hash' : 'receivables_hash';
                $filenameColumn = $kind === 'customers' ? 'customers_filename' : 'receivables_filename';

                $hasRecentReference = UploadBatch::where('company_id', $batch->company_id)
                    ->where($hashColumn, $batch->{$hashColumn})
                    ->where($filenameColumn, $batch->{$filenameColumn})
                    ->where('created_at', '>=', $cutoff->format('Y-m-d H:i:s'))
                    ->exists();

                $exists = is_file($path);
                $deleted = false;
                $error = null;

                if ($exists && !$hasRecentReference && !$dryRun) {
                    $deleted = @unlink($path);
                    if (!$deleted) {
                        $error = 'Nao foi possivel excluir o arquivo.';
                    }
                }

                $files[] = [
                    'batch_id' => (int) $batch->id,
                    'company_id' => (int) $batch->company_id,
                    'kind' => $kind,
                    'path' => $path,
                    'exists' => $exists,
                    'kept_because_recent_reference' => $hasRecentReference,
                    'deleted' => $dryRun ? false : $deleted,
                    'would_delete' => $dryRun && $exists && !$hasRecentReference,
                    'error' => $error,
                ];
            }
        }

        return [
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'dry_run' => $dryRun,
            'expired_batches_count' => $expiredBatches->count(),
            'files_found' => count(array_filter($files, fn ($file) => $file['exists'])),
            'files_deleted' => count(array_filter($files, fn ($file) => $file['deleted'])),
            'files_kept_by_recent_reference' => count(array_filter($files, fn ($file) => $file['kept_because_recent_reference'])),
            'errors_count' => count(array_filter($files, fn ($file) => $file['error'] !== null)),
            'files' => $files,
        ];
    }
}
