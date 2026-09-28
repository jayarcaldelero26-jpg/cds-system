<?php

namespace Tests\Unit;

use App\Http\Controllers\ImeaAssessmentController;
use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** No Laravel application bootstrap, RefreshDatabase, migrations, or filesystem fixtures. */
final class ImeaFacilitiesExportBatchTest extends TestCase
{
    private Container $previousContainer;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();

        $container = new Container;
        Container::setInstance($container);
        Model::unsetEventDispatcher();
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();

        $responses = $this->createMock(ResponseFactory::class);
        $responses->expects(self::once())->method('stream')->willReturnCallback(
            fn ($callback, $status, $headers) => new StreamedResponse($callback, $status, $headers)
        );
        $container->instance(ResponseFactory::class, $responses);

        // Explicitly create only the temporary tables needed by the export.
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('protected_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
        $schema->create('protected_area_facilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('protected_area_id');
            $table->string('inventory_date');
            $table->integer('unit_no');
            $table->integer('year_established');
            foreach (['facility_type', 'location_brgy_muni', 'management_zone', 'within_easement_zone',
                'status', 'source_of_fund', 'tenurial_instrument', 'recommendations', 'remarks'] as $field) {
                $table->text($field)->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher !== null) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public static function facilityCounts(): array
    {
        return [[1], [500], [501], [1001]];
    }

    #[DataProvider('facilityCounts')]
    public function test_export_eager_loads_per_batch_and_preserves_scoped_filtered_csv(int $count): void
    {
        $connection = $this->database->getConnection();
        self::assertSame(':memory:', $connection->getConfig('database'));
        self::assertSame('sqlite', $connection->getDriverName());
        $connection->table('protected_areas')->insert([
            ['id' => 1, 'name' => '=PA, "quoted"'],
            ['id' => 2, 'name' => 'Outside scope'],
        ]);
        $fixture = [
            'protected_area_id' => 1, 'inventory_date' => '2026-09-28',
            'facility_type' => '=Facility', 'unit_no' => 1, 'year_established' => 4000,
            'location_brgy_muni' => "Mati, Davao\nOriental", 'management_zone' => '@Zone',
            'within_easement_zone' => '+Yes', 'status' => '-Status', 'source_of_fund' => '=Fund',
            'tenurial_instrument' => '@Permit', 'recommendations' => '+Recommend', 'remarks' => "Café, \"quoted\"",
        ];
        for ($index = 0; $index < $count; $index++) {
            $connection->table('protected_area_facilities')->insert(array_replace($fixture, [
                'unit_no' => $index + 1, 'year_established' => 4000 - $index,
            ]));
        }
        foreach ([['protected_area_id' => 2], ['management_zone' => 'Other'], ['inventory_date' => '2026-09-27']] as $excluded) {
            $connection->table('protected_area_facilities')->insert(array_replace($fixture, $excluded));
        }

        $user = new User(['section' => 'PAMO', 'unit_assignment' => 'conservation',
            'is_active' => true, 'protected_area_id' => 1]);
        $user->setRelation('roles', new Collection);
        // Alternate explicit PA filtering with PA scoping alone.
        $request = Request::create('/imea/facilities/export', 'GET', array_filter([
            'protected_area_id' => $count === 501 ? 1 : null,
            'zone' => '@Zone', 'inventory_date' => '2026-09-28',
        ], fn ($value) => $value !== null));
        $request->setUserResolver(fn () => $user);
        $gateway = $this->createMock(GoogleDriveArchiveGateway::class);
        foreach (['findByIdentityAndHash', 'upload', 'verify', 'verifyAvailability', 'replace', 'retrieve'] as $method) {
            $gateway->expects(self::never())->method($method);
        }
        $controller = new ImeaAssessmentController(new ProtectedAttachmentService($gateway), new OrganizationalAccessService);
        $connection->enableQueryLog();
        $response = $controller->exportFacilitiesExcel($request);
        self::assertSame([], $connection->getQueryLog(), 'Retrieval must remain inside the streamed callback.');
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=facilities_inventory_'.date('Y-m-d').'.csv', $response->headers->get('Content-Disposition'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('0', $response->headers->get('Expires'));
        self::assertStringContainsString('must-revalidate', $response->headers->get('Cache-Control'));

        ob_start();
        try {
            $response->sendContent();
            $csv = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $queries = array_column($connection->getQueryLog(), 'query');
        $paQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'from "protected_areas"')));
        $facilityQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'from "protected_area_facilities"')));
        self::assertCount((int) ceil($count / 500), $paQueries, 'One PA query per nonempty batch, not per facility.');
        self::assertCount(intdiv($count, 500) + 1, $facilityQueries);
        foreach ($facilityQueries as $sql) {
            self::assertStringContainsString('order by "year_established" desc', $sql);
            self::assertStringContainsString('limit 500', $sql);
        }
        foreach ($paQueries as $sql) {
            self::assertStringContainsString(' in (', $sql, 'The relationship must be eager loaded.');
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        self::assertSame(['Protected Area', 'Inventory Date', 'Facility / Structure', 'Unit No.',
            'Year Established', 'Location (Brgy/Muni)', 'Management Zone', 'Within Easement Zone',
            'Status', 'Source of Fund', 'Tenurial Instrument', 'Recommendations', 'Remarks'], fgetcsv($stream));
        for ($index = 0; $index < $count; $index++) {
            self::assertSame(["'=PA, \"quoted\"", '2026-09-28', "'=Facility", (string) ($index + 1),
                (string) (4000 - $index), "Mati, Davao\nOriental", "'@Zone", "'+Yes", "'-Status",
                "'=Fund", "'@Permit", "'+Recommend", "Café, \"quoted\""], fgetcsv($stream));
        }
        self::assertFalse(fgetcsv($stream), 'No out-of-scope or filtered-out rows may be emitted.');
        fclose($stream);
    }
}
