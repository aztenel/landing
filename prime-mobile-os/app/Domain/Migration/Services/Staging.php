<?php

namespace App\Domain\Migration\Services;

use App\Domain\Migration\Models\ImportRow;
use App\Domain\Migration\Models\ImportRun;
use App\Models\User;

/**
 * Raw rows of the import (TZ 07 §1.4): repeatable — the same file or API answer updates rows by sfon_key and never
 * creates duplicates; a row already put into the posted ВХО is not touched.
 */
final class Staging
{
    public function run(string $source, string $kind, ?User $user, ?string $file = null, ?array $columns = null): ImportRun
    {
        return ImportRun::query()->create(['source' => $source, 'kind' => $kind, 'file_name' => $file, 'columns' => $columns, 'user_id' => $user?->id, 'created_at' => now()]);
    }

    public function put(ImportRun $run, string $kind, string $key, array $data, array $fields = [], ?string $error = null): ImportRow
    {
        $row = ImportRow::query()->firstOrNew(['kind' => $kind, 'sfon_key' => mb_substr($key, 0, 120)]);
        if ($row->exists && $row->status === 'in_vxo') {
            return $row;
        }
        $keep = $row->exists ? array_intersect_key((array) $row->fields, array_flip(['cost', 'cost_set_by', 'variant_id_manual'])) : [];
        $row->fill(['run_id' => $run->id, 'data' => $data, 'fields' => $fields + $keep, 'status' => $error ? 'error' : 'matched', 'error' => $error, 'updated_at' => now()]);
        $row->save();

        return $row;
    }

    public function finish(ImportRun $run, array $totals, ?string $error = null): ImportRun
    {
        $run->update(['totals' => $totals, 'status' => $error ? 'failed' : 'done', 'error' => $error]);

        return $run;
    }
}
