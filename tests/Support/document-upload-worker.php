<?php

use App\Models\User;
use App\Modules\FleetAndDocuments\Application\DocumentAdministration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// Only the disposable cluster configured by tests/run-postgres.sh is accepted.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = config('database.connections.pgsql_testing');
if (getenv('TEST_DB_ISOLATED') !== '1' || ! str_starts_with($config['host'], '/tmp/transporte-tests-') || $config['database'] !== 'transporte_test' || $config['username'] !== 'transport_test' || $config['url'] !== null) {
    throw new RuntimeException('Isolated database required.');
}
config(['database.default' => 'pgsql_testing', 'database.connections' => ['pgsql_testing' => $config]]);
$input = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/\Aupload_concurrency_[a-f0-9]+\z/', $input['schema'])) {
    throw new RuntimeException('Invalid test schema.');
}
DB::statement('SET search_path TO '.$input['schema'].',public');
DB::statement("SET application_name='document-upload-worker'");
config(['filesystems.disks.fleet_documents.root' => $input['disk']]);
$actor = User::findOrFail($input['actor']);
$file = new UploadedFile(__DIR__.'/../Fixtures/fleet-documents/valid.pdf', 'document.pdf', null, null, true);
$result = app(DocumentAdministration::class)->register($actor, $input['asset'], $input['data'], $file);
echo $result->id;
