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
use function FOfX\Utility\filter_json_blocks_by_selector;
use function FOfX\Utility\save_json_blocks_to_file;
use function FOfX\Utility\infer_laravel_type;
use function FOfX\Utility\inspect_json_types;
use function FOfX\Utility\types_to_columns;
use function FOfX\Utility\get_json_value_by_path;
use function FOfX\Utility\extract_values_by_paths;
use function FOfX\Utility\ensure_table_exists;
use function FOfX\Utility\kdp_trim_size_inches;
use function FOfX\Utility\kdp_print_cost_us;
use function FOfX\Utility\kdp_royalty_us;
use function FOfX\Utility\bsr_to_monthly_sales_books;

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
    public function test_extract_registrable_domain(string $url, string $expected, bool $stripWww = true): void
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

    public static function provideFilterJsonBlocksBySelectorTestCases(): array
    {
        $blocks = [
            [
                'id'    => 'block1',
                'type'  => 'application/json',
                'bytes' => 10,
                'attrs' => ['id' => 'block1', 'class' => null],
                'json'  => ['data' => 'value1'],
            ],
            [
                'id'    => 'block2',
                'type'  => 'application/json',
                'bytes' => 10,
                'attrs' => ['id' => 'block2', 'class' => null],
                'json'  => ['data' => 'value2'],
            ],
            [
                'id'    => 'block1',
                'type'  => 'application/ld+json',
                'bytes' => 15,
                'attrs' => ['id' => 'block1', 'class' => null],
                'json'  => ['@context' => 'schema.org'],
            ],
            [
                'id'    => '',
                'type'  => 'application/json',
                'bytes' => 8,
                'attrs' => ['id' => null, 'class' => null],
                'json'  => ['empty' => 'id'],
            ],
        ];

        return [
            'no selector, no pluck' => [
                'blocks'       => $blocks,
                'selectorId'   => null,
                'pluckJsonKey' => false,
                'expected'     => $blocks,
            ],
            'no selector, pluck json' => [
                'blocks'       => $blocks,
                'selectorId'   => null,
                'pluckJsonKey' => true,
                'expected'     => [
                    ['data' => 'value1'],
                    ['data'     => 'value2'],
                    ['@context' => 'schema.org'],
                    ['empty'    => 'id'],
                ],
            ],
            'filter by block1, no pluck' => [
                'blocks'       => $blocks,
                'selectorId'   => 'block1',
                'pluckJsonKey' => false,
                'expected'     => [
                    [
                        'id'    => 'block1',
                        'type'  => 'application/json',
                        'bytes' => 10,
                        'attrs' => ['id' => 'block1', 'class' => null],
                        'json'  => ['data' => 'value1'],
                    ],
                    [
                        'id'    => 'block1',
                        'type'  => 'application/ld+json',
                        'bytes' => 15,
                        'attrs' => ['id' => 'block1', 'class' => null],
                        'json'  => ['@context' => 'schema.org'],
                    ],
                ],
            ],
            'filter by block1, pluck json' => [
                'blocks'       => $blocks,
                'selectorId'   => 'block1',
                'pluckJsonKey' => true,
                'expected'     => [
                    ['data' => 'value1'],
                    ['@context' => 'schema.org'],
                ],
            ],
            'filter by block2, no pluck' => [
                'blocks'       => $blocks,
                'selectorId'   => 'block2',
                'pluckJsonKey' => false,
                'expected'     => [
                    [
                        'id'    => 'block2',
                        'type'  => 'application/json',
                        'bytes' => 10,
                        'attrs' => ['id' => 'block2', 'class' => null],
                        'json'  => ['data' => 'value2'],
                    ],
                ],
            ],
            'filter by block2, pluck json' => [
                'blocks'       => $blocks,
                'selectorId'   => 'block2',
                'pluckJsonKey' => true,
                'expected'     => [
                    ['data' => 'value2'],
                ],
            ],
            'filter by empty id, no pluck' => [
                'blocks'       => $blocks,
                'selectorId'   => '',
                'pluckJsonKey' => false,
                'expected'     => [
                    [
                        'id'    => '',
                        'type'  => 'application/json',
                        'bytes' => 8,
                        'attrs' => ['id' => null, 'class' => null],
                        'json'  => ['empty' => 'id'],
                    ],
                ],
            ],
            'filter by empty id, pluck json' => [
                'blocks'       => $blocks,
                'selectorId'   => '',
                'pluckJsonKey' => true,
                'expected'     => [
                    ['empty' => 'id'],
                ],
            ],
            'filter by non-existent id, no pluck' => [
                'blocks'       => $blocks,
                'selectorId'   => 'non-existent',
                'pluckJsonKey' => false,
                'expected'     => [],
            ],
            'filter by non-existent id, pluck json' => [
                'blocks'       => $blocks,
                'selectorId'   => 'non-existent',
                'pluckJsonKey' => true,
                'expected'     => [],
            ],
        ];
    }

    #[DataProvider('provideFilterJsonBlocksBySelectorTestCases')]
    public function test_filter_json_blocks_by_selector(array $blocks, ?string $selectorId, bool $pluckJsonKey, array $expected): void
    {
        $result = filter_json_blocks_by_selector($blocks, $selectorId, $pluckJsonKey);
        $this->assertEquals($expected, $result);
    }

    public function test_filter_json_blocks_by_selector_empty_blocks_array(): void
    {
        $result = filter_json_blocks_by_selector([], 'any-id', false);
        $this->assertEquals([], $result);

        $result = filter_json_blocks_by_selector([], null, true);
        $this->assertEquals([], $result);
    }

    public function test_filter_json_blocks_by_selector_handles_null_json_when_plucking(): void
    {
        $blocks = [
            [
                'id'    => 'failed-decode',
                'type'  => 'application/json',
                'bytes' => 5,
                'attrs' => ['id' => 'failed-decode', 'class' => null],
                'json'  => null,
            ],
        ];

        $result = filter_json_blocks_by_selector($blocks, null, true);
        $this->assertEquals([[]], $result);
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

    public static function provideInferLaravelTypeCases(): array
    {
        return [
            'null => string'                 => [null, 'string'],
            'short string => string'         => ['hello', 'string'],
            'long string => text'            => [str_repeat('a', 300), 'text'],
            'float => float'                 => [3.14, 'float'],
            'integer => integer'             => [42, 'integer'],
            'big int => bigInteger'          => [9223372036854, 'bigInteger'],
            'negative big int => bigInteger' => [-5000000000, 'bigInteger'],
            'boolean => boolean'             => [true, 'boolean'],
            'array/object => text'           => [['a' => 1], 'text'],
        ];
    }

    #[DataProvider('provideInferLaravelTypeCases')]
    public function test_infer_laravel_type(mixed $value, string $expected): void
    {
        $this->assertSame($expected, infer_laravel_type($value));
    }

    public function test_inspect_json_types_defaults(): void
    {
        $data = [
            'user' => [
                'name' => 'Alice',
                'age'  => 30,
                'tags' => ['a', 'b'],
            ],
            'active' => true,
        ];

        $types = inspect_json_types($data);

        $this->assertSame([
            'user.name' => 'string',
            'user.age'  => 'integer',
            'user.tags' => 'array',
            'active'    => 'boolean',
        ], $types);
    }

    public function test_inspect_json_types_with_custom_delimiter(): void
    {
        $data = [
            'user' => [
                'name' => 'Alice',
                'age'  => 30,
                'tags' => ['a', 'b'],
            ],
            'active' => true,
        ];

        $types = inspect_json_types($data, delimiter: '__');

        $this->assertSame([
            'user__name' => 'string',
            'user__age'  => 'integer',
            'user__tags' => 'array',
            'active'     => 'boolean',
        ], $types);
    }

    public function test_inspect_json_types_with_infer(): void
    {
        $data = [
            'user' => [
                'name' => 'Alice',
                'age'  => 30,
                'tags' => ['a', 'b'],
            ],
            'active' => true,
        ];

        $types = inspect_json_types($data, infer: true);

        $this->assertSame([
            'user.name' => 'string',
            'user.age'  => 'integer',
            'user.tags' => 'text', // List array treated as text
            'active'    => 'boolean',
        ], $types);
    }

    public function test_inspect_json_types_with_custom_delimiter_and_infer(): void
    {
        $data = [
            'user' => [
                'name' => 'Alice',
                'age'  => 30,
                'tags' => ['a', 'b'],
            ],
            'active' => true,
        ];

        $types = inspect_json_types($data, delimiter: '__', infer: true);

        $this->assertSame([
            'user__name' => 'string',
            'user__age'  => 'integer',
            'user__tags' => 'text', // List array treated as text
            'active'     => 'boolean',
        ], $types);
    }

    public function test_inspect_json_types_empty_arrays(): void
    {
        $data = [
            'empty_obj' => [],
            'nested'    => ['also_empty' => []],
        ];
        $types = inspect_json_types($data);
        $this->assertSame([
            'empty_obj'         => 'array',
            'nested.also_empty' => 'array',
        ], $types);
    }

    public function test_inspect_json_types_mixed_array_types_handling(): void
    {
        $data = [
            'mixed' => ['a', 'b', 'key' => 'value'],
        ];
        $types = inspect_json_types($data);
        $this->assertSame([
            'mixed.0'   => 'string',
            'mixed.1'   => 'string',
            'mixed.key' => 'string',
        ], $types);
    }

    public function test_types_to_columns_renders_lines(): void
    {
        $types = [
            'user__name' => 'string',
            'user__bio'  => 'text',
            'user__age'  => 'integer',
        ];

        $expected = "string('user__name')" . PHP_EOL
                  . "text('user__bio')" . PHP_EOL
                  . "integer('user__age')" . PHP_EOL;

        $this->assertSame($expected, types_to_columns($types));
    }

    public function test_get_json_value_by_path_default_delimiter(): void
    {
        $data = [
            'user' => [
                'address' => [
                    'city' => 'Paris',
                ],
            ],
        ];

        $this->assertSame('Paris', get_json_value_by_path($data, 'user.address.city'));
        $this->assertNull(get_json_value_by_path($data, 'user.address.zip'));
    }

    public function test_get_json_value_by_path_custom_delimiter(): void
    {
        $data = [
            'user' => [
                'address' => [
                    'city' => 'Paris',
                ],
            ],
        ];

        $this->assertSame('Paris', get_json_value_by_path($data, 'user__address__city', delimiter: '__'));
    }

    public function test_get_json_value_by_path_non_array_intermediate_returns_null(): void
    {
        $data = ['user' => 'not-an-array'];
        $this->assertNull(get_json_value_by_path($data, 'user.name'));
    }

    public function test_get_json_value_by_path_empty_path_returns_null(): void
    {
        $data = ['simple' => 1];
        $this->assertNull(get_json_value_by_path($data, ''));
    }

    public function test_get_json_value_by_path_single_key_path(): void
    {
        $data = ['simple' => 123];
        $this->assertSame(123, get_json_value_by_path($data, 'simple'));
    }

    public function test_get_json_value_by_path_deep_nesting_returns_value(): void
    {
        $data = ['a' => ['b' => ['c' => ['d' => 'value']]]];
        $this->assertSame('value', get_json_value_by_path($data, 'a.b.c.d'));
    }

    public function test_extract_values_by_paths_default_delimiter(): void
    {
        $data = [
            'user' => [
                'name' => 'Alice',
                'age'  => 30,
            ],
        ];
        $paths = ['user.name', 'user.age', 'user.missing'];

        $result = extract_values_by_paths($data, $paths);

        $this->assertSame([
            'user.name'    => 'Alice',
            'user.age'     => 30,
            'user.missing' => null,
        ], $result);
    }

    public function test_extract_values_by_paths_custom_delimiter(): void
    {
        $data = [
            'user' => [
                'name' => 'Alice',
                'age'  => 30,
            ],
        ];
        $paths = ['user__name', 'user__age'];

        $result = extract_values_by_paths($data, $paths, delimiter: '__');

        $this->assertSame([
            'user__name' => 'Alice',
            'user__age'  => 30,
        ], $result);
    }

    private function createTempMigrationFile(string $tableName): string
    {
        $php = <<<PHP
<?php
return new class {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::create('{$tableName}', function (\$table) {
            \$table->id();
            \$table->string('name')->nullable();
            \$table->timestamps();
        });
    }
};
PHP;

        $path = sys_get_temp_dir() . '/test_migration_' . uniqid() . '.php';
        file_put_contents($path, $php);

        return $path;
    }

    public function test_ensure_table_exists_creates_table_when_missing(): void
    {
        $tableName     = 'test_ensure_table_missing';
        $migrationFile = $this->createTempMigrationFile($tableName);

        // Ensure table doesn't exist
        Schema::dropIfExists($tableName);
        $this->assertFalse(Schema::hasTable($tableName));

        // Test the function
        ensure_table_exists($tableName, $migrationFile);

        // Verify table was created
        $this->assertTrue(Schema::hasTable($tableName));

        // Cleanup
        Schema::dropIfExists($tableName);
        unlink($migrationFile);
    }

    public function test_ensure_table_exists_does_nothing_when_table_exists(): void
    {
        $tableName = 'test_ensure_table_existing';

        // Create the table first
        Schema::dropIfExists($tableName);
        Schema::create($tableName, function ($table) {
            $table->id();
            $table->string('original_column'); // Not in createTempMigrationFile()
        });
        $this->assertTrue(Schema::hasTable($tableName));

        // Create migration that would add different column (shouldn't be called)
        $migrationFile = $this->createTempMigrationFile($tableName);

        // Test the function - should not modify existing table
        ensure_table_exists($tableName, $migrationFile);

        // Table should still exist with original structure
        $this->assertTrue(Schema::hasTable($tableName));
        $this->assertTrue(Schema::hasColumn($tableName, 'original_column'));
        $this->assertFalse(Schema::hasColumn($tableName, 'name')); // Migration column not added

        // Cleanup
        Schema::dropIfExists($tableName);
        unlink($migrationFile);
    }

    public function test_ensure_table_exists_throws_exception_when_migration_file_missing(): void
    {
        $tableName                = 'test_ensure_table_nonexistent';
        $nonExistentMigrationFile = '/path/to/nonexistent/migration.php';

        // Ensure table doesn't exist
        Schema::dropIfExists($tableName);

        $this->expectException(\RuntimeException::class);

        ensure_table_exists($tableName, $nonExistentMigrationFile);
    }

    public static function kdpTrimSizeInchesProvider(): array
    {
        return [
            // Regular trim sizes (width <= 6.12 AND height <= 9.0)
            'regular 6 x 9' => [
                'dimensions' => '6 x 0.45 x 9 inches',
                'expected'   => 'regular',
            ],
            'regular 5.5 x 8.5' => [
                'dimensions' => '5.5 x 0.3 x 8.5 inches',
                'expected'   => 'regular',
            ],
            'regular 5 x 8' => [
                'dimensions' => '5 x 0.25 x 8 inches',
                'expected'   => 'regular',
            ],
            'regular 6.12 x 9.0 boundary' => [
                'dimensions' => '6.12 x 0.5 x 9.0 inches',
                'expected'   => 'regular',
            ],

            // Large trim sizes (width > 6.12 OR height > 9.0)
            'large 8.5 x 11' => [
                'dimensions' => '8.5 x 0.24 x 11 inches',
                'expected'   => 'large',
            ],
            'large 7 x 10' => [
                'dimensions' => '7 x 0.5 x 10 inches',
                'expected'   => 'large',
            ],
            'large 11.4 x 8.3' => [
                'dimensions' => '11.4 x 8.3 x 0.21 inches',
                'expected'   => 'large',
            ],
            'large square 9.84 x 9.84' => [
                'dimensions' => '9.84 x 9.84 x 0.2 inches',
                'expected'   => 'large',
            ],
            'large width exceeds 6.12' => [
                'dimensions' => '6.13 x 0.3 x 9.0 inches',
                'expected'   => 'large',
            ],
            'large height exceeds 9.0' => [
                'dimensions' => '6.0 x 0.3 x 9.01 inches',
                'expected'   => 'large',
            ],

            // Different formats
            'dimensions with x separator' => [
                'dimensions' => '6 x 9 inches',
                'expected'   => 'regular',
            ],
            'dimensions with × separator' => [
                'dimensions' => '6 × 9 × 0.5 inches',
                'expected'   => 'regular',
            ],
            'dimensions with spaces' => [
                'dimensions' => '6   x   9   inches',
                'expected'   => 'regular',
            ],
            'dimensions lowercase inches' => [
                'dimensions' => '6 x 9 inches',
                'expected'   => 'regular',
            ],
            'dimensions uppercase INCHES' => [
                'dimensions' => '6 x 9 INCHES',
                'expected'   => 'regular',
            ],
            'dimensions mixed case Inches' => [
                'dimensions' => '6 x 9 Inches',
                'expected'   => 'regular',
            ],
            'dimensions with inch (singular)' => [
                'dimensions' => '6 x 9 inch',
                'expected'   => 'regular',
            ],

            // Order variations (function takes two largest)
            'dimensions thickness first' => [
                'dimensions' => '0.5 x 6 x 9 inches',
                'expected'   => 'regular',
            ],
            'dimensions height first' => [
                'dimensions' => '9 x 6 x 0.5 inches',
                'expected'   => 'regular',
            ],
            'dimensions width first' => [
                'dimensions' => '6 x 9 x 0.5 inches',
                'expected'   => 'regular',
            ],

            // Edge cases that should return null
            'no inch keyword' => [
                'dimensions' => '6 x 9 cm',
                'expected'   => null,
            ],
            'no numbers' => [
                'dimensions' => 'six by nine inches',
                'expected'   => null,
            ],
            'only one number' => [
                'dimensions' => '6 inches',
                'expected'   => null,
            ],
            'empty string' => [
                'dimensions' => '',
                'expected'   => null,
            ],
            'only inch keyword' => [
                'dimensions' => 'inches',
                'expected'   => null,
            ],
        ];
    }

    #[DataProvider('kdpTrimSizeInchesProvider')]
    public function test_kdp_trim_size_inches(string $dimensions, ?string $expected): void
    {
        $result = kdp_trim_size_inches($dimensions);
        $this->assertSame($expected, $result);
    }

    public function test_kdp_trim_size_inches_with_decimal_precision(): void
    {
        // Test that 6.12 x 9.0 is regular (boundary)
        $this->assertSame('regular', kdp_trim_size_inches('6.12 x 9.0 inches'));

        // Test that 6.13 x 9.0 is large (just over width boundary)
        $this->assertSame('large', kdp_trim_size_inches('6.13 x 9.0 inches'));

        // Test that 6.12 x 9.01 is large (just over height boundary)
        $this->assertSame('large', kdp_trim_size_inches('6.12 x 9.01 inches'));
    }

    public function test_kdp_trim_size_inches_ignores_thickness(): void
    {
        // Thickness (smallest dimension) should be ignored
        // These should all be regular (6 x 9)
        $this->assertSame('regular', kdp_trim_size_inches('6 x 9 x 0.1 inches'));
        $this->assertSame('regular', kdp_trim_size_inches('6 x 9 x 0.5 inches'));
        $this->assertSame('regular', kdp_trim_size_inches('6 x 9 x 1.0 inches'));

        // These should all be large (8.5 x 11)
        $this->assertSame('large', kdp_trim_size_inches('8.5 x 11 x 0.1 inches'));
        $this->assertSame('large', kdp_trim_size_inches('8.5 x 11 x 0.5 inches'));
        $this->assertSame('large', kdp_trim_size_inches('8.5 x 11 x 1.0 inches'));
    }

    public function test_kdp_trim_size_inches_with_extra_text(): void
    {
        // Should still parse dimensions even with extra text
        $this->assertSame('regular', kdp_trim_size_inches('Product Dimensions: 6 x 9 inches'));
        $this->assertSame('large', kdp_trim_size_inches('Size: 8.5 x 11 inches (Letter)'));
        $this->assertSame('regular', kdp_trim_size_inches('Paperback: 6 x 0.5 x 9 inches; Weight: 1 lb'));
    }

    public static function kdpPrintCostUsProvider(): array
    {
        return [
            // Black ink, small trim
            'black 24 pages small' => [
                'numPages'        => 24,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expected'        => 2.3, // Fixed cost only (24-108 pages)
            ],
            'black 108 pages small' => [
                'numPages'        => 108,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expected'        => 2.3, // Fixed cost only (boundary)
            ],
            'black 109 pages small' => [
                'numPages'        => 109,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expected'        => 1 + (109 * 0.012), // Fixed + per-page
            ],
            'black 200 pages small' => [
                'numPages'        => 200,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expected'        => 1 + (200 * 0.012), // 1 + 2.4 = 3.4
            ],
            'black 828 pages small' => [
                'numPages'        => 828,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expected'        => 1 + (828 * 0.012), // Maximum pages
            ],

            // Black ink, large trim
            'black 24 pages large' => [
                'numPages'        => 24,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => true,
                'expected'        => 2.84, // Fixed cost only
            ],
            'black 108 pages large' => [
                'numPages'        => 108,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => true,
                'expected'        => 2.84, // Fixed cost only (boundary)
            ],
            'black 109 pages large' => [
                'numPages'        => 109,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => true,
                'expected'        => 1 + (109 * 0.017), // Fixed + per-page
            ],
            'black 200 pages large' => [
                'numPages'        => 200,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => true,
                'expected'        => 1 + (200 * 0.017), // 1 + 3.4 = 4.4
            ],

            // Premium color, small trim
            'premium color 24 pages small' => [
                'numPages'        => 24,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => false,
                'expected'        => 3.6, // Fixed cost only (24-40 pages)
            ],
            'premium color 40 pages small' => [
                'numPages'        => 40,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => false,
                'expected'        => 3.6, // Fixed cost only (boundary)
            ],
            'premium color 41 pages small' => [
                'numPages'        => 41,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => false,
                'expected'        => 1 + (41 * 0.065), // Fixed + per-page
            ],
            'premium color 100 pages small' => [
                'numPages'        => 100,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => false,
                'expected'        => 1 + (100 * 0.065), // 1 + 6.5 = 7.5
            ],

            // Premium color, large trim
            'premium color 24 pages large' => [
                'numPages'        => 24,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => true,
                'expected'        => 4.2, // Fixed cost only
            ],
            'premium color 40 pages large' => [
                'numPages'        => 40,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => true,
                'expected'        => 4.2, // Fixed cost only (boundary)
            ],
            'premium color 41 pages large' => [
                'numPages'        => 41,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => true,
                'expected'        => 1 + (41 * 0.08), // Fixed + per-page
            ],
            'premium color 100 pages large' => [
                'numPages'        => 100,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => true,
                'expected'        => 1 + (100 * 0.08), // 1 + 8 = 9
            ],

            // Standard color, small trim
            'standard color 72 pages small' => [
                'numPages'        => 72,
                'isColor'         => true,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expected'        => 1 + (72 * 0.0255), // Minimum for standard color
            ],
            'standard color 200 pages small' => [
                'numPages'        => 200,
                'isColor'         => true,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expected'        => 1 + (200 * 0.0255), // 1 + 5.1 = 6.1
            ],
            'standard color 600 pages small' => [
                'numPages'        => 600,
                'isColor'         => true,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expected'        => 1 + (600 * 0.0255), // Maximum for standard color
            ],

            // Standard color, large trim
            'standard color 72 pages large' => [
                'numPages'        => 72,
                'isColor'         => true,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => true,
                'expected'        => 1 + (72 * 0.0402), // Minimum for standard color
            ],
            'standard color 200 pages large' => [
                'numPages'        => 200,
                'isColor'         => true,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => true,
                'expected'        => 1 + (200 * 0.0402), // 1 + 8.04 = 9.04
            ],
            'standard color 600 pages large' => [
                'numPages'        => 600,
                'isColor'         => true,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => true,
                'expected'        => 1 + (600 * 0.0402), // Maximum for standard color
            ],
        ];
    }

    #[DataProvider('kdpPrintCostUsProvider')]
    public function test_kdp_print_cost_us(
        int $numPages,
        bool $isColor,
        bool $isPremiumInk,
        bool $isTrimSizeLarge,
        float $expected
    ): void {
        $result = kdp_print_cost_us($numPages, $isColor, $isPremiumInk, $isTrimSizeLarge);
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    public function test_kdp_print_cost_us_auto_adjusts_minimum_pages_for_standard_color(): void
    {
        // Standard color minimum is 72, but function auto-adjusts from lower values
        $result   = kdp_print_cost_us(50, isColor: true, isPremiumInk: false);
        $expected = 1 + (72 * 0.0255); // Should use 72 pages
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    public function test_kdp_print_cost_us_auto_adjusts_minimum_pages_for_black(): void
    {
        // Black ink minimum is 24, but function auto-adjusts from lower values
        $result   = kdp_print_cost_us(10, isColor: false);
        $expected = 2.3; // Should use 24 pages (fixed cost only)
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    public function test_kdp_print_cost_us_throws_exception_for_too_many_pages(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        kdp_print_cost_us(829);
    }

    public function test_kdp_print_cost_us_throws_exception_for_standard_color_too_many_pages(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        kdp_print_cost_us(601, isColor: true, isPremiumInk: false);
    }

    public static function kdpRoyaltyUsProvider(): array
    {
        return [
            // 50% royalty rate (list price <= 9.98)
            'low price 50% rate' => [
                'listPrice'       => 5.00,
                'numPages'        => 100,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expectedRoyalty' => (0.5 * 5.00) - 2.3, // 2.5 - 2.3 = 0.2
            ],
            'boundary price 50% rate' => [
                'listPrice'       => 9.98,
                'numPages'        => 100,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expectedRoyalty' => (0.5 * 9.98) - 2.3, // 4.99 - 2.3 = 2.69
            ],

            // 60% royalty rate (list price > 9.98)
            'high price 60% rate' => [
                'listPrice'       => 9.99,
                'numPages'        => 100,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expectedRoyalty' => (0.6 * 9.99) - 2.3, // 5.994 - 2.3 = 3.694
            ],
            'typical book price' => [
                'listPrice'       => 15.99,
                'numPages'        => 200,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expectedRoyalty' => (0.6 * 15.99) - (1 + (200 * 0.012)), // 9.594 - 3.4 = 6.194
            ],

            // Color books
            'premium color book' => [
                'listPrice'       => 29.99,
                'numPages'        => 100,
                'isColor'         => true,
                'isPremiumInk'    => true,
                'isTrimSizeLarge' => false,
                'expectedRoyalty' => (0.6 * 29.99) - (1 + (100 * 0.065)), // 17.994 - 7.5 = 10.494
            ],
            'standard color book' => [
                'listPrice'       => 19.99,
                'numPages'        => 200,
                'isColor'         => true,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expectedRoyalty' => (0.6 * 19.99) - (1 + (200 * 0.0255)), // 11.994 - 6.1 = 5.894
            ],

            // Large trim size
            'large trim black' => [
                'listPrice'       => 12.99,
                'numPages'        => 200,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => true,
                'expectedRoyalty' => (0.6 * 12.99) - (1 + (200 * 0.017)), // 7.794 - 4.4 = 3.394
            ],

            // Negative royalty (print cost exceeds royalty)
            'negative royalty' => [
                'listPrice'       => 3.00,
                'numPages'        => 200,
                'isColor'         => false,
                'isPremiumInk'    => false,
                'isTrimSizeLarge' => false,
                'expectedRoyalty' => (0.5 * 3.00) - (1 + (200 * 0.012)), // 1.5 - 3.4 = -1.9
            ],
        ];
    }

    #[DataProvider('kdpRoyaltyUsProvider')]
    public function test_kdp_royalty_us(
        float $listPrice,
        int $numPages,
        bool $isColor,
        bool $isPremiumInk,
        bool $isTrimSizeLarge,
        float $expectedRoyalty
    ): void {
        $result = kdp_royalty_us($listPrice, $numPages, $isColor, $isPremiumInk, $isTrimSizeLarge);
        $this->assertEqualsWithDelta($expectedRoyalty, $result, 0.01);
    }

    public function test_kdp_royalty_us_uses_correct_rate_at_threshold(): void
    {
        // Test that 9.98 uses 50% rate
        $result1   = kdp_royalty_us(9.98, 100, false, false, false);
        $printCost = 2.3;
        $expected1 = (0.5 * 9.98) - $printCost;
        $this->assertEqualsWithDelta($expected1, $result1, 0.01);

        // Test that 9.99 uses 60% rate
        $result2   = kdp_royalty_us(9.99, 100, false, false, false);
        $expected2 = (0.6 * 9.99) - $printCost;
        $this->assertEqualsWithDelta($expected2, $result2, 0.01);

        // Verify they're different
        $this->assertNotEquals($result1, $result2);
    }

    public function test_kdp_royalty_us_throws_exception_for_invalid_page_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        kdp_royalty_us(15.99, 829, false, false, false);
    }

    public function test_bsr_to_monthly_sales_books_with_null(): void
    {
        $result = bsr_to_monthly_sales_books(null);
        $this->assertNull($result);
    }

    public function test_bsr_to_monthly_sales_books_tier_1_high_volume(): void
    {
        // BSR 1-100: High-volume sellers
        $result = bsr_to_monthly_sales_books(1);
        $this->assertIsFloat($result);
        $this->assertGreaterThan(0, $result);

        // BSR 1 should give highest sales
        $bsr1   = bsr_to_monthly_sales_books(1);
        $bsr100 = bsr_to_monthly_sales_books(100);
        $this->assertGreaterThan($bsr100, $bsr1);
    }

    public function test_bsr_to_monthly_sales_books_tier_2_mid_range(): void
    {
        // BSR 101-100,000: Mid-range sellers
        $result = bsr_to_monthly_sales_books(1000);
        $this->assertIsFloat($result);
        $this->assertGreaterThan(0, $result);

        // Lower BSR should give higher sales
        $bsr101    = bsr_to_monthly_sales_books(101);
        $bsr100000 = bsr_to_monthly_sales_books(100000);
        $this->assertGreaterThan($bsr100000, $bsr101);
    }

    public function test_bsr_to_monthly_sales_books_tier_3_long_tail(): void
    {
        // BSR 100,001+: Long-tail sellers
        $result = bsr_to_monthly_sales_books(500000);
        $this->assertIsFloat($result);
        $this->assertGreaterThan(0, $result);

        // Lower BSR should give higher sales
        $bsr100001  = bsr_to_monthly_sales_books(100001);
        $bsr1000000 = bsr_to_monthly_sales_books(1000000);
        $this->assertGreaterThan($bsr1000000, $bsr100001);
    }

    public function test_bsr_to_monthly_sales_books_boundary_values(): void
    {
        // Test boundary between tier 1 and tier 2
        // Note: There may be a discontinuity at boundaries due to different formulas
        $bsr100 = bsr_to_monthly_sales_books(100);
        $bsr101 = bsr_to_monthly_sales_books(101);
        // Both should be positive
        $this->assertGreaterThan(0, $bsr100);
        $this->assertGreaterThan(0, $bsr101);

        // Test boundary between tier 2 and tier 3
        $bsr100000 = bsr_to_monthly_sales_books(100000);
        $bsr100001 = bsr_to_monthly_sales_books(100001);
        // Both should be positive
        $this->assertGreaterThan(0, $bsr100000);
        $this->assertGreaterThan(0, $bsr100001);
    }

    public static function bsrToMonthlySalesBooksProvider(): array
    {
        return [
            'BSR 1 (best seller)' => [
                'bsr'      => 1,
                'expected' => 84175.0,
            ],
            'BSR 10 (top 10)' => [
                'bsr'      => 10,
                'expected' => 29253.86,
            ],
            'BSR 100 (tier 1 boundary)' => [
                'bsr'      => 100,
                'expected' => 10166.77,
            ],
            'BSR 101 (tier 2 start)' => [
                'bsr'      => 101,
                'expected' => 11234.31,
            ],
            'BSR 1,000 (mid-range)' => [
                'bsr'      => 1000,
                'expected' => 1940.24,
            ],
            'BSR 10,000 (mid-range)' => [
                'bsr'      => 10000,
                'expected' => 332.55,
            ],
            'BSR 100,000 (tier 2 boundary)' => [
                'bsr'      => 100000,
                'expected' => 57.00,
            ],
            'BSR 100,001 (tier 3 start)' => [
                'bsr'      => 100001,
                'expected' => 48.15,
            ],
            'BSR 500,000 (long tail)' => [
                'bsr'      => 500000,
                'expected' => 9.91,
            ],
            'BSR 1,000,000 (deep long tail)' => [
                'bsr'      => 1000000,
                'expected' => 5.02,
            ],
            'BSR 5,000,000 (very deep long tail)' => [
                'bsr'      => 5000000,
                'expected' => 1.03,
            ],
            'BSR 10,000,000 (extremely deep long tail)' => [
                'bsr'      => 10000000,
                'expected' => 0.52,
            ],
        ];
    }

    #[DataProvider('bsrToMonthlySalesBooksProvider')]
    public function test_bsr_to_monthly_sales_books_with_data_provider(
        int $bsr,
        float $expected
    ): void {
        $result = bsr_to_monthly_sales_books($bsr);

        $this->assertIsFloat($result);
        // Use delta of 0.01 for floating-point comparison
        $this->assertEqualsWithDelta($expected, $result, 0.01, "BSR {$bsr} should produce approximately {$expected} monthly sales");
    }
}
