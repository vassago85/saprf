<?php

namespace App\Services\Selection;

use App\Models\SelectionCycle;
use App\Models\SelectionPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the JSON selection ruleset into a versioned SelectionPolicy row
 * and marks it active on its cycle. Every import is content-hashed so a
 * re-import of the same file is idempotent, and the cycle keeps every prior
 * version so historical selection decisions can be replayed against the
 * policy that was in force at the time (ADM-04).
 */
class PolicyImportService
{
    /**
     * @throws RuntimeException on missing file / malformed JSON / missing spec_version.
     */
    public function import(string $jsonPath, SelectionCycle $cycle, ?User $importedBy = null): SelectionPolicy
    {
        if (! is_file($jsonPath)) {
            throw new RuntimeException("Policy file not found: {$jsonPath}");
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false || $raw === '') {
            throw new RuntimeException("Policy file empty or unreadable: {$jsonPath}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Policy file is not valid JSON: '.json_last_error_msg());
        }

        $version = $decoded['spec']['spec_version'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new RuntimeException('Policy JSON is missing spec.spec_version');
        }

        $hash = hash('sha256', $raw);

        return DB::transaction(function () use ($cycle, $decoded, $version, $hash, $jsonPath, $importedBy) {
            $policy = SelectionPolicy::updateOrCreate(
                [
                    'selection_cycle_id' => $cycle->id,
                    'version' => $version,
                ],
                [
                    'source_path' => $jsonPath,
                    'source_hash' => $hash,
                    'spec_json' => $decoded,
                    'imported_by' => $importedBy?->id,
                    'imported_at' => now(),
                ],
            );

            $cycle->forceFill(['active_policy_version_id' => $policy->id])->save();

            return $policy;
        });
    }
}
