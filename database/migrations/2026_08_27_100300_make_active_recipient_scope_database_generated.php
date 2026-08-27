<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'compliance_alert_recipients_active_scope_unique';

    public function up(): void
    {
        $this->dropScopeTriggers();
        if ($this->indexExists(self::UNIQUE_INDEX)) {
            Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }
        if (Schema::hasColumn('compliance_alert_recipients', 'active_scope_key')) {
            Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
                $table->dropColumn('active_scope_key');
            });
        }

        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->string('active_scope_key', 191)->nullable();
        });

        $this->backfillScopeKeys();

        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->unique('active_scope_key', self::UNIQUE_INDEX);
        });

        $this->createScopeTriggers();
    }

    public function down(): void
    {
        $this->dropScopeTriggers();
        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->unique('active_scope_key', self::UNIQUE_INDEX);
        });
    }

    private function backfillScopeKeys(): void
    {
        foreach (DB::table('compliance_alert_recipients')->get() as $recipient) {
            DB::table('compliance_alert_recipients')->where('id', $recipient->id)->update([
                'active_scope_key' => $this->scopeKey($recipient),
            ]);
        }
    }

    private function scopeKey(object $recipient): ?string
    {
        if (! $recipient->is_active) {
            return null;
        }
        if ($recipient->protected_area_id) {
            return 'pa:'.(int) $recipient->protected_area_id;
        }

        $office = mb_strtolower(trim((string) $recipient->target_office));

        return $office === '' ? null : 'office:'.$office;
    }

    private function createScopeTriggers(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER compliance_recipient_scope_insert AFTER INSERT ON compliance_alert_recipients BEGIN UPDATE compliance_alert_recipients SET active_scope_key = CASE WHEN NEW.is_active = 1 THEN CASE WHEN NEW.protected_area_id IS NOT NULL THEN 'pa:' || NEW.protected_area_id ELSE 'office:' || lower(trim(NEW.target_office)) END ELSE NULL END WHERE id = NEW.id; END");
            DB::unprepared("CREATE TRIGGER compliance_recipient_scope_update AFTER UPDATE OF is_active, protected_area_id, target_office ON compliance_alert_recipients BEGIN UPDATE compliance_alert_recipients SET active_scope_key = CASE WHEN NEW.is_active = 1 THEN CASE WHEN NEW.protected_area_id IS NOT NULL THEN 'pa:' || NEW.protected_area_id ELSE 'office:' || lower(trim(NEW.target_office)) END ELSE NULL END WHERE id = NEW.id; END");
            return;
        }

        DB::unprepared("CREATE TRIGGER compliance_recipient_scope_insert BEFORE INSERT ON compliance_alert_recipients FOR EACH ROW SET NEW.active_scope_key = CASE WHEN NEW.is_active = 1 THEN CASE WHEN NEW.protected_area_id IS NOT NULL THEN CONCAT('pa:', NEW.protected_area_id) ELSE CONCAT('office:', LOWER(TRIM(NEW.target_office))) END ELSE NULL END");
        DB::unprepared("CREATE TRIGGER compliance_recipient_scope_update BEFORE UPDATE ON compliance_alert_recipients FOR EACH ROW SET NEW.active_scope_key = CASE WHEN NEW.is_active = 1 THEN CASE WHEN NEW.protected_area_id IS NOT NULL THEN CONCAT('pa:', NEW.protected_area_id) ELSE CONCAT('office:', LOWER(TRIM(NEW.target_office))) END ELSE NULL END");
    }

    private function dropScopeTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS compliance_recipient_scope_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS compliance_recipient_scope_update');
    }

    private function indexExists(string $name): bool
    {
        return collect(Schema::getIndexes('compliance_alert_recipients'))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $name,
        );
    }
};
