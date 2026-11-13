<?php

namespace App\Console\Commands;

use App\Models\DigitalKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillDigitalKeyFingerprints extends Command
{
    protected $signature = 'digital-keys:backfill-fingerprints {--allow-decryption : Explicitly authorize reading existing encrypted keys} {--dry-run : Validate without writing fingerprints}';

    protected $description = 'Backfill nullable HMAC fingerprints without changing ciphertext or timestamps';

    public function handle(): int
    {
        if (! $this->option('allow-decryption')) {
            $this->error('Explicit authorization is required: --allow-decryption. No keys were read.');

            return self::FAILURE;
        }
        try {
            DigitalKey::fingerprint('configuration-check');
            $result = DB::transaction(function (): array {
                // Keep validation and writes on one stable dataset, including concurrent imports.
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('LOCK TABLE digital_keys IN SHARE ROW EXCLUSIVE MODE');
                }
                $counts = [];
                $missing = [];
                $checked = 0;
                $mismatched = 0;
                foreach (DigitalKey::query()->select(['id', 'key_value', 'key_fingerprint'])->orderBy('id')->lockForUpdate()->lazyById(200) as $key) {
                    // Use the existing encrypted cast; plaintext never leaves application memory.
                    $fingerprint = DigitalKey::fingerprint($key->key_value);
                    $counts[$fingerprint] = ($counts[$fingerprint] ?? 0) + 1;
                    if ($key->key_fingerprint === null) {
                        $missing[$key->id] = $fingerprint;
                    } elseif (! hash_equals($fingerprint, $key->key_fingerprint)) {
                        $mismatched++;
                    }
                    $checked++;
                }
                $conflicts = array_sum(array_filter($counts, fn (int $count): bool => $count > 1));
                if ($conflicts > 0 || $mismatched > 0) {
                    return compact('checked', 'conflicts', 'mismatched') + ['written' => 0];
                }
                $written = 0;
                if (! $this->option('dry-run')) {
                    foreach ($missing as $id => $fingerprint) {
                        $written += DB::table('digital_keys')->where('id', $id)->whereNull('key_fingerprint')->update(['key_fingerprint' => $fingerprint]);
                    }
                }

                return compact('checked', 'conflicts', 'mismatched', 'written');
            });
        } catch (Throwable) {
            // Never render SQL/bindings, ciphertext, plaintext, fingerprints or secrets.
            $this->error('Backfill aborted and rolled back. Check the separate secret, encryption configuration and duplicate key content.');

            return self::FAILURE;
        }
        $this->info('Checked: '.$result['checked'].'; conflicting records: '.$result['conflicts'].'; mismatched fingerprints: '.$result['mismatched'].'; fingerprints written: '.$result['written']);
        if ($result['conflicts'] > 0 || $result['mismatched'] > 0) {
            $this->error('No records changed. Resolve conflicts or verify the configured secret before retrying.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
