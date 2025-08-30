<?php

declare(strict_types=1);

namespace FOfX\Utility\Tests\Unit;

use FOfX\Utility\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;

use function FOfX\Utility\get_tables;
use function FOfX\Utility\download_public_suffix_list;
use function FOfX\Utility\extract_registrable_domain;
use function FOfX\Utility\is_valid_domain;
use function FOfX\Utility\list_embedded_json_selectors;
use function FOfX\Utility\extract_embedded_json_blocks;

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

        get_tables();
    }

    public function test_download_public_suffix_list_returns_path_when_file_exists(): void
    {
        // File already exists from TestCase setup
        $path = download_public_suffix_list();

        $this->assertTrue(Storage::disk('local')->exists(self::PSL_FILENAME));
        $this->assertEquals(Storage::disk('local')->path(self::PSL_FILENAME), $path);
    }

    public function test_download_public_suffix_list_downloads_when_file_does_not_exist(): void
    {
        // Remove the file that TestCase setup created
        Storage::disk('local')->delete(self::PSL_FILENAME);

        $expectedContent = 'public suffix list content';
        Http::fake([
            'publicsuffix.org/list/public_suffix_list.dat' => Http::response($expectedContent),
        ]);

        $path = download_public_suffix_list();

        $this->assertTrue(Storage::disk('local')->exists(self::PSL_FILENAME));
        $this->assertEquals($expectedContent, Storage::disk('local')->get(self::PSL_FILENAME));
        $this->assertEquals(Storage::disk('local')->path(self::PSL_FILENAME), $path);
    }

    public function test_download_public_suffix_list_throws_exception_on_download_failure(): void
    {
        // Remove the file that TestCase setup created
        Storage::disk('local')->delete(self::PSL_FILENAME);

        Http::fake([
            'publicsuffix.org/list/public_suffix_list.dat' => Http::response('', 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to download public suffix list');

        download_public_suffix_list();
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
        $this->assertSame($expected, is_valid_domain($domain));
    }

    public function test_list_embedded_json_selectors_includeLdJson_true_unique_true(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="a"> {"x":1} </script>
    <script type="application/ld+json" id="b"> {"@context":"https://schema.org"} </script>
    <script type="application/json" id="dup"> {"x":2} </script>
    <script type="application/json" id="dup"> {"x":3} </script>
    <script type="text/javascript" id="ignored"> {"x":4} </script>
  </head>
</html>
HTML;
        $selectors = list_embedded_json_selectors($html, includeLdJson: true, unique: true);
        $this->assertSame(['script#a', 'script#b', 'script#dup'], $selectors);
    }

    public function test_list_embedded_json_selectors_includeLdJson_false_unique_true(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="a"> {"x":1} </script>
    <script type="application/ld+json" id="b"> {"@context":"https://schema.org"} </script>
    <script type="application/json" id="dup"> {"x":2} </script>
    <script type="application/json" id="dup"> {"x":3} </script>
    <script type="text/javascript" id="ignored"> {"x":4} </script>
  </head>
</html>
HTML;
        $selectors = list_embedded_json_selectors($html, includeLdJson: false, unique: true);
        $this->assertSame(['script#a', 'script#dup'], $selectors);
    }

    public function test_list_embedded_json_selectors_unique_false_includes_duplicates(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="a"> {"x":1} </script>
    <script type="application/ld+json" id="b"> {"@context":"https://schema.org"} </script>
    <script type="application/json" id="dup"> {"x":2} </script>
    <script type="application/json" id="dup"> {"x":3} </script>
    <script type="text/javascript" id="ignored"> {"x":4} </script>
  </head>
</html>
HTML;
        $selectors = list_embedded_json_selectors($html, includeLdJson: true, unique: false);
        $this->assertSame(['script#a', 'script#b', 'script#dup', 'script#dup'], $selectors);
    }

    public function test_extract_embedded_json_blocks_includeLdJson_true_assoc_true(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="perseus" class="foo bar"> {"a":1} </script>
    <script type="application/ld+json" id="ld1"> {"@context":"https://schema.org"} </script>
    <script type="application/json"> {"b": [1,2]} </script>
    <script type="application/json" id="bigint"> {"id": 9223372036854775808} </script>
  </head>
</html>
HTML;
        $blocks = extract_embedded_json_blocks($html, includeLdJson: true, assoc: true);
        $this->assertCount(4, $blocks);
        $this->assertSame(['id', 'type', 'bytes', 'attrs', 'json'], array_keys($blocks[0]));
        $this->assertSame('perseus', $blocks[0]['id']);
        $this->assertSame('application/ld+json', $blocks[1]['type']);
        $this->assertSame('', $blocks[2]['id']);
        $this->assertSame('bigint', $blocks[3]['id']);
        $this->assertSame('foo bar', $blocks[0]['attrs']['class']);
        $this->assertSame(['a' => 1], $blocks[0]['json']);
        $this->assertSame('9223372036854775808', $blocks[3]['json']['id']);
    }

    public function test_extract_embedded_json_blocks_includeLdJson_false_assoc_true(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="perseus"> {"a":1} </script>
    <script type="application/ld+json" id="ld1"> {"@context":"https://schema.org"} </script>
    <script type="application/json"> {"b": [1,2]} </script>
    <script type="application/json" id="bigint"> {"id": 9223372036854775808} </script>
  </head>
</html>
HTML;
        $blocks = extract_embedded_json_blocks($html, includeLdJson: false, assoc: true);
        $this->assertCount(3, $blocks);
        $this->assertSame('perseus', $blocks[0]['id']);
        $this->assertSame('', $blocks[1]['id']);
        $this->assertSame('bigint', $blocks[2]['id']);
    }

    public function test_extract_embedded_json_blocks_assoc_false_returns_objects(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="perseus"> {"a":1} </script>
  </head>
</html>
HTML;
        $blocks = extract_embedded_json_blocks($html, includeLdJson: true, assoc: false);
        $this->assertIsObject($blocks[0]['json']);
    }

    public function test_extract_embedded_json_blocks_limit_applies(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="perseus"> {"a":1} </script>
    <script type="application/ld+json" id="ld1"> {"@context":"https://schema.org"} </script>
    <script type="application/json"> {"b": [1,2]} </script>
  </head>
</html>
HTML;
        $blocks = extract_embedded_json_blocks($html, includeLdJson: true, assoc: true, limit: 2);
        $this->assertCount(2, $blocks);
        $this->assertSame('perseus', $blocks[0]['id']);
        $this->assertSame('application/ld+json', $blocks[1]['type']);
    }
}
