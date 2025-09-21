<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Redis\RedisServiceProvider;

// Create the application container
$app = new Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);
$app->bootstrapWith([
    LoadEnvironmentVariables::class,
    LoadConfiguration::class,
]);

// Set the application instance for facades
Facade::setFacadeApplication($app);

// Override default DB connection using utility config
//$databaseConnection = config('utility.database_connection', 'sqlite_memory');
//config(['database.default' => $databaseConnection]);

// Register service providers
$app->register(EventServiceProvider::class);
$app->register(DatabaseServiceProvider::class);
$app->register(FilesystemServiceProvider::class);
$app->register(CacheServiceProvider::class);
$app->register(RedisServiceProvider::class);

// Set up basic paths
$app->useStoragePath($app->basePath('storage'));

// Create storage directories if they don't exist
$storagePath          = $app->storagePath();
$storageAppPath       = $app->storagePath('app');
$storageAppPublicPath = $app->storagePath('app/public');
$storageLogsPath      = $app->storagePath('logs');

if (!is_dir($storagePath)) {
    mkdir($storagePath, 0755, true);
}

if (!is_dir($storageAppPath)) {
    mkdir($storageAppPath, 0755, true);
}

if (!is_dir($storageAppPublicPath)) {
    mkdir($storageAppPublicPath, 0755, true);
}

if (!is_dir($storageLogsPath)) {
    mkdir($storageLogsPath, 0755, true);
}
