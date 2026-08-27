<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOOTER = 'This automated reminder concerns reports whose authoritative submission or receipt has not yet been recorded by PENRO. Reminders cease once the report is recorded as received or submitted by PENRO. Records verification is a separate internal audit process.';

    public function up(): void
    {
        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->string('active_scope_key', 191)->nullable()->after('is_active');
        });

        $seen = [];
        foreach (DB::table('compliance_alert_recipients')->orderBy('id')->get() as $recipient) {
            $scope = $recipient->protected_area_id
                ? 'pa:'.(int) $recipient->protected_area_id
                : 'office:'.$this->normaliseOffice($recipient->target_office);

            if (! $recipient->is_active || $scope === 'office:') {
                DB::table('compliance_alert_recipients')->where('id', $recipient->id)->update(['active_scope_key' => null]);
                continue;
            }

            if (isset($seen[$scope])) {
                DB::table('compliance_alert_recipients')->where('id', $recipient->id)->update([
                    'is_active' => false,
                    'active_scope_key' => null,
                    'notes' => trim((string) $recipient->notes."\nAutomatically deactivated while enforcing active recipient scope uniqueness; retained for audit history."),
                    'updated_at' => now(),
                ]);
                continue;
            }

            $seen[$scope] = true;
            DB::table('compliance_alert_recipients')->where('id', $recipient->id)->update(['active_scope_key' => $scope]);
        }

        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->unique('active_scope_key', 'compliance_alert_recipients_active_scope_unique');
        });

        if (! Schema::hasTable('compliance_alert_setting_archives')) {
            Schema::create('compliance_alert_setting_archives', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('original_id');
                $table->json('snapshot');
                $table->string('archive_reason');
                $table->timestamp('archived_at');
            });
        }

        Schema::table('compliance_alert_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('singleton_key')->default(1)->after('id');
        });

        $settingsRows = DB::table('compliance_alert_settings')->orderBy('id')->get();
        foreach ($settingsRows->skip(1) as $row) {
            DB::table('compliance_alert_setting_archives')->insert([
                'original_id' => $row->id,
                'snapshot' => json_encode((array) $row, JSON_THROW_ON_ERROR),
                'archive_reason' => 'Additional settings row archived while enforcing the Compliance Alerts singleton.',
                'archived_at' => now(),
            ]);
            DB::table('compliance_alert_settings')->where('id', $row->id)->delete();
        }

        Schema::table('compliance_alert_settings', function (Blueprint $table): void {
            $table->unique('singleton_key', 'compliance_alert_settings_singleton_unique');
        });

        DB::table('compliance_alert_settings')
            ->whereIn('system_generated_footer_text', [
                'This system-generated notification is sent daily and will stop only once the required submission is completed and confirmed by the Records Officer.',
                'This is a system-generated notification sent every working day. It will cease only once all required submissions are completed and confirmed by the Records Officer.',
            ])
            ->update(['system_generated_footer_text' => self::FOOTER, 'updated_at' => now()]);

        DB::table('compliance_alert_settings')->update(['timezone' => 'Asia/Manila']);
    }

    public function down(): void
    {
        Schema::table('compliance_alert_settings', function (Blueprint $table): void {
            $table->dropUnique('compliance_alert_settings_singleton_unique');
            $table->dropColumn('singleton_key');
        });
        Schema::dropIfExists('compliance_alert_setting_archives');

        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->dropUnique('compliance_alert_recipients_active_scope_unique');
            $table->dropColumn('active_scope_key');
        });
    }

    private function normaliseOffice(?string $office): string
    {
        return mb_strtolower(trim((string) $office));
    }
};
