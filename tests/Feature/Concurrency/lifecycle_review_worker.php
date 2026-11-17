<?php

declare(strict_types=1);

use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Actions\StudentLifecycle\RecordLifecycleAccountingReview;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$database = getenv('TALA_TEST_DATABASE') ?: 'test_tala_db';
if (preg_match('/\Atest_tala_db(?:_[a-z0-9_]+)?\z/', $database) !== 1) {
    fwrite(STDERR, "A TALA test database is required.\n");
    exit(1);
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== $database || DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== $database || DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
    fwrite(STDERR, "The configured and active database must match the test target.\n");
    exit(1);
}

try {
    $payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    $enrollment = Enrollment::query()->findOrFail($payload['enrollment_id']);
    $actor = User::query()->findOrFail($payload['actor_id']);
    echo 'READY:'.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
    flush();
    app(RecordLifecycleAccountingReview::class)->execute($enrollment, $actor, $payload['reviewed'], [
        'outcome' => LifecycleAccountingReview::NoAccountChange,
        'reason' => 'Competing completed review.',
    ]);
    fwrite(STDERR, "The stale result was unexpectedly accepted.\n");
    exit(1);
} catch (ValidationException $exception) {
    if (! isset($exception->errors()['review']) || ! str_contains($exception->errors()['review'][0], 'changed while')) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
    echo "REJECTED:review\n";
}
