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
}
