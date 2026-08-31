<?php

namespace App\Providers;
use Illuminate\Database\Eloquent\Model;
use App\Services\AuditLogService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Modules\ModuleMetadataResolver;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL; // 🚀 KINAHANGLAN I-IMPORT KINI NGA FACADE!

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ModuleMetadataResolver::class, function ($app): ModuleMetadataResolver {
            return new ModuleMetadataResolver(
                $app->make(ConservationReportWorkflowRegistry::class),
                $app->make(EngpReportWorkflowRegistry::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
       if (app()->environment('production')) {
           URL::forceScheme('https');
       }

        $this->registerReportAuditHooks();

        Gate::before(function ($user, $ability, array $arguments) {
            // User deletion has non-bypassable policy invariants (self-delete
            // and last-CDS-Admin protection). Let UserPolicy::delete() decide.
            if ($ability === 'delete' && ($arguments[0] ?? null) instanceof \App\Models\User) {
                return null;
            }

            return $user->hasRole('CDS Admin') ? true : null;
        });
   }

    private function registerReportAuditHooks(): void
    {
        foreach ([
            \App\Models\ConservationReportSubmission::class, \App\Models\EngpReportSubmission::class,
            \App\Models\BmsReportSubmission::class, \App\Models\BamsReportSubmission::class,
            \App\Models\ImeaReportSubmission::class, \App\Models\ImeaFacilityMaintenanceReport::class,
            \App\Models\Aws::class, \App\Models\IpafManagementReport::class,
            \App\Models\IpafRevenueCollection::class, \App\Models\TechnicalReport::class,
            \App\Models\ManagementPlan::class,
        ] as $modelClass) {
            $modelClass::created(fn (Model $model) => $this->recordReportAudit($model, 'created'));
            $modelClass::updated(fn (Model $model) => $this->recordReportAudit($model, 'updated'));
            $modelClass::deleted(fn (Model $model) => $this->recordReportAudit($model, 'deleted'));
        }
    }

    private function recordReportAudit(Model $model, string $event): void
    {
        $changes = collect($model->getChanges())->except(['created_at', 'updated_at', 'updated_by', 'date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional', 'submission_date'])->all();
        if ($event === 'updated' && $changes === []) return;
        $metadata = app(\App\Services\Modules\ModuleMetadataResolver::class)->resolve($model, class_basename($model));
        $label = $metadata['module_name'];
        $attachmentChanged = collect(array_keys($changes))->contains(fn (string $field): bool => str_contains($field, 'attachment') || str_contains($field, 'mov_') || $field === 'report_file_path');
        $action = $event === 'created' ? 'Report Created' : ($event === 'deleted' ? 'Report Deleted' : ($attachmentChanged ? 'Attachment Replaced' : 'Report Updated'));
        $auditMetadata = $event === 'updated' ? ['fields' => array_keys($changes)] : [];
        if ($metadata['program_area'] !== null) {
            $auditMetadata['program_area'] = $metadata['program_area'];
        }
        app(AuditLogService::class)->record('report_management', $action, $model::class, $model->getKey(), $label, $action.' for '.$label.' record.', $auditMetadata);
    }
}
