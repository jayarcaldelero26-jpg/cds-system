<?php

namespace Tests\Unit;

use App\Services\OfficeTatComplianceAssessmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OfficeTatComplianceMetricsTest extends TestCase
{
    private OfficeTatComplianceAssessmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName(), 'Office TAT metric tests require an isolated SQLite database.');
        self::assertSame(':memory:', DB::connection()->getDatabaseName(), 'Office TAT metric tests must never use the ordinary database.');
        $this->service = app(OfficeTatComplianceAssessmentService::class);
    }

    public function test_source_formula_boundaries_misses_no_due_and_invalid_evidence(): void
    {
        $cutoff = CarbonImmutable::parse('2026-10-20', 'Asia/Manila');

        $zeroDuration = $this->score([$this->obligation('2026-10-01', '2026-10-11', '2026-10-01')], $cutoff);
        self::assertSame('Scored', $zeroDuration['score_status']);
        self::assertSame(1, $zeroDuration['due_count']);
        self::assertSame(1, $zeroDuration['on_time_count']);
        self::assertSame(1, $zeroDuration['early_count']);
        self::assertSame(100.0, $zeroDuration['se_percent']);

        $deadlineBoundary = $this->score([$this->obligation('2026-10-01', '2026-10-11', '2026-10-11')], $cutoff);
        self::assertSame(1, $deadlineBoundary['on_time_count']);
        self::assertSame(0, $deadlineBoundary['early_count']);
        self::assertSame(0.0, $deadlineBoundary['se_percent']);

        $late = $this->score([$this->obligation('2026-10-01', '2026-10-11', '2026-10-15')], $cutoff);
        self::assertSame(0, $late['on_time_count']);
        self::assertSame(0, $late['early_count']);
        self::assertSame(0.0, $late['se_percent']);
        self::assertSame(1, $late['volume_count'], 'A valid late receipt counts once as verified volume.');

        $missed = $this->score([$this->obligation('2026-10-01', '2026-10-11', null)], $cutoff);
        self::assertSame('Scored', $missed['score_status']);
        self::assertSame(1, $missed['due_count']);
        self::assertSame(0, $missed['submitted_count']);
        self::assertSame(0.0, $missed['se_percent'], 'A verified missed obligation contributes zero while remaining in N.');

        $unfinishedDraft = $this->obligation('2026-10-01', '2026-10-11', null);
        $unfinishedDraft['record_id'] = 77;
        $unfinishedDraft['source_deadline'] = '2026-10-11';
        $draftScore = $this->score([$unfinishedDraft], $cutoff);
        self::assertSame('Scored', $draftScore['score_status']);
        self::assertSame(0, $draftScore['submitted_count'], 'An unfinished draft row is not an accepted receipt.');
        self::assertSame(0, $draftScore['volume_count']);

        $future = $this->score([$this->obligation('2026-10-01', '2026-10-21', '2026-10-15')], CarbonImmutable::parse('2026-10-20', 'Asia/Manila'));
        self::assertSame('No due reports', $future['score_status']);
        self::assertNull($future['tc_percent']);
        self::assertNull($future['se_percent']);
        self::assertSame(1, $future['volume_count']);

        $invalidPeriod = $this->score([$this->obligation('2026-10-11', '2026-10-11', '2026-10-11')], $cutoff);
        self::assertSame('Coverage incomplete', $invalidPeriod['score_status']);
        self::assertNull($invalidPeriod['se_percent']);

        $negativeDuration = $this->score([$this->obligation('2026-10-01', '2026-10-11', '2026-09-30')], $cutoff);
        self::assertSame('Coverage incomplete', $negativeDuration['score_status']);
        self::assertNotEmpty($negativeDuration['score_issues']);

        $unknownHistorical = $this->score([$this->obligation('2026-01-01', '2026-06-30', null)], CarbonImmutable::parse('2026-12-31', 'Asia/Manila'));
        self::assertSame('Coverage incomplete', $unknownHistorical['score_status']);
        self::assertStringContainsString('historical submission coverage is unverified', implode(' ', $unknownHistorical['score_issues']));

        $historicalRecord = $this->obligation('2026-10-01', '2026-10-11', '2026-10-05');
        $historicalRecord['historical'] = true;
        $historicalScore = $this->score([$historicalRecord], $cutoff);
        self::assertSame('Coverage incomplete', $historicalScore['score_status']);
        self::assertStringContainsString('historical source evidence is separated', implode(' ', $historicalScore['score_issues']));
    }

    public function test_three_on_time_reports_and_one_approved_miss_score_seventy_five_but_unknown_evidence_withholds_the_score(): void
    {
        $cutoff = CarbonImmutable::parse('2026-06-30', 'Asia/Manila');
        $obligations = [
            $this->obligation('2026-01-01', '2026-01-31', '2026-01-10'),
            $this->obligation('2026-02-01', '2026-02-28', '2026-02-10'),
            $this->obligation('2026-03-01', '2026-03-31', '2026-03-10'),
            $this->obligation('2026-04-01', '2026-04-30', null),
        ];
        $obligations[3]['nonreceipt_verified'] = true;
        $obligations[3]['nonreceipt_register_id'] = 91;

        $approved = $this->score($obligations, $cutoff);
        self::assertSame('Scored', $approved['score_status']);
        self::assertSame(4, $approved['due_count']);
        self::assertSame(3, $approved['on_time_count']);
        self::assertEquals(75.0, $approved['tc_percent']);

        $obligations[3]['nonreceipt_verified'] = false;
        $obligations[3]['nonreceipt_register_id'] = null;
        $unknown = $this->score($obligations, $cutoff);
        self::assertSame('Coverage incomplete', $unknown['score_status']);
        self::assertNull($unknown['tc_percent']);
    }

    public function test_pooled_unit_math_weights_each_due_obligation_not_each_office(): void
    {
        $cutoff = CarbonImmutable::parse('2026-10-11', 'Asia/Manila');
        $firstOffice = $this->score([
            $this->obligation('2026-10-01', '2026-10-11', '2026-10-05'),
            $this->obligation('2026-10-01', '2026-10-11', '2026-10-05'),
        ], $cutoff);
        $secondOfficeObligations = [];
        for ($i = 0; $i < 4; $i++) $secondOfficeObligations[] = $this->obligation('2026-10-01', '2026-10-11', '2026-10-05');
        for ($i = 0; $i < 4; $i++) $secondOfficeObligations[] = $this->obligation('2026-10-01', '2026-10-11', null);
        $secondOffice = $this->score($secondOfficeObligations, $cutoff);

        self::assertEquals(100.0, $firstOffice['tc_percent']);
        self::assertEquals(60.0, $firstOffice['se_percent']);
        self::assertEquals(50.0, $secondOffice['tc_percent']);
        self::assertEquals(30.0, $secondOffice['se_percent']);

        $pooled = $this->invoke('pooledScore', [[
            ['score_status' => 'Scored', ...$firstOffice],
            ['score_status' => 'Scored', ...$secondOffice],
        ]]);
        self::assertEquals(60.0, $pooled['tc_percent']);
        self::assertEquals(36.0, $pooled['se_percent']);
        self::assertSame(55.2, round(($pooled['tc_percent'] * 0.80) + ($pooled['se_percent'] * 0.20), 2));
    }

    public function test_reference_ranking_uses_tc_before_otps_and_unrounded_metrics(): void
    {
        $rows = [
            $this->rankRow('pa:b', 'PAMO B', 20, 20, '65/1', 13, 20, 3),
            $this->rankRow('pa:apl', 'APL', 2, 2, '50/1', 1, 2, 0),
            $this->rankRow('pa:c', 'PAMO C', 20, 19, '80/1', 12, 20, 5),
            $this->rankRow('pa:d', 'PAMO D', 20, 19, '60/1', 5, 20, 4),
        ];
        $this->rank($rows);
        self::assertSame(['PAMO B', 'APL', 'PAMO C', 'PAMO D'], array_column($rows, 'name'));
        self::assertSame([1, 2, 3, 4], array_column($rows, 'rank'));

        self::assertSame(['pa:b', 'pa:apl', 'pa:c', 'pa:d'], array_column($rows, 'participant_key'));
        self::assertSame([1, 2, 3, 4], array_column($rows, 'rank'));

        $precise = [
            $this->rankRow('pa:third', 'One third', 3, 1, '1/3', 0, 1, 0),
            $this->rankRow('pa:decimal', 'Decimal', 3, 1, '333/1000', 0, 1, 0),
        ];
        $this->rank($precise);
        self::assertSame('pa:third', $precise[0]['participant_key'], 'Exact rational SE comparison must survive equal two-decimal presentation.');
        self::assertSame([1, 2], array_column($precise, 'rank'));
    }

    public function test_each_ranking_tiebreak_and_awaiting_classification_state(): void
    {
        $pairs = [
            [$this->rankRow('a', 'A', 10, 10, '1/1', 0, 0, 0), $this->rankRow('b', 'B', 10, 9, '100/1', 10, 100, 100)],
            [$this->rankRow('a', 'A', 10, 10, '65/1', 0, 0, 0), $this->rankRow('b', 'B', 10, 10, '64/1', 10, 100, 100)],
            [$this->rankRow('a', 'A', 10, 10, '65/1', 5, 1, 0), $this->rankRow('b', 'B', 10, 10, '65/1', 4, 100, 100)],
            [$this->rankRow('a', 'A', 10, 10, '65/1', 5, 2, 0), $this->rankRow('b', 'B', 10, 10, '65/1', 5, 1, 100)],
            [$this->rankRow('a', 'A', 10, 10, '65/1', 5, 2, 3), $this->rankRow('b', 'B', 10, 10, '65/1', 5, 2, 2)],
        ];

        foreach ($pairs as $pair) {
            $rows = $pair;
            $this->rank($rows);
            self::assertSame('a', $rows[0]['participant_key']);
            self::assertSame([1, 2], array_column($rows, 'rank'));
        }

        $rows = [
            $this->rankRow('a', 'A', 3, 2, '50/1', 1, 2, 0),
            $this->rankRow('b', 'B', 3, 2, '50/1', 1, 2, null),
        ];
        $ties = $this->rank($rows);
        self::assertNull($rows[0]['rank']);
        self::assertSame('Awaiting technical classification', $rows[1]['rank_status']);
        self::assertFalse($ties[0]['resolvable']);

        $reservedThenRanked = [
            $this->rankRow('a', 'A', 3, 2, '50/1', 1, 2, 0),
            $this->rankRow('b', 'B', 3, 2, '50/1', 1, 2, null),
            $this->rankRow('c', 'C', 3, 2, '49/1', 1, 2, 0),
        ];
        $this->rank($reservedThenRanked);
        self::assertSame(['a', 'b', 'c'], array_column($reservedThenRanked, 'participant_key'));
        self::assertSame([1, 1, 3], array_column($reservedThenRanked, 'reserved_rank_start') + array_column($reservedThenRanked, 'rank'));
        self::assertSame([1, 1], array_column(array_slice($reservedThenRanked, 0, 2), 'reserved_rank_start'));
        self::assertSame([2, 2], array_column(array_slice($reservedThenRanked, 0, 2), 'reserved_rank_end'));
        self::assertSame(3, $reservedThenRanked[2]['rank']);

        $exactTie = [
            $this->rankRow('a', 'A', 3, 2, '50/1', 1, 2, 0),
            $this->rankRow('b', 'B', 3, 2, '50/1', 1, 2, 0),
        ];
        $ties = $this->rank($exactTie);
        self::assertNull($exactTie[0]['rank']);
        self::assertSame('Awaiting tie resolution', $exactTie[0]['rank_status']);
        self::assertTrue($ties[0]['resolvable']);
    }

    public function test_manual_tie_decisions_are_fingerprint_and_membership_scoped_and_assign_unique_places(): void
    {
        $baseFingerprint = hash('sha256', 'isolated assessment');
        $rows = [
            $this->rankRow('a', 'A', 3, 2, '50/1', 1, 2, 0),
            $this->rankRow('b', 'B', 3, 2, '50/1', 1, 2, 0),
        ];
        $members = ['a', 'b'];
        $fingerprint = hash('sha256', $baseFingerprint.'|'.json_encode($members, JSON_THROW_ON_ERROR));

        $staleRows = $rows;
        Schema::create('office_tat_tie_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('unit');
            $table->string('period_key');
            $table->string('metric_fingerprint', 64);
            $table->json('participant_keys');
            $table->json('ordered_participant_keys');
            $table->text('reason');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at');
            $table->timestamps();
        });
        DB::table('office_tat_tie_decisions')->insert([
            'unit' => 'development', 'period_key' => '2027', 'metric_fingerprint' => str_repeat('0', 64),
            'participant_keys' => json_encode($members), 'ordered_participant_keys' => json_encode(['b', 'a']),
            'reason' => 'stale fingerprint', 'approved_at' => '2026-10-11 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ties = $this->rank($staleRows, $baseFingerprint);
        self::assertTrue($ties[0]['resolvable']);
        self::assertNull($staleRows[0]['rank']);

        DB::table('office_tat_tie_decisions')->delete();
        DB::table('office_tat_tie_decisions')->insert([
            'unit' => 'development', 'period_key' => '2027', 'metric_fingerprint' => $fingerprint,
            'participant_keys' => json_encode(['a', 'forged']), 'ordered_participant_keys' => json_encode(['b', 'a']),
            'reason' => 'forged membership', 'approved_at' => '2026-10-11 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $forgedRows = $rows;
        $ties = $this->rank($forgedRows, $baseFingerprint);
        self::assertTrue($ties[0]['resolvable']);
        self::assertNull($forgedRows[0]['rank']);

        DB::table('office_tat_tie_decisions')->delete();
        DB::table('office_tat_tie_decisions')->insert([
            'unit' => 'development', 'period_key' => '2027', 'metric_fingerprint' => $fingerprint,
            'participant_keys' => json_encode($members), 'ordered_participant_keys' => json_encode(['b', 'a']),
            'reason' => 'approved independent review', 'approved_at' => '2026-10-11 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resolvedRows = $rows;
        $ties = $this->rank($resolvedRows, $baseFingerprint);
        self::assertSame(['b', 'a'], array_column($resolvedRows, 'participant_key'));
        self::assertSame([1, 2], array_column($resolvedRows, 'rank'));
        self::assertSame([], $ties);
    }

    private function score(array $obligations, CarbonImmutable $cutoff): array
    {
        return $this->invoke('scoreParticipant', [$obligations, $cutoff]);
    }

    private function rank(array &$rows, string $fingerprint = 'assessment-fingerprint'): array
    {
        $method = new \ReflectionMethod($this->service, 'rankRows');
        $arguments = [&$rows, 'development', '2027', $fingerprint];
        return $method->invokeArgs($this->service, $arguments);
    }

    private function invoke(string $methodName, array $arguments): mixed
    {
        return (new \ReflectionMethod($this->service, $methodName))->invokeArgs($this->service, $arguments);
    }

    private function obligation(string $start, string $deadline, ?string $received, ?bool $technical = false): array
    {
        return [
            'key' => 'obligation-'.uniqid(), 'label' => 'Scoring test report', 'start' => $start, 'deadline' => $deadline,
            'received' => $received, 'source_deadline' => $received ? $deadline : null, 'technical' => $technical,
            'record_id' => $received ? 1 : null, 'historical' => false, 'domain' => 'engp', 'day_mode' => 'calendar_days',
            'office' => null, 'classification_version' => 1, 'source_rows_matched' => $received ? 1 : 0,
        ];
    }

    private function rankRow(string $key, string $name, int $due, int $onTime, string $seRatio, int $early, int $volume, ?int $technical): array
    {
        return [
            'participant_key' => $key, 'name' => $name, 'score_status' => 'Scored', 'due_count' => $due,
            'submitted_count' => $onTime, 'on_time_count' => $onTime, 'tc_numerator' => $onTime, 'tc_denominator' => $due,
            'se_ratio' => $seRatio, 'early_count' => $early, 'volume_count' => $volume,
            'technical_on_time_count' => $technical, 'rank' => null, 'rank_status' => 'Pending comparison',
        ];
    }
}
