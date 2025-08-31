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
use function FOfX\Utility\extract_canonical_url;
use function FOfX\Utility\list_embedded_json_selectors;
use function FOfX\Utility\extract_embedded_json_blocks;
use function FOfX\Utility\save_json_blocks_to_file;

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

    public static function provideExtractCanonicalUrlTestCases(): array
    {
        return [
            // Valid HTML with canonical URL
            'basic canonical URL' => [
                'html'     => '<html><head><link rel="canonical" href="https://example.com"></head></html>',
                'expected' => 'https://example.com',
            ],
            'canonical with multiple attributes' => [
                'html'     => '<html><head><link rel="canonical" href="https://example.com/page" type="text/html"></head></html>',
                'expected' => 'https://example.com/page',
            ],
            'canonical with complex URL' => [
                'html'     => '<html><head><link rel="canonical" href="https://example.com/path/to/page?param=value&other=test"></head></html>',
                'expected' => 'https://example.com/path/to/page?param=value&other=test',
            ],
            'canonical with whitespace in href' => [
                'html'     => '<html><head><link rel="canonical" href="  https://example.com  "></head></html>',
                'expected' => 'https://example.com',
            ],

            // Multiple canonical tags - should return first one
            'multiple canonical tags' => [
                'html'     => '<html><head><link rel="canonical" href="https://first.com"><link rel="canonical" href="https://second.com"></head></html>',
                'expected' => 'https://first.com',
            ],

            // Mixed with other link tags
            'canonical among other links' => [
                'html'     => '<html><head><link rel="stylesheet" href="style.css"><link rel="canonical" href="https://example.com"><link rel="icon" href="favicon.ico"></head></html>',
                'expected' => 'https://example.com',
            ],

            // Space-separated rel values (HTML spec compliance)
            'canonical with nofollow' => [
                'html'     => '<html><head><link rel="canonical nofollow" href="https://example.com/page"></head></html>',
                'expected' => 'https://example.com/page',
            ],
            'nofollow canonical reversed order' => [
                'html'     => '<html><head><link rel="nofollow canonical" href="https://example.com/page"></head></html>',
                'expected' => 'https://example.com/page',
            ],
            'canonical with multiple rel values' => [
                'html'     => '<html><head><link rel="canonical stylesheet nofollow" href="https://example.com/page"></head></html>',
                'expected' => 'https://example.com/page',
            ],
            'canonical with extra whitespace in rel' => [
                'html'     => '<html><head><link rel="  canonical   nofollow  " href="https://example.com/page"></head></html>',
                'expected' => 'https://example.com/page',
            ],
            'rel values without canonical should not match' => [
                'html'     => '<html><head><link rel="nofollow alternate stylesheet" href="https://example.com/page"></head></html>',
                'expected' => null,
            ],

            // Real-world like HTML structure
            'realistic HTML structure' => [
                'html'     => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Test Page</title><link rel="canonical" href="https://www.fiverr.com/vpross/create-logo"><meta name="description" content="Test"></head><body></body></html>',
                'expected' => 'https://www.fiverr.com/vpross/create-logo',
            ],

            // Cases that should return null
            'no canonical tag' => [
                'html'     => '<html><head><title>No canonical</title></head></html>',
                'expected' => null,
            ],
            'empty href' => [
                'html'     => '<html><head><link rel="canonical" href=""></head></html>',
                'expected' => null,
            ],
            'whitespace only href' => [
                'html'     => '<html><head><link rel="canonical" href="   "></head></html>',
                'expected' => null,
            ],
            'link without href attribute' => [
                'html'     => '<html><head><link rel="canonical"></head></html>',
                'expected' => null,
            ],
            'empty HTML' => [
                'html'     => '',
                'expected' => null,
            ],
            'only text content' => [
                'html'     => 'This is just plain text with no HTML tags',
                'expected' => null,
            ],

            // Edge cases and malformed HTML
            'malformed HTML - unclosed tags' => [
                'html'     => '<html><head><link rel="canonical" href="https://example.com"<title>Test</head></html>',
                'expected' => 'https://example.com',
            ],
            'malformed HTML - broken structure' => [
                'html'     => '<html><head><link rel="canonical" href="https://example.com"></title></head></html>',
                'expected' => 'https://example.com',
            ],
            'canonical tag in body instead of head' => [
                'html'     => '<html><body><link rel="canonical" href="https://example.com"></body></html>',
                'expected' => 'https://example.com',
            ],

            // Different case variations - element/attribute names in HTML are not case-sensitive
            'uppercase rel attribute' => [
                'html'     => '<html><head><link REL="canonical" href="https://example.com"></head></html>',
                'expected' => 'https://example.com',
            ],
            // DomCrawler's CSS attribute value matching is case-sensitive
            'mixed case canonical value - should not match' => [
                'html'     => '<html><head><link rel="CANONICAL" href="https://example.com"></head></html>',
                'expected' => null,
            ],

            // URLs with special characters
            'URL with special characters' => [
                'html'     => '<html><head><link rel="canonical" href="https://example.com/path?param=value&other=test#anchor"></head></html>',
                'expected' => 'https://example.com/path?param=value&other=test#anchor',
            ],
            'URL with encoded characters' => [
                'html'     => '<html><head><link rel="canonical" href="https://example.com/search?q=hello%20world"></head></html>',
                'expected' => 'https://example.com/search?q=hello%20world',
            ],
        ];
    }

    #[DataProvider('provideExtractCanonicalUrlTestCases')]
    public function test_extract_canonical_url(string $html, ?string $expected): void
    {
        $actual = extract_canonical_url($html);
        $this->assertSame($expected, $actual);
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

    public function test_save_json_blocks_to_file_without_selector_single_block(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="only-data">{"singleBlock": true}</script>
  </head>
</html>
HTML;

        $filename = 'test-no-selector-single.json';
        $result   = save_json_blocks_to_file($html, $filename);

        $this->assertEquals($filename, $result);
        $this->assertTrue(Storage::disk('local')->exists($filename));

        $savedContent   = Storage::disk('local')->get($filename);
        $decodedContent = json_decode($savedContent, true);

        // Should be unwrapped since there's only one block
        $this->assertEquals(['singleBlock' => true], $decodedContent);
    }

    public function test_save_json_blocks_to_file_without_selector_multiple_blocks(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="block1">{"data": 1}</script>
    <script type="application/json" id="block2">{"data": 2}</script>
  </head>
</html>
HTML;

        $filename = 'test-no-selector-multiple.json';
        $result   = save_json_blocks_to_file($html, $filename);

        $this->assertEquals($filename, $result);
        $this->assertTrue(Storage::disk('local')->exists($filename));

        $savedContent   = Storage::disk('local')->get($filename);
        $decodedContent = json_decode($savedContent, true);

        // Should be array since there are multiple blocks
        $this->assertEquals([['data' => 1], ['data' => 2]], $decodedContent);
    }

    public function test_save_json_blocks_to_file_with_selector_single_block(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="perseus-initial-props">{"userId": 123, "settings": {"theme": "dark"}}</script>
    <script type="application/json" id="other-data">{"otherData": true}</script>
  </head>
</html>
HTML;

        $filename = 'test-perseus.json';
        $result   = save_json_blocks_to_file($html, $filename, 'perseus-initial-props');

        $this->assertEquals($filename, $result);
        $this->assertTrue(Storage::disk('local')->exists($filename));

        $savedContent   = Storage::disk('local')->get($filename);
        $decodedContent = json_decode($savedContent, true);

        // Should be unwrapped since there's only one block
        $this->assertEquals(['userId' => 123, 'settings' => ['theme' => 'dark']], $decodedContent);
    }

    public function test_save_json_blocks_to_file_with_selector_multiple_blocks(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="data">{"block": 1}</script>
    <script type="application/json" id="data">{"block": 2}</script>
    <script type="application/json" id="other">{"other": true}</script>
  </head>
</html>
HTML;

        $filename = 'test-multiple.json';
        $result   = save_json_blocks_to_file($html, $filename, 'data');

        $this->assertEquals($filename, $result);
        $this->assertTrue(Storage::disk('local')->exists($filename));

        $savedContent   = Storage::disk('local')->get($filename);
        $decodedContent = json_decode($savedContent, true);

        // Should be array since there are multiple blocks
        $this->assertEquals([['block' => 1], ['block' => 2]], $decodedContent);
    }

    public function test_save_json_blocks_to_file_with_selector_no_matches(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="other-data">{"otherData": true}</script>
  </head>
</html>
HTML;

        $filename = 'test-no-matches.json';
        $result   = save_json_blocks_to_file($html, $filename, 'non-existent-id');

        $this->assertEquals($filename, $result);
        $this->assertTrue(Storage::disk('local')->exists($filename));

        $savedContent   = Storage::disk('local')->get($filename);
        $decodedContent = json_decode($savedContent, true);

        // Should be empty array
        $this->assertEquals([], $decodedContent);
    }

    public function test_save_json_blocks_to_file_prettyPrint_false(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="data">{"test": "value"}</script>
  </head>
</html>
HTML;

        $filename = 'test-compact.json';
        $result   = save_json_blocks_to_file($html, $filename, 'data', false);

        $this->assertEquals($filename, $result);
        $this->assertTrue(Storage::disk('local')->exists($filename));

        $savedContent = Storage::disk('local')->get($filename);

        // Should be compact JSON (no pretty printing)
        $this->assertEquals('{"test":"value"}', $savedContent);
    }

    public function test_save_json_blocks_to_file_json_flags_unescaped(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/json" id="data">{"url": "https://example.com/path?param=value&other=test", "unicode": "café"}</script>
  </head>
</html>
HTML;

        $filename = 'test-flags.json';
        $result   = save_json_blocks_to_file($html, $filename, 'data');

        $this->assertEquals($filename, $result);
        $this->assertTrue(Storage::disk('local')->exists($filename));

        $savedContent = Storage::disk('local')->get($filename);

        // Should contain unescaped slashes and unicode
        $this->assertStringContainsString('https://example.com/path', $savedContent);
        $this->assertStringContainsString('&other=test', $savedContent);
        $this->assertStringContainsString('café', $savedContent);
        $this->assertStringNotContainsString('\/', $savedContent);
        $this->assertStringNotContainsString('\\u0026', $savedContent);
    }
}
