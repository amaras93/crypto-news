<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MigrationIdentifierTest extends TestCase
{
    #[DataProvider('databaseVersions')]
    public function test_the_complete_migration_chain_uses_portable_identifiers_and_matching_rollback_names(string $driver, string $version): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn($version);
        $pdo->shouldNotReceive('prepare', 'exec', 'query', 'beginTransaction');
        $class = $driver === 'mariadb' ? MariaDbConnection::class : MySqlConnection::class;
        $connection = new $class($pdo, 'identifier_regression', '', [
            'driver' => $driver, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix_indexes' => true,
        ]);
        $schema = $connection->getSchemaBuilder();
        $originalSchema = Schema::getFacadeRoot();
        $originalDatabase = DB::getFacadeRoot();
        Schema::swap($schema);
        DB::swap($connection);
        $violations = [];
        $foreignKeys = [];
        $queries = [];
        try {
            foreach (['up' => $this->migrationFiles(), 'down' => array_reverse($this->migrationFiles())] as $direction => $files) {
                foreach ($files as $file) {
                    $migration = require $file;
                    $statements = $connection->pretend(fn () => $migration->{$direction}());
                    foreach ($statements as $statement) {
                        $sql = $statement['query'];
                        $queries[] = $sql;
                        preg_match_all('/`((?:``|[^`])+)`/', $sql, $identifiers);
                        foreach ($identifiers[1] as $identifier) {
                            if (mb_strlen(str_replace('``', '`', $identifier)) > 64) {
                                $violations[] = basename($file).' '.$direction.': '.$identifier;
                            }
                        }
                        if (preg_match('/alter table `([^`]+)` add constraint `([^`]+)` foreign key/i', $sql, $created)) {
                            $foreignKeys[$created[1]][$created[2]] = true;
                        }
                        if (preg_match('/alter table `([^`]+)` drop foreign key `([^`]+)`/i', $sql, $dropped)) {
                            $this->assertArrayHasKey($dropped[2], $foreignKeys[$dropped[1]] ?? [], basename($file).' drops a foreign key that was not created.');
                        }
                    }
                }
            }
        } finally {
            Schema::swap($originalSchema);
            DB::swap($originalDatabase);
        }
        $this->assertNotEmpty($foreignKeys, 'The audit must compile foreign key constraints.');
        $this->assertStringContainsString('add unique', implode("\n", $queries));
        $this->assertStringContainsString('add index', implode("\n", $queries));
        $this->assertSame([], array_values(array_unique($violations)), implode("\n", $violations));
    }

    public function test_all_migrations_can_be_reversed_and_reapplied_on_a_disposable_database(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
        $connection->statement('PRAGMA foreign_keys = ON');
        $this->assertMigrationRoundTrip($connection);
    }

    public function test_the_complete_chain_executes_on_an_opt_in_empty_local_mariadb_or_mysql_database(): void
    {
        $configurationFile = getenv('CRYPTONEWS_MIGRATION_TEST_CONFIG');
        if (! is_string($configurationFile) || $configurationFile === '') {
            $this->markTestSkipped('Set CRYPTONEWS_MIGRATION_TEST_CONFIG to an ignored local fixture configuration for real database execution.');
        }
        $settings = json_decode(ltrim(file_get_contents($configurationFile), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);
        $driver = $settings['driver'] ?? 'mariadb';
        $this->assertContains($driver, ['mariadb', 'mysql']);
        $this->assertContains($settings['host'], ['127.0.0.1', '::1', 'localhost'], 'Integration tests must never connect to a remote production host.');
        $this->assertMatchesRegularExpression('/\Acryptonews_migration_test_[a-z0-9_]+\z/D', $settings['database']);
        $manager = DB::getFacadeRoot();
        $connection = $manager->build(array_replace(config('database.connections.'.$driver), [
            'url' => null, 'host' => $settings['host'], 'port' => $settings['port'], 'database' => $settings['database'],
            'username' => $settings['username'], 'password' => $settings['password'], 'unix_socket' => '',
            'prefix' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true, 'timezone' => '+00:00',
        ]));
        try {
            $this->assertSame([], $connection->getSchemaBuilder()->getTableListing(), 'The dedicated test database must be empty; existing tables will never be wiped.');
            $version = $connection->getServerVersion();
            $this->assertMatchesRegularExpression($driver === 'mariadb' ? '/\A10\.11\./' : '/\A8\./', $version);
            $schema = $connection->getSchemaBuilder();
            try {
                $schema->create('candidate_source_references', function (Blueprint $table): void {
                    $table->unsignedBigInteger('news_candidate_id');
                    $table->unsignedBigInteger('news_source_id');
                    $table->index(['news_candidate_id', 'news_source_id']);
                });
                $this->fail('The server unexpectedly accepted a 66-character index identifier.');
            } catch (QueryException $exception) {
                $this->assertSame(1059, (int) $exception->errorInfo[1]);
            }
            $this->assertTrue($schema->hasTable('candidate_source_references'), 'The failed index must demonstrate a partially applied migration.');
            try {
                $schema->create('candidate_source_references', function (Blueprint $table): void {
                    $table->unsignedBigInteger('news_candidate_id');
                    $table->unsignedBigInteger('news_source_id');
                    $table->index(['news_candidate_id', 'news_source_id'], 'csr_candidate_source_index');
                });
                $this->fail('Rerunning CREATE TABLE should not silently repair the partial table.');
            } catch (QueryException $exception) {
                $this->assertSame(1050, (int) $exception->errorInfo[1]);
            }
            $schema->drop('candidate_source_references');
            $this->assertMigrationRoundTrip($connection);
            file_put_contents(storage_path('framework/testing/'.$driver.'-migration-integration.json'), json_encode([
                'status' => 'PASS', 'driver' => $driver, 'version' => $version, 'migrations' => count($this->migrationFiles()),
                'operations' => ['up', 'down', 'up', 'down'], 'database_empty_after_test' => true,
                'original_error_reproduced' => 1059, 'partial_table_survived' => true, 'blind_rerun_failed' => 1050,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            $manager->purge($connection->getName());
        }
    }

    private function assertMigrationRoundTrip(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $originalSchema = Schema::getFacadeRoot();
        $originalDatabase = DB::getFacadeRoot();
        Schema::swap($schema);
        DB::swap($connection);
        try {
            foreach ($this->migrationFiles() as $file) {
                (require $file)->up();
            }
            $tables = $schema->getTableListing(schemaQualified: false);
            $this->assertContains('candidate_source_references', $tables);
            $index = collect($schema->getIndexes('candidate_source_references'))->firstWhere('name', 'csr_candidate_source_index');
            $this->assertNotNull($index);
            $this->assertSame(['news_candidate_id', 'news_source_id'], $index['columns']);
            $this->assertFalse($index['unique']);
            $this->assertCount(3, $schema->getForeignKeys('candidate_source_references'));
            foreach (array_reverse($this->migrationFiles()) as $file) {
                (require $file)->down();
            }
            $this->assertSame([], $schema->getTableListing(schemaQualified: false));
            foreach ($this->migrationFiles() as $file) {
                (require $file)->up();
            }
            $this->assertSame($tables, $schema->getTableListing(schemaQualified: false));
            if ($connection->getDriverName() === 'sqlite') {
                $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
            }
            foreach (array_reverse($this->migrationFiles()) as $file) {
                (require $file)->down();
            }
            $this->assertSame([], $schema->getTableListing(schemaQualified: false));
        } finally {
            Schema::swap($originalSchema);
            DB::swap($originalDatabase);
            $connection->disconnect();
        }
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php'));
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, array{string, string}> */
    public static function databaseVersions(): array
    {
        return ['MariaDB 10.11.6' => ['mariadb', '10.11.6-MariaDB'], 'MySQL 8.0' => ['mysql', '8.0.36']];
    }
}
