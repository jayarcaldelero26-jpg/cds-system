<?php

namespace App\Services;

use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Support\Facades\Gate;

/** Read-only, permission-aware search across eDATS resources. */
final class GlobalSearchService
{
    private const PER_GROUP_LIMIT = 5;

    public function __construct(private readonly SubmissionTrackingService $tracking) {}

    /** @return array{groups: list<array{key: string, label: string, results: list<array<string, string>>}>, total: int} */
    public function search(User $user, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return ['groups' => [], 'total' => 0];

        $groups = collect([
            $this->group('navigation', 'Navigation', $this->navigation($user, $query)),
            $this->group('reports', 'Reports', $this->reports($user, $query)),
            $this->group('protected_areas', 'Protected Areas', $this->protectedAreas($user, $query)),
            $this->group('users', 'Users', $this->users($user, $query)),
        ])->filter()->values();

        return ['groups' => $groups->all(), 'total' => $groups->sum(fn (array $group): int => count($group['results']))];
    }

    /** @param list<array<string, string>> $results */
    private function group(string $key, string $label, array $results): ?array
    {
        return $results === [] ? null : ['key' => $key, 'label' => $label, 'results' => $results];
    }

    /** @return list<array<string, string>> */
    private function navigation(User $user, string $query): array
    {
        $items = [
            ['title' => 'eDATS Monitoring Dashboard', 'subtitle' => 'Conservation and ENGP monitoring', 'url' => '/dashboard', 'icon' => 'chart'],
            ['title' => 'Protected Areas', 'subtitle' => 'Protected area profiles and baselines', 'url' => '/protected-areas', 'icon' => 'map', 'ability' => 'protected-areas.view'],
            ['title' => 'Submission Tracking', 'subtitle' => 'Report routing and receipt monitoring', 'url' => '/submission-tracking', 'icon' => 'document', 'ability' => 'reports.view'],
            ['title' => 'Alerts', 'subtitle' => 'Compliance requirements and deadlines', 'url' => '/compliance-alerts', 'icon' => 'bell', 'ability' => 'reports.view'],
            ['title' => 'Calendar', 'subtitle' => 'Business calendar and non-working days', 'url' => '/admin/business-calendar', 'icon' => 'calendar', 'ability' => 'reports.view'],
            ['title' => 'BMS', 'subtitle' => 'Biodiversity Monitoring System', 'url' => '/bms', 'icon' => 'leaf', 'ability' => 'bms.view'],
            ['title' => 'BAMS', 'subtitle' => 'Biodiversity Assessment and Monitoring System', 'url' => '/bams', 'icon' => 'leaf', 'ability' => 'bams.view'],
            ['title' => 'IMEA', 'subtitle' => 'Integrated Management Effectiveness Assessment', 'url' => '/imea', 'icon' => 'chart', 'ability' => 'imea.view'],
            ['title' => 'AWS', 'subtitle' => 'Automated Weather Station monitoring', 'url' => '/aws', 'icon' => 'cloud', 'ability' => 'aws.view'],
            ['title' => 'IPAF', 'subtitle' => 'Integrated Protected Area Fund monitoring', 'url' => '/ipaf', 'icon' => 'document', 'ability' => 'technical-reports.view'],
            ['title' => 'Management of IPAF', 'subtitle' => 'IPAF management reports', 'url' => '/ipaf?ipaf_tab=management', 'icon' => 'document', 'ability' => 'technical-reports.view'],
            ['title' => 'Revenue Collection', 'subtitle' => 'IPAF revenue collection monitoring', 'url' => '/ipaf?ipaf_tab=revenue', 'icon' => 'document', 'ability' => 'technical-reports.view'],
            ['title' => 'ENGP Summary Monitoring', 'subtitle' => 'National Greening Program', 'url' => '/engp-reports/summary', 'icon' => 'chart', 'ability' => 'technical-reports.view'],
            ['title' => 'CBEP', 'subtitle' => 'ENGP monthly report workflow', 'url' => '/engp-reports/cbep', 'icon' => 'document', 'ability' => 'technical-reports.view'],
            ['title' => 'ELCAC', 'subtitle' => 'ENGP monthly report workflow', 'url' => '/engp-reports/elcac', 'icon' => 'document', 'ability' => 'technical-reports.view'],
            ['title' => 'RIMS', 'subtitle' => 'ENGP monthly report workflow', 'url' => '/engp-reports/rims', 'icon' => 'document', 'ability' => 'technical-reports.view'],
            ['title' => 'ENGP Weekly Accomplishment', 'subtitle' => 'ENGP weekly report workflow', 'url' => route('engp-reports.index', ['workflow' => 'weekly_accomplishment']), 'icon' => 'document', 'ability' => 'technical-reports.view'],
        ];

        return collect($items)->filter(fn (array $item): bool => !isset($item['ability']) || $user->can($item['ability']))
            ->filter(fn (array $item): bool => $this->matches($query, $item['title'].' '.$item['subtitle']))
            ->take(self::PER_GROUP_LIMIT)->map(fn (array $item): array => [
                'type' => 'navigation', 'title' => $item['title'], 'subtitle' => $item['subtitle'], 'url' => $item['url'], 'icon' => $item['icon'], 'badge' => 'Navigation',
            ])->values()->all();
    }

    /** @return list<array<string, string>> */
    private function reports(User $user, string $query): array
    {
        return $this->tracking->records()
            ->filter(fn (array $record): bool => $this->canViewReport($user, (string) ($record['source'] ?? '')))
            ->filter(fn (array $record): bool => $this->matches($query, implode(' ', [$record['module'] ?? '', $record['target_office'] ?? '', $record['protected_area'] ?? '', $record['reporting_period'] ?? '', $record['activity_name'] ?? '', $record['document_type'] ?? ''])))
            ->take(self::PER_GROUP_LIMIT)->map(function (array $record): array {
                $context = collect([$record['target_office'] ?? null, $record['protected_area'] ?? null])->filter()->implode(' · ');
                $period = trim((string) ($record['reporting_period'] ?? ''));
                return [
                    'type' => 'report', 'title' => (string) ($record['module'] ?? 'Monitored report'),
                    'subtitle' => trim($context.($period !== '' ? ($context !== '' ? ' · ' : '').$period : '')) ?: 'Monitored report',
                    'url' => (string) ($record['source_url'] ?? '/dashboard'), 'icon' => 'document', 'badge' => 'Report',
                ];
            })->values()->all();
    }

    /** @return list<array<string, string>> */
    private function protectedAreas(User $user, string $query): array
    {
        if (!$user->can('protected-areas.view')) return [];
        return ProtectedArea::query()->where(function ($builder) use ($query): void {
            $like = '%'.$query.'%';
            $builder->where('name', 'like', $like)->orWhere('category', 'like', $like)->orWhere('municipality', 'like', $like)->orWhere('pamo', 'like', $like)->orWhere('pasu', 'like', $like);
        })->orderBy('name')->limit(self::PER_GROUP_LIMIT)->get(['name', 'category', 'municipality'])
            ->map(fn (ProtectedArea $area): array => [
                'type' => 'protected_area', 'title' => (string) $area->name,
                'subtitle' => collect([$area->category, $area->municipality])->filter()->implode(' · ') ?: 'Protected Area',
                'url' => '/protected-areas?search='.rawurlencode((string) $area->name), 'icon' => 'map', 'badge' => 'Protected Area',
            ])->all();
    }

    /** @return list<array<string, string>> */
    private function users(User $user, string $query): array
    {
        if (!Gate::forUser($user)->allows('viewAny', User::class)) return [];
        return User::query()->with('roles:name')->where(function ($builder) use ($query): void {
            $like = '%'.$query.'%';
            $builder->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('office_designated', 'like', $like)->orWhere('section', 'like', $like);
        })->orderBy('name')->limit(self::PER_GROUP_LIMIT)->get(['id', 'name', 'office_designated', 'section'])
            ->map(function (User $managedUser): array {
                $context = collect([$managedUser->office_designated, $managedUser->section, $managedUser->roles->first()?->name])->filter()->implode(' · ');
                return ['type' => 'user', 'title' => (string) $managedUser->name, 'subtitle' => $context ?: 'User account', 'url' => '/admin/users/'.$managedUser->id.'/edit', 'icon' => 'user', 'badge' => 'User'];
            })->all();
    }

    private function canViewReport(User $user, string $source): bool
    {
        return match ($source) {
            'bms' => $user->can('bms.view'), 'bams' => $user->can('bams.view'), 'imea' => $user->can('imea.view'), 'aws' => $user->can('aws.view'),
            'engp', 'conservation', 'ipaf-management' => $user->can('technical-reports.view'), default => false,
        };
    }

    private function matches(string $needle, string $haystack): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }
}
