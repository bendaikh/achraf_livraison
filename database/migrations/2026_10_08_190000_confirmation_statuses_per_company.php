<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company confirmation statuses.
 *
 * Additive: new nullable columns and a backfill. The only index change replaces the
 * global unique on `code` with a composite unique (`company_id`, `code`). Every existing
 * row is kept and copied to the other companies, so order codes and history still resolve.
 * Safe to run once (index names are checked). Does not delete statuses, orders or history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('confirmation_statuses', function (Blueprint $table) {
            if (! Schema::hasColumn('confirmation_statuses', 'company_id')) {
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            }
            if (! Schema::hasColumn('confirmation_statuses', 'category')) {
                $table->string('category', 32)->nullable();
            }
            if (! Schema::hasColumn('confirmation_statuses', 'stays_in_queue')) {
                $table->boolean('stays_in_queue')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'counts_as_confirmed')) {
                $table->boolean('counts_as_confirmed')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'counts_as_failure')) {
                $table->boolean('counts_as_failure')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'requires_recall_date')) {
                $table->boolean('requires_recall_date')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'requires_time')) {
                $table->boolean('requires_time')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'requires_reason')) {
                $table->boolean('requires_reason')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'requires_comment')) {
                $table->boolean('requires_comment')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'requires_product')) {
                $table->boolean('requires_product')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'is_final')) {
                $table->boolean('is_final')->default(false);
            }
            if (! Schema::hasColumn('confirmation_statuses', 'reason_options')) {
                $table->json('reason_options')->nullable();
            }
            if (! Schema::hasColumn('confirmation_statuses', 'is_system')) {
                $table->boolean('is_system')->default(false);
            }
        });

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'confirmation_note')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('confirmation_note')->nullable();
            });
        }

        $defaultId = DB::table('companies')->orderBy('id')->value('id');
        if ($defaultId) {
            $this->backfillExisting((int) $defaultId);
            $this->dropCodeUnique();
            $this->copyToOtherCompanies((int) $defaultId);
        } else {
            $this->dropCodeUnique();
        }

        $this->addCompanyCodeUnique();
    }

    public function down(): void
    {
        $this->dropCompanyCodeUnique();

        if (Schema::hasColumn('orders', 'confirmation_note')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('confirmation_note');
            });
        }

        Schema::table('confirmation_statuses', function (Blueprint $table) {
            if (Schema::hasColumn('confirmation_statuses', 'company_id')) {
                $table->dropConstrainedForeignId('company_id');
            }
        });

        $drop = array_values(array_filter([
            'category', 'stays_in_queue', 'counts_as_confirmed', 'counts_as_failure',
            'requires_recall_date', 'requires_time', 'requires_reason', 'requires_comment',
            'requires_product', 'is_final', 'reason_options', 'is_system',
        ], fn (string $column) => Schema::hasColumn('confirmation_statuses', $column)));

        if ($drop !== []) {
            Schema::table('confirmation_statuses', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }

    private function backfillExisting(int $defaultId): void
    {
        $definitions = \App\Services\Confirmation\ConfirmationStatusProvisioner::definitions();
        $byCode = [];
        foreach ($definitions as $definition) {
            $byCode[$definition['code']] = $definition;
        }

        DB::table('confirmation_statuses')->whereNull('company_id')->orderBy('id')->chunkById(100, function ($rows) use ($defaultId, $byCode) {
            foreach ($rows as $row) {
                $definition = $byCode[$row->code] ?? null;
                if ($definition) {
                    $payload = [
                        'company_id' => $defaultId,
                        'category' => $definition['category'],
                        'is_system' => true,
                        'stays_in_queue' => $definition['stays_in_queue'],
                        'counts_as_confirmed' => $definition['counts_as_confirmed'],
                        'counts_as_failure' => $definition['counts_as_failure'],
                        'requires_recall_date' => $definition['requires_recall_date'],
                        'requires_time' => $definition['requires_time'],
                        'requires_reason' => $definition['requires_reason'],
                        'requires_comment' => $definition['requires_comment'],
                        'requires_product' => $definition['requires_product'],
                        'is_final' => $definition['is_final'],
                        'is_terminal' => $definition['is_final'],
                        'type' => $definition['type'],
                        'queue_behavior' => $definition['queue_behavior'],
                    ];
                } else {
                    // Keep the legacy type and queue_behavior columns. Flags are derived from them
                    // so a later save does not silently change what the status already did.
                    $payload = $this->flagsFromLegacy($row);
                    $payload['company_id'] = $defaultId;
                    $payload['is_system'] = false;
                }
                DB::table('confirmation_statuses')->where('id', $row->id)->update($payload);
            }
        });
    }

    /**
     * Custom statuses created before categories existed. Does not write type or queue_behavior.
     *
     * @return array<string, mixed>
     */
    private function flagsFromLegacy(object $row): array
    {
        $type = (string) ($row->type ?? '');
        $queue = $row->queue_behavior;
        $category = 'personnalise';
        $locked = false;
        $stays = false;
        $confirmed = false;
        $failure = false;
        $recall = false;

        if ($type === 'success') {
            $category = 'confirmee';
            $confirmed = true;
            $locked = true;
        } elseif ($type === 'cancelled') {
            $category = 'annulee_echec';
            $failure = true;
            $locked = true;
        } elseif ($type === 'open') {
            $category = 'en_attente';
        }

        if ($queue === 'future_only') {
            if (! $locked) {
                $category = 'a_recontacter';
            }
            $recall = true;
            $stays = false;
        } elseif ($queue === 'due_queue' || (bool) $row->is_default) {
            if (! $locked) {
                $category = 'en_attente';
            }
            $stays = true;
        } elseif ($type === 'waiting' && ! $locked) {
            $category = 'personnalise';
        }

        $terminal = (bool) $row->is_terminal;

        return [
            'category' => $category,
            'stays_in_queue' => $stays,
            'counts_as_confirmed' => $confirmed,
            'counts_as_failure' => $failure,
            'requires_recall_date' => $recall,
            'requires_time' => false,
            'requires_reason' => false,
            'requires_comment' => false,
            'requires_product' => false,
            'is_final' => $terminal,
            'is_terminal' => $terminal,
        ];
    }

    private function copyToOtherCompanies(int $defaultId): void
    {
        $rows = DB::table('confirmation_statuses')->where('company_id', $defaultId)->orderBy('id')->get();
        $others = DB::table('companies')->where('id', '!=', $defaultId)->orderBy('id')->pluck('id');

        foreach ($others as $companyId) {
            foreach ($rows as $row) {
                $exists = DB::table('confirmation_statuses')
                    ->where('company_id', $companyId)
                    ->where('code', $row->code)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $insert = (array) $row;
                unset($insert['id']);
                $insert['company_id'] = $companyId;
                $insert['created_at'] = now();
                $insert['updated_at'] = now();
                DB::table('confirmation_statuses')->insert($insert);
            }
        }
    }

    private function dropCodeUnique(): void
    {
        foreach ($this->indexes() as $index) {
            $columns = array_values($index['columns'] ?? []);
            if ($this->isUnique($index) && ! $this->isPrimary($index) && $columns === ['code']) {
                Schema::table('confirmation_statuses', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index['name']);
                });
            }
        }
    }

    private function addCompanyCodeUnique(): void
    {
        foreach ($this->indexes() as $index) {
            $columns = array_values($index['columns'] ?? []);
            if ($this->isUnique($index) && $columns === ['company_id', 'code']) {
                return;
            }
        }

        Schema::table('confirmation_statuses', function (Blueprint $table) {
            $table->unique(['company_id', 'code']);
        });
    }

    private function dropCompanyCodeUnique(): void
    {
        if (! Schema::hasTable('confirmation_statuses')) {
            return;
        }
        foreach ($this->indexes() as $index) {
            $columns = array_values($index['columns'] ?? []);
            if ($this->isUnique($index) && ! $this->isPrimary($index) && $columns === ['company_id', 'code']) {
                Schema::table('confirmation_statuses', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index['name']);
                });
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function indexes(): array
    {
        if (! Schema::hasTable('confirmation_statuses')) {
            return [];
        }

        return Schema::getIndexes('confirmation_statuses');
    }

    private function isUnique(array $index): bool
    {
        return (bool) ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
    }

    private function isPrimary(array $index): bool
    {
        return (bool) ($index['primary'] ?? false) || ($index['name'] ?? '') === 'primary';
    }
};
