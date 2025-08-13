<?php

declare(strict_types=1);

namespace FOfX\Utility\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Http;
use Mockery;

use function FOfX\Utility\get_tables;
use function FOfX\Utility\download_public_suffix_list;
use function FOfX\Utility\extract_registrable_domain;
use function FOfX\Utility\is_valid_domain;

class FunctionsTest extends TestCase
{
    public function test_get_tables_returns_array_of_tables(): void
    {
        // Create some test tables
        Schema::create('test_table1', function ($table) {
            $table->id();
        });
        Schema::create('test_table2', function ($table) {
            $table->id();
        });

        $tables = get_tables();

        $this->assertContains('test_table1', $tables);
        $this->assertContains('test_table2', $tables);
    }

    public function test_get_tables_throws_exception_for_unsupported_driver(): void
    {
        // Create a mock connection that will return an unsupported driver name
        $mockConnection = Mockery::mock();
        $mockConnection->shouldReceive('getDriverName')
            ->once()
            ->andReturn('unsupportedDriverName');

        // Ensure getSchemaBuilder() exists, even if not used
        $mockConnection->shouldReceive('getSchemaBuilder')->andReturnSelf();

        // Mock Schema facade to prevent errors related to dropIfExists()
        Schema::shouldReceive('dropIfExists')->andReturnTrue();

        // Mock DB facade to return the mock connection
        DB::shouldReceive('connection')
            ->andReturn($mockConnection);

        // Ensure DB::select() is never called since the exception should occur first
        $mockConnection->shouldNotReceive('select');

        // Expect an exception when calling get_tables()
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported database driver: unsupported');

        get_tables();
    }

    public function test_download_public_suffix_list_returns_path_when_file_exists(): void
    {
        // Create a test file
        $expectedPath    = storage_path('app/public_suffix_list.dat');
        $expectedContent = 'public suffix list content';
        file_put_contents($expectedPath, $expectedContent);

        $path = download_public_suffix_list();

        $this->assertSame($expectedPath, $path);
        $this->assertFileExists($path);
        $this->assertSame($expectedContent, file_get_contents($path));
    }

    public function test_download_public_suffix_list_downloads_when_file_does_not_exist(): void
    {
        // Create a test file
        $expectedPath    = storage_path('app/public_suffix_list.dat');
        $expectedContent = 'public suffix list content';

        // Ensure file doesn't exist
        if (file_exists($expectedPath)) {
            unlink($expectedPath);
        }

        Http::fake([
            'publicsuffix.org/list/public_suffix_list.dat' => Http::response($expectedContent),
        ]);

        $path = download_public_suffix_list();

        $this->assertSame($expectedPath, $path);
        $this->assertFileExists($path);
        $this->assertSame($expectedContent, file_get_contents($path));

        // Delete the fake response file if it was created
        if (file_exists($expectedPath)) {
            unlink($expectedPath);
        }
    }

    public function test_download_public_suffix_list_throws_exception_on_download_failure(): void
    {
        $expectedPath = storage_path('app/public_suffix_list.dat');

        // Ensure file doesn't exist
        if (file_exists($expectedPath)) {
            unlink($expectedPath);
        }

        Http::fake([
            'publicsuffix.org/list/public_suffix_list.dat' => Http::response('', 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to download public suffix list');

        try {
            download_public_suffix_list();
        } finally {
            // Delete the fake response file if it was created
            if (file_exists($expectedPath)) {
                unlink($expectedPath);
            }
        }
    }

    public static function provideExtractRegistrableDomainTestCases(): array
    {
        return [
            // php-domain-parser already strips www for example.com
            'simple domain' => [
                'url'      => 'example.com',
                'expected' => 'example.com',
            ],
            'domain with www' => [
                'url'      => 'www.example.com',
                'expected' => 'example.com',
            ],
            'domain with www and stripWww false' => [
                'url'      => 'www.example.com',
                'expected' => 'example.com',
                'stripWww' => false,
            ],

            // php-domain-parser keeps www for httpbin.org
            'www.httpbin.org' => [
                'url'      => 'www.httpbin.org',
                'expected' => 'httpbin.org',
            ],
            'www.httpbin.org with stripWww false' => [
                'url'      => 'www.httpbin.org',
                'expected' => 'www.httpbin.org',
                'stripWww' => false,
            ],

            // URLs with protocols
            'http url' => [
                'url'      => 'http://example.com',
                'expected' => 'example.com',
            ],
            'https url' => [
                'url'      => 'https://example.com',
                'expected' => 'example.com',
            ],
            'https url with www' => [
                'url'      => 'https://www.example.com',
                'expected' => 'example.com',
            ],
            'https httpbin.org with www' => [
                'url'      => 'https://www.httpbin.org',
                'expected' => 'httpbin.org',
            ],

            // URLs with paths and queries
            'url with path' => [
                'url'      => 'https://example.com/path/to/page',
                'expected' => 'example.com',
            ],
            'url with query' => [
                'url'      => 'https://example.com?param=value',
                'expected' => 'example.com',
            ],
            'url with path and query' => [
                'url'      => 'https://example.com/path?param=value',
                'expected' => 'example.com',
            ],
            'httpbin.org with path' => [
                'url'      => 'https://www.httpbin.org/path',
                'expected' => 'httpbin.org',
            ],
        ];
    }

    #[DataProvider('provideExtractRegistrableDomainTestCases')]
    public function testExtractRegistrableDomain(string $url, string $expected, bool $stripWww = true): void
    {
        // Use local PSL file if it exists
        $localPslPath = dirname(__DIR__, 2) . '/local/resources/public_suffix_list.dat';
        if (!file_exists($localPslPath)) {
            $this->markTestSkipped('Local PSL file not found at: ' . $localPslPath);
        }

        // Copy local PSL to test storage location
        $testPslPath = storage_path('app/public_suffix_list.dat');
        copy($localPslPath, $testPslPath);

        $actual = extract_registrable_domain($url, $stripWww);
        $this->assertEquals($expected, $actual);
    }

    public static function provideIsValidDomainCases(): array
    {
        return [
            'simple domain'      => ['example.com', true],
            'subdomain'          => ['sub.example.com', true],
            'idn domain'         => ['müller.de', true],
            'public suffix only' => ['co.uk', false],
            'invalid tld'        => ['example.invalidtld', false],
            'leading www'        => ['www.example.com', true],
            'empty string'       => ['', false],
            'just dot'           => ['.', false],
            'space'              => [' example.com ', false],
        ];
    }

    #[DataProvider('provideIsValidDomainCases')]
    public function test_is_valid_domain(string $domain, bool $expected): void
    {
        // Use local PSL file if it exists
        $localPslPath = dirname(__DIR__, 2) . '/local/resources/public_suffix_list.dat';
        if (!file_exists($localPslPath)) {
            $this->markTestSkipped('Local PSL file not found at: ' . $localPslPath);
        }

        // Copy local PSL to test storage location (tests use temporary storage)
        $testPslPath = storage_path('app/public_suffix_list.dat');
        copy($localPslPath, $testPslPath);

        $this->assertSame($expected, is_valid_domain($domain));
    }
}
