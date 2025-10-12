<?php

declare(strict_types=1);

namespace FOfX\Utility\Tests\Unit;

use FOfX\Utility\Tests\TestCase;
use FOfX\Utility\AmazonProductPageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Mockery;

class AmazonProductPageParserTest extends TestCase
{
    private AmazonProductPageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AmazonProductPageParser();
    }

    public function test_get_and_set_amazon_products_table(): void
    {
        $this->assertEquals('amazon_products', $this->parser->getAmazonProductsTable());

        $this->parser->setAmazonProductsTable('custom_amazon_products');
        $this->assertEquals('custom_amazon_products', $this->parser->getAmazonProductsTable());
    }

    public function test_get_and_set_amazon_products_migration_path(): void
    {
        // The default path is relative to src/ directory
        $actualPath = $this->parser->getAmazonProductsMigrationPath();
        $this->assertStringEndsWith('database/migrations/2025_10_05_194500_create_amazon_products_table.php', $actualPath);
        $this->assertStringContainsString('database', $actualPath);

        $customPath = '/custom/path/to/migration.php';
        $this->parser->setAmazonProductsMigrationPath($customPath);
        $this->assertEquals($customPath, $this->parser->getAmazonProductsMigrationPath());
    }

    public function test_get_and_set_amazon_keywords_stats_table(): void
    {
        $this->assertEquals('amazon_keywords_stats', $this->parser->getAmazonKeywordsStatsTable());

        $this->parser->setAmazonKeywordsStatsTable('custom_amazon_keywords_stats');
        $this->assertEquals('custom_amazon_keywords_stats', $this->parser->getAmazonKeywordsStatsTable());
    }

    public function test_get_and_set_amazon_keywords_stats_table_migration_path(): void
    {
        // The default path is relative to src/ directory
        $actualPath = $this->parser->getAmazonKeywordsStatsTableMigrationPath();
        $this->assertStringEndsWith('database/migrations/2025_10_06_111107_create_amazon_keywords_stats_table.php', $actualPath);
        $this->assertStringContainsString('database', $actualPath);

        $customPath = '/custom/path/to/migration.php';
        $this->parser->setAmazonKeywordsStatsTableMigrationPath($customPath);
        $this->assertEquals($customPath, $this->parser->getAmazonKeywordsStatsTableMigrationPath());
    }

    public function test_get_and_set_dataforseo_merchant_amazon_products_listings_table(): void
    {
        $this->assertEquals('dataforseo_merchant_amazon_products_listings', $this->parser->getDataforseoMerchantAmazonProductsListingsTable());

        $this->parser->setDataforseoMerchantAmazonProductsListingsTable('custom_listings_table');
        $this->assertEquals('custom_listings_table', $this->parser->getDataforseoMerchantAmazonProductsListingsTable());
    }

    public function test_get_and_set_dataforseo_merchant_amazon_products_items_table(): void
    {
        $this->assertEquals('dataforseo_merchant_amazon_products_items', $this->parser->getDataforseoMerchantAmazonProductsItemsTable());

        $this->parser->setDataforseoMerchantAmazonProductsItemsTable('custom_items_table');
        $this->assertEquals('custom_items_table', $this->parser->getDataforseoMerchantAmazonProductsItemsTable());
    }

    public function test_get_and_set_stats_items_limit(): void
    {
        $this->assertEquals(10, $this->parser->getStatsItemsLimit());

        $this->parser->setStatsItemsLimit(20);
        $this->assertEquals(20, $this->parser->getStatsItemsLimit());
    }

    public function test_get_and_set_stats_amazon_products_limit(): void
    {
        $this->assertEquals(3, $this->parser->getStatsAmazonProductsLimit());

        $this->parser->setStatsAmazonProductsLimit(5);
        $this->assertEquals(5, $this->parser->getStatsAmazonProductsLimit());
    }

    public function test_get_and_set_json_flags(): void
    {
        $expectedFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $this->assertEquals($expectedFlags, $this->parser->getJsonFlags());

        $customFlags = JSON_UNESCAPED_SLASHES;
        $this->parser->setJsonFlags($customFlags);
        $this->assertEquals($customFlags, $this->parser->getJsonFlags());
    }

    public static function cleanLabelProvider(): array
    {
        return [
            'empty string'                      => ['', ''],
            'simple text'                       => ['Publisher', 'Publisher'],
            'removes trailing colon'            => ['Publisher:', 'Publisher'],
            'removes trailing colon with space' => ['Publisher :', 'Publisher'],
            'removes bidi marks'                => ["Test\u{200E}Label\u{200F}", 'TestLabel'],
            'removes bidi marks and colon'      => ["Publisher\u{200E}:", 'Publisher'],
            'trims whitespace'                  => ['  Publisher  ', 'Publisher'],
            'trims whitespace and colon'        => ['  Publisher :  ', 'Publisher'],
        ];
    }

    #[DataProvider('cleanLabelProvider')]
    public function test_clean_label_with_data_provider(string $input, string $expected): void
    {
        $result = $this->parser->cleanLabel($input);
        $this->assertEquals($expected, $result);
    }

    public static function cleanValueProvider(): array
    {
        return [
            'empty string'                   => ['', ''],
            'simple text'                    => ['Hello World', 'Hello World'],
            'removes bidi marks'             => ["Test\u{200E}Value\u{200F}", 'TestValue'],
            'trims whitespace'               => ["  Test Value  \n\r\t", 'Test Value'],
            'preserves colons in dimensions' => ['5.5 x 3.2 x 1:', '5.5 x 3.2 x 1:'],
            'preserves colons in time'       => ['12:30 PM', '12:30 PM'],
            'preserves colons in ratio'      => ['16:9', '16:9'],
        ];
    }

    #[DataProvider('cleanValueProvider')]
    public function test_clean_value_with_data_provider(string $input, string $expected): void
    {
        $result = $this->parser->cleanValue($input);
        $this->assertEquals($expected, $result);
    }

    public static function extractAsinProvider(): array
    {
        return [
            'no canonical url' => [
                '<html><body><h1>No canonical</h1></body></html>',
                null,
            ],
            'canonical with /dp/ pattern' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/Product-Name/dp/B0FNY2674D"/></head></html>',
                'B0FNY2674D',
            ],
            'canonical with /gp/product/ pattern' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/gp/product/B00JM5GW10"/></head></html>',
                'B00JM5GW10',
            ],
            'canonical with ISBN as ASIN' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/Book-Title/dp/0385546890"/></head></html>',
                '0385546890',
            ],
            'canonical with numeric ISBN' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/Book/dp/1934081205"/></head></html>',
                '1934081205',
            ],
            'canonical with lowercase asin' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/product/dp/b0bjmsh4jx"/></head></html>',
                'b0bjmsh4jx',
            ],
            'canonical with query parameters' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/Product/dp/B07D4T2XKB?ref=test"/></head></html>',
                'B07D4T2XKB',
            ],
            'canonical with fragment' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/Product/dp/B0CTKW2T18#reviews"/></head></html>',
                'B0CTKW2T18',
            ],
            'canonical without ASIN pattern' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/some-page"/></head></html>',
                null,
            ],
            'canonical with short ASIN (invalid)' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/dp/B0FNY267"/></head></html>',
                null,
            ],
            'canonical with long ASIN (invalid)' => [
                '<html><head><link rel="canonical" href="https://www.amazon.com/dp/B0FNY2674D99"/></head></html>',
                null,
            ],
        ];
    }

    #[DataProvider('extractAsinProvider')]
    public function test_extract_asin_with_data_provider(string $html, ?string $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractAsin($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractTitleProvider(): array
    {
        return [
            'no title' => [
                '<html><body><h1>No product title</h1></body></html>',
                null,
            ],
            'productTitle selector' => [
                '<html><body><span id="productTitle">Test Product Title</span></body></html>',
                'Test Product Title',
            ],
            'title with whitespace' => [
                '<html><body><span id="productTitle">  Test Product  </span></body></html>',
                'Test Product',
            ],
            'fallback title selector' => [
                '<html><body><h1 id="title">Fallback Title</h1></body></html>',
                'Fallback Title',
            ],
        ];
    }

    #[DataProvider('extractTitleProvider')]
    public function test_extract_title_with_data_provider(string $html, ?string $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractTitle($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractDescriptionProvider(): array
    {
        return [
            'no description' => [
                '<html><body><p>No description here</p></body></html>',
                null,
            ],
            'feature bullets single' => [
                '<html><body><div id="feature-bullets"><ul><li><span>This is a great product description with enough text</span></li></ul></div></body></html>',
                'This is a great product description with enough text',
            ],
            'feature bullets multiple' => [
                '<html><body><div id="feature-bullets"><ul><li><span>First feature point here</span></li><li><span>Second feature point here</span></li></ul></div></body></html>',
                'First feature point here Second feature point here',
            ],
            'description too short' => [
                '<html><body><div id="feature-bullets">Short</div></body></html>',
                null,
            ],
            'filters out bullets less than 10 chars' => [
                '<html><body><div id="feature-bullets"><ul><li><span>Short</span></li><li><span>This bullet is long enough to be included</span></li><li><span>Tiny</span></li></ul></div></body></html>',
                'This bullet is long enough to be included',
            ],
        ];
    }

    #[DataProvider('extractDescriptionProvider')]
    public function test_extract_description_with_data_provider(string $html, ?string $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractDescription($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractCurrencyProvider(): array
    {
        return [
            'no currency' => [
                '<html><body><p>No currency</p></body></html>',
                null,
            ],
            'currency from data attribute' => [
                '<html><body><div data-currency="usd">Price</div></body></html>',
                'USD',
            ],
            'currency from price symbol USD' => [
                '<html><body><span class="a-price"><span class="a-price-symbol">$</span></span></body></html>',
                'USD',
            ],
            'currency from price symbol EUR' => [
                '<html><body><span class="a-price"><span class="a-price-symbol">€</span></span></body></html>',
                'EUR',
            ],
            'currency from price symbol GBP' => [
                '<html><body><span class="a-price"><span class="a-price-symbol">£</span></span></body></html>',
                'GBP',
            ],
        ];
    }

    #[DataProvider('extractCurrencyProvider')]
    public function test_extract_currency_with_data_provider(string $html, ?string $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractCurrency($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractPriceProvider(): array
    {
        return [
            'no price' => [
                '<html><body><p>No price</p></body></html>',
                null,
            ],
            'price with decimal' => [
                '<html><body><span class="a-price"><span class="a-offscreen">$29.99</span></span></body></html>',
                29.99,
            ],
            'price without decimal' => [
                '<html><body><span class="a-price"><span class="a-offscreen">$22</span></span></body></html>',
                22.0,
            ],
            'price with comma' => [
                '<html><body><span class="a-price"><span class="a-offscreen">$1,234.56</span></span></body></html>',
                1234.56,
            ],
        ];
    }

    #[DataProvider('extractPriceProvider')]
    public function test_extract_price_with_data_provider(string $html, ?float $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractPrice($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractIsAvailableProvider(): array
    {
        return [
            'no availability info' => [
                '<html><body><p>No availability</p></body></html>',
                null,
            ],
            'in stock' => [
                '<html><body><div id="availability"><span>In Stock</span></div></body></html>',
                true,
            ],
            'available now' => [
                '<html><body><div id="availability"><span>Available now</span></div></body></html>',
                true,
            ],
            'out of stock' => [
                '<html><body><div id="availability"><span>Out of stock</span></div></body></html>',
                false,
            ],
            'currently unavailable' => [
                '<html><body><div id="availability"><span>Currently unavailable</span></div></body></html>',
                false,
            ],
            'add to cart button present' => [
                '<html><body><button id="add-to-cart-button">Add to Cart</button></body></html>',
                true,
            ],
        ];
    }

    #[DataProvider('extractIsAvailableProvider')]
    public function test_extract_is_available_with_data_provider(string $html, ?bool $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractIsAvailable($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractIsAmazonChoiceProvider(): array
    {
        return [
            'no amazon choice badge' => [
                '<html><body><p>No badge</p></body></html>',
                null,
            ],
            'amazon choice badge by id' => [
                '<html><body><div id="amazons-choice-badge">Amazon\'s Choice</div></body></html>',
                true,
            ],
            'amazon choice badge by class' => [
                '<html><body><div class="ac-badge-wrapper">Amazon\'s Choice</div></body></html>',
                true,
            ],
            'amazon choice in body text' => [
                '<html><body><p>This is Amazon\'s Choice for "test product"</p></body></html>',
                true,
            ],
        ];
    }

    #[DataProvider('extractIsAmazonChoiceProvider')]
    public function test_extract_is_amazon_choice_with_data_provider(string $html, ?bool $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractIsAmazonChoice($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractImageUrlProvider(): array
    {
        return [
            'no image' => [
                '<html><body><p>No image</p></body></html>',
                null,
            ],
            'image with src' => [
                '<html><body><img id="landingImage" src="https://example.com/image.jpg" /></body></html>',
                'https://example.com/image.jpg',
            ],
            'image with data-old-hires fallback' => [
                '<html><body><img id="landingImage" data-old-hires="https://example.com/hires-image.jpg" /></body></html>',
                'https://example.com/hires-image.jpg',
            ],
            'image with data-a-dynamic-image JSON' => [
                '<html><body><img class="a-dynamic-image" data-a-dynamic-image=\'{"https://example.com/image.jpg":[500,500]}\' /></body></html>',
                'https://example.com/image.jpg',
            ],
            'invalid URL filtered out' => [
                '<html><body><img id="landingImage" src="not-a-valid-url" /></body></html>',
                null,
            ],
        ];
    }

    #[DataProvider('extractImageUrlProvider')]
    public function test_extract_image_url_with_data_provider(string $html, ?string $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractImageUrl($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractBylineInfoProvider(): array
    {
        return [
            'no byline' => [
                '<html><body><p>No byline</p></body></html>',
                null,
            ],
            'author byline' => [
                '<html><body><span id="bylineInfo">by Robert Goodman (Author)</span></body></html>',
                'by Robert Goodman (Author)',
            ],
            'store byline' => [
                '<html><body><span id="bylineInfo">Visit the Play-Doh Store</span></body></html>',
                'Visit the Play-Doh Store',
            ],
            'brand byline' => [
                '<html><body><span id="bylineInfo">Brand: Ball Park</span></body></html>',
                'Brand: Ball Park',
            ],
        ];
    }

    #[DataProvider('extractBylineInfoProvider')]
    public function test_extract_byline_info_with_data_provider(string $html, ?string $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractBylineInfo($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractCategoriesProvider(): array
    {
        return [
            'no categories' => [
                '<html><body><p>No categories</p></body></html>',
                [],
            ],
            'single category' => [
                '<html><body><div id="wayfinding-breadcrumbs_feature_div"><ul class="a-unordered-list"><li><a>Books</a></li></ul></div></body></html>',
                ['Books'],
            ],
            'multiple categories' => [
                '<html><body><div id="wayfinding-breadcrumbs_feature_div"><ul class="a-unordered-list"><li><a>Books</a></li><li><a>Children\'s Books</a></li><li><a>Art</a></li></ul></div></body></html>',
                ['Books', "Children's Books", 'Art'],
            ],
        ];
    }

    #[DataProvider('extractCategoriesProvider')]
    public function test_extract_categories_with_data_provider(string $html, array $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractCategories($crawler);
        $this->assertEquals($expected, $result);
    }

    public static function extractPublisherProvider(): array
    {
        return [
            'no publisher' => [
                '<html><body><p>No publisher</p></body></html>',
                null,
            ],
            'publisher from detail bullets' => [
                '<html><body><div id="detailBulletsWrapper_feature_div"><ul class="detail-bullet-list"><li><span class="a-text-bold">Publisher :</span> Test Publisher</li></ul></div></body></html>',
                'Test Publisher',
            ],
            'publisher from prod details table' => [
                '<html><body><div id="prodDetails"><table><tr><th>Publisher</th><td>Table Publisher</td></tr></table></div></body></html>',
                'Table Publisher',
            ],
        ];
    }

    #[DataProvider('extractPublisherProvider')]
    public function test_extract_publisher_with_data_provider(string $html, ?string $expected): void
    {
        $crawler = new Crawler($html);
        $result  = $this->parser->extractPublisher($crawler);
        $this->assertEquals($expected, $result);
    }

    public function test_extract_from_detail_bullets_returns_empty_array_for_no_bullets(): void
    {
        $html    = '<html><body><div id="detailBulletsWrapper_feature_div"></div></body></html>';
        $crawler = new Crawler($html);

        $result = $this->parser->extractFromDetailBullets($crawler);

        $this->assertEmpty($result);
    }

    public function test_extract_from_detail_bullets_extracts_label_value_pairs(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">ASIN :</span> B0FNY2674D
                    </li>
                    <li>
                        <span class="a-text-bold">Publisher :</span> Test Publisher
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;
        $crawler = new Crawler($html);

        $result = $this->parser->extractFromDetailBullets($crawler);

        $this->assertArrayHasKey('asin', $result);
        $this->assertArrayHasKey('publisher', $result);
        $this->assertEquals('B0FNY2674D', $result['asin']);
        $this->assertEquals('Test Publisher', $result['publisher']);
    }

    public function test_extract_from_detail_bullets_excludes_customer_reviews_label(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Customer Reviews :</span> Some text
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;
        $crawler = new Crawler($html);

        $result = $this->parser->extractFromDetailBullets($crawler);

        $this->assertArrayNotHasKey('customer_reviews', $result);
    }

    public function test_extract_from_prod_details_returns_empty_array_for_no_tables(): void
    {
        $html    = '<html><body><div id="prodDetails"></div></body></html>';
        $crawler = new Crawler($html);

        $result = $this->parser->extractFromProdDetails($crawler);

        $this->assertEmpty($result);
    }

    public function test_extract_from_prod_details_extracts_table_rows(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="prodDetails">
                <table class="prodDetTable">
                    <tr>
                        <th class="prodDetSectionEntry">ASIN</th>
                        <td>B00JM5GW10</td>
                    </tr>
                    <tr>
                        <th class="prodDetSectionEntry">Manufacturer</th>
                        <td>Test Manufacturer</td>
                    </tr>
                </table>
            </div>
        </body>
        </html>
        HTML;
        $crawler = new Crawler($html);

        $result = $this->parser->extractFromProdDetails($crawler);

        $this->assertArrayHasKey('asin', $result);
        $this->assertArrayHasKey('manufacturer', $result);
        $this->assertEquals('B00JM5GW10', $result['asin']);
        $this->assertEquals('Test Manufacturer', $result['manufacturer']);
    }

    public function test_extract_from_prod_details_handles_customer_reviews(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="prodDetails">
                <table class="prodDetTable">
                    <tr>
                        <th class="prodDetSectionEntry">Customer Reviews</th>
                        <td>
                            <i class="a-icon a-icon-star">
                                <span class="a-icon-alt">4.8 out of 5 stars</span>
                            </i>
                            <span id="acrCustomerReviewText">65,219 ratings</span>
                        </td>
                    </tr>
                </table>
            </div>
        </body>
        </html>
        HTML;
        $crawler = new Crawler($html);

        $result = $this->parser->extractFromProdDetails($crawler);

        $this->assertArrayHasKey('customer_rating', $result);
        $this->assertArrayHasKey('customer_reviews_count', $result);
        $this->assertEquals('4.8', $result['customer_rating']);
        $this->assertEquals('65219', $result['customer_reviews_count']);
    }

    public function test_extract_from_prod_details_handles_best_sellers_rank(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="prodDetails">
                <table class="prodDetTable">
                    <tr>
                        <th class="prodDetSectionEntry">Best Sellers Rank</th>
                        <td>#4 in Toys & Games (See Top 100 in Toys & Games)</td>
                    </tr>
                </table>
            </div>
        </body>
        </html>
        HTML;
        $crawler = new Crawler($html);

        $result = $this->parser->extractFromProdDetails($crawler);

        $this->assertArrayHasKey('best_sellers_rank', $result);
        $this->assertEquals('#4 in Toys & Games', $result['best_sellers_rank']);
    }

    public function test_parse_returns_empty_array_for_empty_html(): void
    {
        $result = $this->parser->parse('');
        $this->assertEmpty($result);
    }

    public function test_parse_returns_empty_array_for_html_without_product_details(): void
    {
        $html   = '<html><body><h1>No product details here</h1></body></html>';
        $result = $this->parser->parse($html);
        $this->assertEmpty($result);
    }

    public function test_parse_handles_case_insensitive_labels_in_detail_bullets(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">PUBLISHER :</span> Test Publisher
                    </li>
                    <li>
                        <span class="a-text-bold">language :</span> English
                    </li>
                    <li>best sellers rank: #100 in Books</li>
                    <li>
                        customer reviews:
                        <i class="a-icon a-icon-star a-star-4-5"></i>
                        10 ratings
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        // All labels should be converted to snake_case regardless of original case
        $this->assertArrayHasKey('publisher', $result);
        $this->assertArrayHasKey('language', $result);
        $this->assertArrayHasKey('best_sellers_rank', $result);
        $this->assertArrayHasKey('customer_rating', $result);
        $this->assertArrayHasKey('customer_reviews_count', $result);
        $this->assertEquals('Test Publisher', $result['publisher']);
        $this->assertEquals('English', $result['language']);
        $this->assertEquals('#100 in Books', $result['best_sellers_rank']);
        $this->assertEquals('4.5', $result['customer_rating']);
        $this->assertEquals('10', $result['customer_reviews_count']);
    }

    public function test_parse_handles_case_insensitive_labels_in_prod_details(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="prodDetails">
                <table class="prodDetTable">
                    <tr>
                        <th class="prodDetSectionEntry">asin</th>
                        <td>B00TEST123</td>
                    </tr>
                    <tr>
                        <th class="prodDetSectionEntry">MANUFACTURER</th>
                        <td>Test Manufacturer</td>
                    </tr>
                    <tr>
                        <th class="prodDetSectionEntry">Customer Reviews</th>
                        <td>
                            <i class="a-icon a-icon-star">
                                <span class="a-icon-alt">4.8 out of 5 stars</span>
                            </i>
                            <span id="acrCustomerReviewText">100 ratings</span>
                        </td>
                    </tr>
                    <tr>
                        <th class="prodDetSectionEntry">best sellers rank</th>
                        <td>#50 in Toys & Games</td>
                    </tr>
                </table>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        // All labels should be converted to snake_case regardless of original case
        $this->assertArrayHasKey('asin', $result);
        $this->assertArrayHasKey('manufacturer', $result);
        $this->assertArrayHasKey('customer_rating', $result);
        $this->assertArrayHasKey('customer_reviews_count', $result);
        $this->assertArrayHasKey('best_sellers_rank', $result);
        $this->assertEquals('B00TEST123', $result['asin']);
        $this->assertEquals('Test Manufacturer', $result['manufacturer']);
        $this->assertEquals('4.8', $result['customer_rating']);
        $this->assertEquals('100', $result['customer_reviews_count']);
        $this->assertEquals('#50 in Toys & Games', $result['best_sellers_rank']);
    }

    public function test_parse_extracts_from_detail_bullets_format(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publisher :</span> Test Publisher
                    </li>
                    <li>
                        <span class="a-text-bold">Language :</span> English
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        $this->assertArrayHasKey('publisher', $result);
        $this->assertArrayHasKey('language', $result);
        $this->assertEquals('Test Publisher', $result['publisher']);
        $this->assertEquals('English', $result['language']);
    }

    public function test_parse_extracts_from_prod_details_format(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="prodDetails">
                <table class="prodDetTable">
                    <tr>
                        <th class="prodDetSectionEntry">ASIN</th>
                        <td>B00TEST123</td>
                    </tr>
                    <tr>
                        <th class="prodDetSectionEntry">Manufacturer</th>
                        <td>Test Manufacturer</td>
                    </tr>
                </table>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        $this->assertArrayHasKey('asin', $result);
        $this->assertArrayHasKey('manufacturer', $result);
        $this->assertEquals('B00TEST123', $result['asin']);
        $this->assertEquals('Test Manufacturer', $result['manufacturer']);
    }

    public function test_parse_extracts_asin_from_canonical_url(): void
    {
        $html = <<<HTML
        <html>
        <head>
            <link rel="canonical" href="https://www.amazon.com/Product-Name/dp/B0FNY2674D"/>
        </head>
        <body>
            <span id="productTitle">Test Product</span>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        $this->assertArrayHasKey('asin', $result);
        $this->assertEquals('B0FNY2674D', $result['asin']);
    }

    public function test_parse_product_details_asin_overwrites_canonical_asin(): void
    {
        $html = <<<HTML
        <html>
        <head>
            <link rel="canonical" href="https://www.amazon.com/Product/dp/B0CANONICAL"/>
        </head>
        <body>
            <div id="prodDetails">
                <table class="prodDetTable">
                    <tr>
                        <th class="prodDetSectionEntry">ASIN</th>
                        <td>B0PRODDETLS</td>
                    </tr>
                </table>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        $this->assertArrayHasKey('asin', $result);
        // Product details ASIN should overwrite canonical ASIN
        $this->assertEquals('B0PRODDETLS', $result['asin']);
    }

    public function test_parse_handles_best_sellers_rank_in_detail_bullets(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>Best Sellers Rank: #18,775 in Books (See Top 100 in Books)</li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        $this->assertArrayHasKey('best_sellers_rank', $result);
        $this->assertEquals('#18,775 in Books', $result['best_sellers_rank']);
    }

    public function test_parse_handles_customer_reviews_in_detail_bullets(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        Customer Reviews: 
                        <i class="a-icon a-icon-star a-star-4-5"></i>
                        42 ratings
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        $this->assertArrayHasKey('customer_rating', $result);
        $this->assertArrayHasKey('customer_reviews_count', $result);
        $this->assertEquals('4.5', $result['customer_rating']);
        $this->assertEquals('42', $result['customer_reviews_count']);
    }

    public function test_parse_removes_bidi_marks_from_values(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publisher :</span> Test\u{200E}Publisher\u{200F}
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        $this->assertArrayHasKey('publisher', $result);
        $this->assertEquals('TestPublisher', $result['publisher']);
    }

    public function test_parse_merges_both_formats(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publisher :</span> Test Publisher
                    </li>
                    <li>
                        <span class="a-text-bold">Language :</span> English
                    </li>
                </ul>
            </div>
            <div id="prodDetails">
                <table class="prodDetTable">
                    <tr>
                        <th class="prodDetSectionEntry">ASIN</th>
                        <td>B00TEST123</td>
                    </tr>
                    <tr>
                        <th class="prodDetSectionEntry">Manufacturer</th>
                        <td>Test Manufacturer</td>
                    </tr>
                </table>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parse($html);

        // Should have fields from both formats
        $this->assertArrayHasKey('publisher', $result);
        $this->assertArrayHasKey('language', $result);
        $this->assertArrayHasKey('asin', $result);
        $this->assertArrayHasKey('manufacturer', $result);
        $this->assertEquals('Test Publisher', $result['publisher']);
        $this->assertEquals('English', $result['language']);
        $this->assertEquals('B00TEST123', $result['asin']);
        $this->assertEquals('Test Manufacturer', $result['manufacturer']);
    }

    public static function pageCountProvider(): array
    {
        return [
            'field missing'              => [['asin' => 'B00TEST123'], null],
            'extracts from print length' => [['print_length' => '103 pages'], 103],
            'single page'                => [['print_length' => '1 page'], 1],
            'multiple pages'             => [['print_length' => '688 pages'], 688],
            'invalid format'             => [['print_length' => 'Unknown'], null],
            'empty value'                => [['print_length' => ''], null],
        ];
    }

    #[DataProvider('pageCountProvider')]
    public function test_get_page_count_with_data_provider(array $details, ?int $expected): void
    {
        $result = $this->parser->getPageCount($details);
        $this->assertEquals($expected, $result);
    }

    public static function bsrRankProvider(): array
    {
        return [
            'field missing'        => [['asin' => 'B00TEST123'], null],
            'extracts rank number' => [['best_sellers_rank' => '#18,775 in Books'], 18775],
            'removes commas'       => [['best_sellers_rank' => '#5,661,475 in Books'], 5661475],
            'rank 4'               => [['best_sellers_rank' => '#4 in Toys & Games'], 4],
            'invalid format'       => [['best_sellers_rank' => 'Unknown'], null],
            'empty value'          => [['best_sellers_rank' => ''], null],
        ];
    }

    #[DataProvider('bsrRankProvider')]
    public function test_get_bsr_rank_with_data_provider(array $details, ?int $expected): void
    {
        $result = $this->parser->getBsrRank($details);
        $this->assertEquals($expected, $result);
    }

    public static function bsrCategoryProvider(): array
    {
        return [
            'no bsr field' => [
                ['asin' => 'B00TEST123'],
                null,
            ],
            'books category' => [
                ['best_sellers_rank' => '#18,775 in Books'],
                'Books',
            ],
            'toys and games category' => [
                ['best_sellers_rank' => '#4 in Toys & Games'],
                'Toys & Games',
            ],
            'beauty and personal care category' => [
                ['best_sellers_rank' => '#510 in Beauty & Personal Care'],
                'Beauty & Personal Care',
            ],
            'amazon devices and accessories category' => [
                ['best_sellers_rank' => '#29 in Amazon Devices & Accessories'],
                'Amazon Devices & Accessories',
            ],
            'our brands category' => [
                ['best_sellers_rank' => '#792 in Our Brands'],
                'Our Brands',
            ],
            'home and kitchen category' => [
                ['best_sellers_rank' => '#22 in Home & Kitchen'],
                'Home & Kitchen',
            ],
            'invalid format' => [
                ['best_sellers_rank' => 'Unknown'],
                null,
            ],
            'empty value' => [
                ['best_sellers_rank' => ''],
                null,
            ],
        ];
    }

    #[DataProvider('bsrCategoryProvider')]
    public function test_get_bsr_category_with_data_provider(array $details, ?string $expected): void
    {
        $result = $this->parser->getBsrCategory($details);
        $this->assertEquals($expected, $result);
    }

    public static function normalizedDateProvider(): array
    {
        return [
            'no date fields' => [
                ['asin' => 'B00TEST123'],
                null,
                null,
            ],
            'publication date only' => [
                ['publication_date' => 'August 25, 2025'],
                '2025-08-25',
                'August 25, 2025',
            ],
            'release date only' => [
                ['release_date' => 'August 1, 2018'],
                '2018-08-01',
                'August 1, 2018',
            ],
            'date first available only' => [
                ['date_first_available' => 'May 29, 2025'],
                '2025-05-29',
                'May 29, 2025',
            ],
            'publication date takes priority over release date' => [
                [
                    'publication_date' => 'August 25, 2025',
                    'release_date'     => 'August 1, 2018',
                ],
                '2025-08-25',
                'August 25, 2025',
            ],
            'publication date takes priority over all' => [
                [
                    'publication_date'     => 'August 25, 2025',
                    'release_date'         => 'August 1, 2018',
                    'date_first_available' => 'May 29, 2025',
                ],
                '2025-08-25',
                'August 25, 2025',
            ],
            'release date takes priority over date first available' => [
                [
                    'release_date'         => 'August 1, 2018',
                    'date_first_available' => 'May 29, 2025',
                ],
                '2018-08-01',
                'August 1, 2018',
            ],
            'empty string skipped for publication date' => [
                [
                    'publication_date' => '',
                    'release_date'     => 'August 1, 2018',
                ],
                '2018-08-01',
                'August 1, 2018',
            ],
            'empty string skipped for release date' => [
                [
                    'publication_date'     => '',
                    'release_date'         => '',
                    'date_first_available' => 'May 29, 2025',
                ],
                '2025-05-29',
                'May 29, 2025',
            ],
            'all empty strings return null' => [
                [
                    'publication_date'     => '',
                    'release_date'         => '',
                    'date_first_available' => '',
                ],
                null,
                null,
            ],
            'unparseable date returns null when converting' => [
                ['publication_date' => 'Invalid Date XYZ'],
                null,
                'Invalid Date XYZ',
            ],
        ];
    }

    #[DataProvider('normalizedDateProvider')]
    public function test_get_normalized_date_with_data_provider(
        array $details,
        ?string $expectedYmd,
        ?string $expectedOriginal
    ): void {
        // Test with conversion to Y-m-d (default)
        $result = $this->parser->getNormalizedDate($details, true);
        $this->assertEquals($expectedYmd, $result);

        // Test without conversion
        $result = $this->parser->getNormalizedDate($details, false);
        $this->assertEquals($expectedOriginal, $result);
    }

    public function test_parseAll_adds_page_count_field(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Print Length :</span> 103 pages
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        $this->assertArrayHasKey('page_count', $result);
        $this->assertEquals(103, $result['page_count']);
    }

    public function test_parseAll_adds_bsr_rank_field(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>Best Sellers Rank: #18,775 in Books (See Top 100 in Books)</li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        $this->assertArrayHasKey('bsr_rank', $result);
        $this->assertEquals(18775, $result['bsr_rank']);
    }

    public function test_parseAll_adds_bsr_category_field(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>Best Sellers Rank: #18,775 in Books (See Top 100 in Books)</li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        $this->assertArrayHasKey('bsr_category', $result);
        $this->assertEquals('Books', $result['bsr_category']);
    }

    public function test_parseAll_adds_normalized_date_field(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publication date :</span> October 1, 2024
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        $this->assertArrayHasKey('normalized_date', $result);
        $this->assertEquals('2024-10-01', $result['normalized_date']);
    }

    public function test_parseAll_adds_bsr_monthly_sales_estimate_books_field(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>Best Sellers Rank: #5,000 in Books</li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        $this->assertArrayHasKey('bsr_monthly_sales_estimate_books', $result);
        $this->assertIsInt($result['bsr_monthly_sales_estimate_books']);
        $this->assertGreaterThan(0, $result['bsr_monthly_sales_estimate_books']);
    }

    public function test_parseAll_adds_kdp_fields_for_independently_published_book(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publisher :</span> Independently published (October 1, 2024)
                    </li>
                    <li>
                        <span class="a-text-bold">Dimensions :</span> 8.5 x 0.24 x 11 inches
                    </li>
                    <li>
                        <span class="a-text-bold">Print Length :</span> 100 pages
                    </li>
                </ul>
            </div>
            <span class="a-price" data-a-size="xl" data-a-color="price">
                <span class="a-offscreen">\$7.99</span>
            </span>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        // Verify it's independently published
        $this->assertStringContainsString('Independently published', $result['publisher']);

        // Check KDP fields are populated
        $this->assertArrayHasKey('kdp_trim_size', $result);
        $this->assertEquals('large', $result['kdp_trim_size']);

        $this->assertArrayHasKey('kdp_royalty_estimate', $result);
        $this->assertIsFloat($result['kdp_royalty_estimate']);
        $this->assertGreaterThan(0, $result['kdp_royalty_estimate']);
    }

    public function test_parseAll_does_not_add_kdp_fields_for_non_independently_published_book(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publisher :</span> Penguin Random House (January 15, 2024)
                    </li>
                    <li>
                        <span class="a-text-bold">Dimensions :</span> 6 x 1.2 x 9 inches
                    </li>
                    <li>
                        <span class="a-text-bold">Print Length :</span> 400 pages
                    </li>
                    <li>Best Sellers Rank: #100 in Books</li>
                </ul>
            </div>
            <span class="a-price" data-a-size="xl" data-a-color="price">
                <span class="a-offscreen">\$19.99</span>
            </span>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        // Check basic fields
        $this->assertStringContainsString('Penguin Random House', $result['publisher']);
        $this->assertEquals(19.99, $result['price']);
        $this->assertEquals(400, $result['page_count']);
        $this->assertEquals(100, $result['bsr_rank']);

        // Check KDP fields are NOT populated
        $this->assertArrayNotHasKey('kdp_trim_size', $result);
        $this->assertArrayNotHasKey('kdp_royalty_estimate', $result);

        // Check BSR monthly sales estimate IS populated (for all books)
        $this->assertArrayHasKey('bsr_monthly_sales_estimate_books', $result);
        $this->assertIsInt($result['bsr_monthly_sales_estimate_books']);
        $this->assertGreaterThan(0, $result['bsr_monthly_sales_estimate_books']);
    }

    public function test_parseAll_does_not_add_kdp_fields_for_non_book_product(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="prodDetails">
                <table class="prodDetTable">
                    <tr>
                        <th class="prodDetSectionEntry">ASIN</th>
                        <td>B0TESTTOY1</td>
                    </tr>
                    <tr>
                        <th class="prodDetSectionEntry">Best Sellers Rank</th>
                        <td>#50 in Toys & Games</td>
                    </tr>
                </table>
            </div>
            <span class="a-price" data-a-size="xl" data-a-color="price">
                <span class="a-offscreen">\$12.99</span>
            </span>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        // Check basic fields
        $this->assertEquals('B0TESTTOY1', $result['asin']);
        $this->assertArrayNotHasKey('publisher', $result); // Non-books don't have publisher
        $this->assertEquals(12.99, $result['price']);
        $this->assertEquals(50, $result['bsr_rank']);

        // Check KDP fields are NOT populated
        $this->assertArrayNotHasKey('kdp_trim_size', $result);
        $this->assertArrayNotHasKey('kdp_royalty_estimate', $result);

        // Check BSR monthly sales estimate IS populated (works for all products with BSR)
        $this->assertArrayHasKey('bsr_monthly_sales_estimate_books', $result);
        $this->assertIsInt($result['bsr_monthly_sales_estimate_books']);
        $this->assertGreaterThan(0, $result['bsr_monthly_sales_estimate_books']);
    }

    public function test_parseAll_kdp_trim_size_missing_when_no_dimensions(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publisher :</span> Independently published (October 1, 2024)
                    </li>
                    <li>
                        <span class="a-text-bold">Print Length :</span> 100 pages
                    </li>
                </ul>
            </div>
            <span class="a-price" data-a-size="xl" data-a-color="price">
                <span class="a-offscreen">\$9.99</span>
            </span>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        // Independently published but no dimensions
        $this->assertStringContainsString('Independently published', $result['publisher']);
        $this->assertArrayNotHasKey('dimensions', $result);

        // No dimensions means no trim size, and no trim size means no royalty estimate
        $this->assertArrayNotHasKey('kdp_trim_size', $result);
        $this->assertArrayNotHasKey('kdp_royalty_estimate', $result);
    }

    public function test_parseAll_kdp_royalty_missing_when_no_price(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publisher :</span> Independently published (October 1, 2024)
                    </li>
                    <li>
                        <span class="a-text-bold">Dimensions :</span> 8.5 x 0.24 x 11 inches
                    </li>
                    <li>
                        <span class="a-text-bold">Print Length :</span> 100 pages
                    </li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        // Has dimensions so trim size should be calculated
        $this->assertStringContainsString('Independently published', $result['publisher']);
        $this->assertArrayHasKey('kdp_trim_size', $result);
        $this->assertEquals('large', $result['kdp_trim_size']);

        // But no price means no royalty estimate
        $this->assertArrayNotHasKey('price', $result);
        $this->assertArrayNotHasKey('kdp_royalty_estimate', $result);
    }

    public function test_parseAll_kdp_royalty_missing_when_no_page_count(): void
    {
        $html = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>
                        <span class="a-text-bold">Publisher :</span> Independently published (October 1, 2024)
                    </li>
                    <li>
                        <span class="a-text-bold">Dimensions :</span> 8.5 x 0.24 x 11 inches
                    </li>
                </ul>
            </div>
            <span class="a-price" data-a-size="xl" data-a-color="price">
                <span class="a-offscreen">\$9.99</span>
            </span>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($html);

        // Has dimensions so trim size should be calculated
        $this->assertStringContainsString('Independently published', $result['publisher']);
        $this->assertArrayHasKey('kdp_trim_size', $result);
        $this->assertEquals('large', $result['kdp_trim_size']);

        // But no page count means no royalty estimate
        $this->assertArrayNotHasKey('page_count', $result);
        $this->assertArrayNotHasKey('kdp_royalty_estimate', $result);
    }

    public function test_parseAll_bsr_monthly_sales_estimate_for_various_bsr_ranks(): void
    {
        // Test Tier 1: BSR 1-100 (high volume)
        $htmlTier1 = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>Best Sellers Rank: #10 in Books</li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($htmlTier1);
        $this->assertEquals(10, $result['bsr_rank']);
        $this->assertIsInt($result['bsr_monthly_sales_estimate_books']);
        $this->assertGreaterThan(20000, $result['bsr_monthly_sales_estimate_books']); // Tier 1 high volume

        // Test Tier 2: BSR 101-100,000 (mid-range)
        $htmlTier2 = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>Best Sellers Rank: #5,000 in Books</li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($htmlTier2);
        $this->assertEquals(5000, $result['bsr_rank']);
        $this->assertIsInt($result['bsr_monthly_sales_estimate_books']);
        $this->assertGreaterThan(100, $result['bsr_monthly_sales_estimate_books']); // Tier 2 mid-range
        $this->assertLessThan(2000, $result['bsr_monthly_sales_estimate_books']);

        // Test Tier 3: BSR 100,001+ (long tail)
        $htmlTier3 = <<<HTML
        <html>
        <body>
            <div id="detailBulletsWrapper_feature_div">
                <ul class="detail-bullet-list">
                    <li>Best Sellers Rank: #500,000 in Books</li>
                </ul>
            </div>
        </body>
        </html>
        HTML;

        $result = $this->parser->parseAll($htmlTier3);
        $this->assertEquals(500000, $result['bsr_rank']);
        $this->assertIsInt($result['bsr_monthly_sales_estimate_books']);
        $this->assertLessThan(50, $result['bsr_monthly_sales_estimate_books']); // Tier 3 long tail
    }

    public function test_insert_product_with_valid_data(): void
    {
        $data = [
            'asin'                   => 'B0TEST1234',
            'title'                  => 'Test Product',
            'description'            => 'Test Description',
            'currency'               => 'USD',
            'price'                  => 29.99,
            'is_available'           => true,
            'is_amazon_choice'       => false,
            'image_url'              => 'https://example.com/image.jpg',
            'byline_info'            => 'Test Author',
            'categories'             => ['Books', 'Fiction'],
            'publisher'              => 'Test Publisher',
            'customer_rating'        => 4.5,
            'customer_reviews_count' => 100,
            'bsr_rank'               => 1000,
            'bsr_category'           => 'Books',
            'normalized_date'        => '2025-01-01',
            'page_count'             => 200,
        ];

        $result = $this->parser->insertProduct($data);

        $this->assertEquals(1, $result['inserted']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals('B0TEST1234', $result['asin']);
        $this->assertNull($result['reason']);

        // Verify data in database
        $this->assertDatabaseHas('amazon_products', [
            'asin'  => 'B0TEST1234',
            'title' => 'Test Product',
            'price' => 29.99,
        ]);
    }

    public function test_insert_product_skips_duplicate(): void
    {
        $data = [
            'asin'  => 'B0DUPLICATE',
            'title' => 'Test Product',
            'price' => 19.99,
        ];

        // Insert first time
        $result1 = $this->parser->insertProduct($data);
        $this->assertEquals(1, $result1['inserted']);
        $this->assertEquals(0, $result1['skipped']);

        // Insert second time (should skip)
        $result2 = $this->parser->insertProduct($data);
        $this->assertEquals(0, $result2['inserted']);
        $this->assertEquals(1, $result2['skipped']);
        $this->assertEquals('B0DUPLICATE', $result2['asin']);
        $this->assertEquals('duplicate', $result2['reason']);

        // Verify only one record exists
        $this->assertDatabaseCount('amazon_products', 1);
    }

    public function test_insert_product_without_asin_returns_error(): void
    {
        $data = [
            'title' => 'Test Product Without ASIN',
            'price' => 19.99,
        ];

        $result = $this->parser->insertProduct($data);

        $this->assertEquals(0, $result['inserted']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertNull($result['asin']);
        $this->assertEquals('no_asin', $result['reason']);

        // Verify no record inserted
        $this->assertDatabaseCount('amazon_products', 0);
    }

    public function test_insert_product_uses_isbn_10_fallback(): void
    {
        $data = [
            'isbn-10' => '1234567890',
            'title'   => 'Test Book',
            'price'   => 15.99,
        ];

        $result = $this->parser->insertProduct($data);

        $this->assertEquals(1, $result['inserted']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals('1234567890', $result['asin']);

        // Verify ASIN column has ISBN-10 value
        $this->assertDatabaseHas('amazon_products', [
            'asin'  => '1234567890',
            'title' => 'Test Book',
        ]);
    }

    public function test_insert_product_sets_processed_fields_to_null(): void
    {
        $data = [
            'asin'  => 'B0TESTPROC',
            'title' => 'Test Product',
        ];

        $result = $this->parser->insertProduct($data);

        $this->assertEquals(1, $result['inserted']);

        // Verify processed_at and processed_status are NULL
        $this->assertDatabaseHas('amazon_products', [
            'asin'             => 'B0TESTPROC',
            'processed_at'     => null,
            'processed_status' => null,
        ]);
    }

    public function test_insert_product_includes_kdp_fields_for_books(): void
    {
        $data = [
            'asin'                             => 'B0KDPBOOK1',
            'title'                            => 'Test KDP Book',
            'categories'                       => ['Books', 'Fiction'],
            'kdp_trim_size'                    => 'large',
            'kdp_royalty_estimate'             => 2.50,
            'bsr_monthly_sales_estimate_books' => 500,
        ];

        $result = $this->parser->insertProduct($data);

        $this->assertEquals(1, $result['inserted']);

        // Verify KDP fields are inserted
        $this->assertDatabaseHas('amazon_products', [
            'asin'                   => 'B0KDPBOOK1',
            'kdp_trim_size'          => 'large',
            'kdp_royalty_estimate'   => 2.50,
            'monthly_sales_estimate' => 500,
        ]);
    }

    public function test_insert_product_monthly_sales_estimate_only_for_books(): void
    {
        // Test with Books category
        $booksData = [
            'asin'                             => 'B0BOOK1234',
            'title'                            => 'Test Book',
            'categories'                       => ['Books', 'Fiction'],
            'bsr_monthly_sales_estimate_books' => 1000,
        ];

        $result = $this->parser->insertProduct($booksData);
        $this->assertEquals(1, $result['inserted']);

        $this->assertDatabaseHas('amazon_products', [
            'asin'                   => 'B0BOOK1234',
            'monthly_sales_estimate' => 1000,
        ]);

        // Test with non-Books category
        $toyData = [
            'asin'                             => 'B0TOY12345',
            'title'                            => 'Test Toy',
            'categories'                       => ['Toys & Games', 'Action Figures'],
            'bsr_monthly_sales_estimate_books' => 2000,
        ];

        $result = $this->parser->insertProduct($toyData);
        $this->assertEquals(1, $result['inserted']);

        $this->assertDatabaseHas('amazon_products', [
            'asin'                   => 'B0TOY12345',
            'monthly_sales_estimate' => null, // Should be NULL for non-books
        ]);
    }

    public function test_insert_product_kdp_fields_can_be_null(): void
    {
        $data = [
            'asin'       => 'B0NOKDP123',
            'title'      => 'Test Product Without KDP',
            'categories' => ['Books', 'Fiction'],
        ];

        $result = $this->parser->insertProduct($data);

        $this->assertEquals(1, $result['inserted']);

        // Verify KDP fields are NULL when not provided
        $this->assertDatabaseHas('amazon_products', [
            'asin'                   => 'B0NOKDP123',
            'kdp_trim_size'          => null,
            'kdp_royalty_estimate'   => null,
            'monthly_sales_estimate' => null,
        ]);
    }

    public function test_compute_items_stats_with_empty_array(): void
    {
        $result = $this->parser->computeItemsStats([]);

        // Should return structure with empty arrays and null averages
        $this->assertEmpty($result['json___bought_past_month']);
        $this->assertEmpty($result['json___price_from']);
        $this->assertEmpty($result['json___price_to']);
        $this->assertEmpty($result['json___rating_value']);
        $this->assertEmpty($result['json___rating_votes_count']);
        $this->assertEmpty($result['json___rating_rating_max']);
        $this->assertEmpty($result['json___is_amazon_choice']);
        $this->assertEmpty($result['json___is_best_seller']);
        $this->assertNull($result['avg___bought_past_month']);
        $this->assertNull($result['avg___price_from']);
        $this->assertNull($result['avg___price_to']);
        $this->assertNull($result['avg___rating_value']);
        $this->assertNull($result['avg___rating_votes_count']);
        $this->assertNull($result['avg___rating_rating_max']);
        $this->assertEquals(0, $result['cnt___is_amazon_choice']);
        $this->assertEquals(0, $result['cnt___is_best_seller']);
    }

    public function test_compute_items_stats_filters_by_rank_absolute(): void
    {
        $this->parser->setStatsItemsLimit(3);

        $items = [
            ['rank_absolute' => 1, 'price_from' => 10.0],
            ['rank_absolute' => 2, 'price_from' => 20.0],
            ['rank_absolute' => 3, 'price_from' => 30.0], // Should be included (rank <= 3)
            ['rank_absolute' => 4, 'price_from' => 40.0], // Should be excluded (rank > 3)
        ];

        $result = $this->parser->computeItemsStats($items);

        // Only items with rank_absolute <= 3 should be included (top 3 items)
        $this->assertCount(3, $result['json___price_from']);
        $this->assertEquals([10.0, 20.0, 30.0], $result['json___price_from']);
        $this->assertEquals(20.0, $result['avg___price_from']);
    }

    public function test_compute_items_stats_handles_objects_and_arrays(): void
    {
        $this->parser->setStatsItemsLimit(10);

        // Mix of objects and arrays
        $items = [
            (object)['rank_absolute' => 1, 'price_from' => 10.0],
            ['rank_absolute' => 2, 'price_from' => 20.0],
            (object)['rank_absolute' => 3, 'price_from' => 30.0],
        ];

        $result = $this->parser->computeItemsStats($items);

        $this->assertCount(3, $result['json___price_from']);
        $this->assertEquals([10.0, 20.0, 30.0], $result['json___price_from']);
        $this->assertEquals(20.0, $result['avg___price_from']);
    }

    public function test_compute_items_stats_excludes_null_values_from_numeric_fields(): void
    {
        $this->parser->setStatsItemsLimit(10);

        $items = [
            ['rank_absolute' => 1, 'price_from' => 10.0, 'price_to' => null],
            ['rank_absolute' => 2, 'price_from' => 20.0, 'price_to' => 50.0],
            ['rank_absolute' => 3, 'price_from' => null, 'price_to' => 60.0],
        ];

        $result = $this->parser->computeItemsStats($items);

        // price_from should include nulls to maintain alignment
        $this->assertCount(3, $result['json___price_from']);
        $this->assertEquals([10.0, 20.0, null], $result['json___price_from']);
        $this->assertEquals(15.0, $result['avg___price_from']); // Average excludes nulls

        // price_to should include nulls to maintain alignment
        $this->assertCount(3, $result['json___price_to']);
        $this->assertEquals([null, 50.0, 60.0], $result['json___price_to']);
        $this->assertEquals(55.0, $result['avg___price_to']); // Average excludes nulls
    }

    public function test_compute_items_stats_counts_boolean_true_values(): void
    {
        $this->parser->setStatsItemsLimit(10);

        $items = [
            ['rank_absolute' => 1, 'is_amazon_choice' => 1, 'is_best_seller' => 0],
            ['rank_absolute' => 2, 'is_amazon_choice' => 0, 'is_best_seller' => 1],
            ['rank_absolute' => 3, 'is_amazon_choice' => 1, 'is_best_seller' => 1],
            ['rank_absolute' => 4, 'is_amazon_choice' => 0, 'is_best_seller' => 0],
        ];

        $result = $this->parser->computeItemsStats($items);

        $this->assertEquals(2, $result['cnt___is_amazon_choice']);
        $this->assertEquals(2, $result['cnt___is_best_seller']);
    }

    public static function computeItemsStatsProvider(): array
    {
        return [
            'single item with all fields' => [
                'items' => [
                    [
                        'rank_absolute'      => 1,
                        'bought_past_month'  => 1000,
                        'price_from'         => 29.99,
                        'price_to'           => 39.99,
                        'rating_value'       => 4.5,
                        'rating_votes_count' => 500,
                        'rating_rating_max'  => 5,
                        'is_amazon_choice'   => 1,
                        'is_best_seller'     => 0,
                    ],
                ],
                'expected' => [
                    'json___bought_past_month'  => [1000],
                    'json___price_from'         => [29.99],
                    'json___price_to'           => [39.99],
                    'json___rating_value'       => [4.5],
                    'json___rating_votes_count' => [500],
                    'json___rating_rating_max'  => [5],
                    'avg___bought_past_month'   => 1000.0,
                    'avg___price_from'          => 29.99,
                    'avg___price_to'            => 39.99,
                    'avg___rating_value'        => 4.5,
                    'avg___rating_votes_count'  => 500.0,
                    'avg___rating_rating_max'   => 5.0,
                    'cnt___is_amazon_choice'    => 1,
                    'cnt___is_best_seller'      => 0,
                ],
            ],
            'multiple items with averages' => [
                'items' => [
                    [
                        'rank_absolute'      => 1,
                        'bought_past_month'  => 1000,
                        'price_from'         => 10.0,
                        'rating_value'       => 4.0,
                        'rating_votes_count' => 100,
                        'rating_rating_max'  => 5,
                        'is_amazon_choice'   => 1,
                        'is_best_seller'     => 1,
                    ],
                    [
                        'rank_absolute'      => 2,
                        'bought_past_month'  => 2000,
                        'price_from'         => 20.0,
                        'rating_value'       => 5.0,
                        'rating_votes_count' => 200,
                        'rating_rating_max'  => 5,
                        'is_amazon_choice'   => 0,
                        'is_best_seller'     => 1,
                    ],
                    [
                        'rank_absolute'      => 3,
                        'bought_past_month'  => 3000,
                        'price_from'         => 30.0,
                        'rating_value'       => 3.0,
                        'rating_votes_count' => 300,
                        'rating_rating_max'  => 5,
                        'is_amazon_choice'   => 1,
                        'is_best_seller'     => 0,
                    ],
                ],
                'expected' => [
                    'json___bought_past_month'  => [1000, 2000, 3000],
                    'json___price_from'         => [10.0, 20.0, 30.0],
                    'json___rating_value'       => [4.0, 5.0, 3.0],
                    'json___rating_votes_count' => [100, 200, 300],
                    'json___rating_rating_max'  => [5, 5, 5],
                    'avg___bought_past_month'   => 2000.0,
                    'avg___price_from'          => 20.0,
                    'avg___rating_value'        => 4.0,
                    'avg___rating_votes_count'  => 200.0,
                    'avg___rating_rating_max'   => 5.0,
                    'cnt___is_amazon_choice'    => 2,
                    'cnt___is_best_seller'      => 2,
                ],
            ],
            'items with missing fields' => [
                'items' => [
                    [
                        'rank_absolute'    => 1,
                        'price_from'       => 10.0,
                        'is_amazon_choice' => 1,
                    ],
                    [
                        'rank_absolute'  => 2,
                        'price_from'     => 20.0,
                        'is_best_seller' => 1,
                    ],
                ],
                'expected' => [
                    'json___bought_past_month'  => [null, null],
                    'json___price_from'         => [10.0, 20.0],
                    'json___price_to'           => [null, null],
                    'json___rating_value'       => [null, null],
                    'json___rating_votes_count' => [null, null],
                    'json___rating_rating_max'  => [null, null],
                    'avg___bought_past_month'   => null,
                    'avg___price_from'          => 15.0,
                    'avg___price_to'            => null,
                    'avg___rating_value'        => null,
                    'avg___rating_votes_count'  => null,
                    'avg___rating_rating_max'   => null,
                    'cnt___is_amazon_choice'    => 1,
                    'cnt___is_best_seller'      => 1,
                ],
            ],
        ];
    }

    #[DataProvider('computeItemsStatsProvider')]
    public function test_compute_items_stats_with_data_provider(array $items, array $expected): void
    {
        $this->parser->setStatsItemsLimit(10);

        $result = $this->parser->computeItemsStats($items);

        // Check JSON arrays
        $this->assertEquals($expected['json___bought_past_month'], $result['json___bought_past_month']);
        $this->assertEquals($expected['json___price_from'], $result['json___price_from']);

        if (isset($expected['json___price_to'])) {
            $this->assertEquals($expected['json___price_to'], $result['json___price_to']);
        }
        if (isset($expected['json___rating_value'])) {
            $this->assertEquals($expected['json___rating_value'], $result['json___rating_value']);
        }
        if (isset($expected['json___rating_votes_count'])) {
            $this->assertEquals($expected['json___rating_votes_count'], $result['json___rating_votes_count']);
        }
        if (isset($expected['json___rating_rating_max'])) {
            $this->assertEquals($expected['json___rating_rating_max'], $result['json___rating_rating_max']);
        }

        // Check averages
        $this->assertEquals($expected['avg___bought_past_month'], $result['avg___bought_past_month']);
        $this->assertEquals($expected['avg___price_from'], $result['avg___price_from']);

        if (isset($expected['avg___price_to'])) {
            $this->assertEquals($expected['avg___price_to'], $result['avg___price_to']);
        }
        if (isset($expected['avg___rating_value'])) {
            $this->assertEquals($expected['avg___rating_value'], $result['avg___rating_value']);
        }
        if (isset($expected['avg___rating_votes_count'])) {
            $this->assertEquals($expected['avg___rating_votes_count'], $result['avg___rating_votes_count']);
        }
        if (isset($expected['avg___rating_rating_max'])) {
            $this->assertEquals($expected['avg___rating_rating_max'], $result['avg___rating_rating_max']);
        }

        // Check counts
        $this->assertEquals($expected['cnt___is_amazon_choice'], $result['cnt___is_amazon_choice']);
        $this->assertEquals($expected['cnt___is_best_seller'], $result['cnt___is_best_seller']);
    }

    public function test_compute_products_stats_with_empty_array(): void
    {
        $result = $this->parser->computeProductsStats([]);

        // Should return structure with empty arrays and null averages
        $this->assertEmpty($result['json___products__price']);
        $this->assertEmpty($result['json___products__customer_rating']);
        $this->assertEmpty($result['json___products__customer_reviews_count']);
        $this->assertEmpty($result['json___products__bsr_rank']);
        $this->assertEmpty($result['json___products__normalized_date']);
        $this->assertEmpty($result['json___products__page_count']);
        $this->assertEmpty($result['json___products__is_available']);
        $this->assertEmpty($result['json___products__is_amazon_choice']);
        $this->assertEmpty($result['json___products__is_independently_published']);
        $this->assertEmpty($result['json___products__kdp_royalty_estimate']);
        $this->assertEmpty($result['json___products__monthly_sales_estimate']);
        $this->assertNull($result['avg___products__price']);
        $this->assertNull($result['avg___products__customer_rating']);
        $this->assertNull($result['avg___products__customer_reviews_count']);
        $this->assertNull($result['avg___products__bsr_rank']);
        $this->assertNull($result['avg___products__normalized_date']);
        $this->assertNull($result['avg___products__page_count']);
        $this->assertNull($result['avg___products__kdp_royalty_estimate']);
        $this->assertNull($result['avg___products__monthly_sales_estimate']);
        $this->assertEquals(0, $result['cnt___products__is_available']);
        $this->assertEquals(0, $result['cnt___products__is_amazon_choice']);
        $this->assertEquals(0, $result['cnt___products__is_independently_published']);
        // BSR-related fields
        $this->assertNull($result['stdev___products__bsr_rank']);
    }

    public function test_compute_products_stats_handles_objects_and_arrays(): void
    {
        // Mix of objects and arrays
        $products = [
            (object)['price' => 10.0, 'customer_rating' => 4.5],
            ['price' => 20.0, 'customer_rating' => 4.0],
            (object)['price' => 30.0, 'customer_rating' => 5.0],
        ];

        $result = $this->parser->computeProductsStats($products);

        $this->assertCount(3, $result['json___products__price']);
        $this->assertEquals([10.0, 20.0, 30.0], $result['json___products__price']);
        $this->assertEquals(20.0, $result['avg___products__price']);
        $this->assertEquals(4.5, $result['avg___products__customer_rating']);
    }

    public function test_compute_products_stats_excludes_null_values_from_numeric_fields(): void
    {
        $products = [
            ['price' => 10.0, 'customer_rating' => null],
            ['price' => 20.0, 'customer_rating' => 4.5],
            ['price' => null, 'customer_rating' => 5.0],
        ];

        $result = $this->parser->computeProductsStats($products);

        // price should include nulls to maintain alignment
        $this->assertCount(3, $result['json___products__price']);
        $this->assertEquals([10.0, 20.0, null], $result['json___products__price']);
        $this->assertEquals(15.0, $result['avg___products__price']); // Average excludes nulls

        // customer_rating should include nulls to maintain alignment
        $this->assertCount(3, $result['json___products__customer_rating']);
        $this->assertEquals([null, 4.5, 5.0], $result['json___products__customer_rating']);
        $this->assertEquals(4.75, $result['avg___products__customer_rating']); // Average excludes nulls
    }

    public function test_compute_products_stats_counts_boolean_true_values(): void
    {
        $products = [
            ['is_available' => 1, 'is_amazon_choice' => 0],
            ['is_available' => 0, 'is_amazon_choice' => 1],
            ['is_available' => 1, 'is_amazon_choice' => 1],
            ['is_available' => 0, 'is_amazon_choice' => 0],
        ];

        $result = $this->parser->computeProductsStats($products);

        $this->assertEquals(2, $result['cnt___products__is_available']);
        $this->assertEquals(2, $result['cnt___products__is_amazon_choice']);
    }

    public function test_compute_products_stats_computes_is_independently_published(): void
    {
        $products = [
            ['publisher' => 'Independently published'],
            ['publisher' => 'Random House'],
            ['publisher' => 'independently Published'], // Case insensitive
            ['publisher' => 'Penguin Books'],
            ['publisher' => null], // Should be excluded
        ];

        $result = $this->parser->computeProductsStats($products);

        // Should have 5 items (including null to maintain alignment)
        $this->assertCount(5, $result['json___products__is_independently_published']);
        $this->assertEquals([true, false, true, false, null], $result['json___products__is_independently_published']);
        $this->assertEquals(2, $result['cnt___products__is_independently_published']);
    }

    public function test_compute_products_stats_computes_average_date(): void
    {
        $products = [
            ['normalized_date' => '2020-01-01'],
            ['normalized_date' => '2024-01-01'],
        ];

        $result = $this->parser->computeProductsStats($products);

        // Average of 2020-01-01 and 2024-01-01 (accounting for leap years)
        $this->assertEquals('2021-12-31', $result['avg___products__normalized_date']);
    }

    public function test_compute_products_stats_computes_bsr_stdev_with_single_item(): void
    {
        $products = [
            ['bsr_rank' => 1000],
        ];

        $result = $this->parser->computeProductsStats($products);

        // Standard deviation should be null for single item
        $this->assertNull($result['stdev___products__bsr_rank']);
    }

    public function test_compute_products_stats_computes_bsr_stdev_with_multiple_items(): void
    {
        $products = [
            ['bsr_rank' => 100],
            ['bsr_rank' => 200],
            ['bsr_rank' => 300],
        ];

        $result = $this->parser->computeProductsStats($products);

        // Standard deviation for [100, 200, 300]:
        // Mean = 200
        // Variance = ((100-200)² + (200-200)² + (300-200)²) / (3-1) = (10000 + 0 + 10000) / 2 = 10000
        // Stdev = sqrt(10000) = 100.0
        $this->assertIsFloat($result['stdev___products__bsr_rank']);
        $this->assertEqualsWithDelta(100.0, $result['stdev___products__bsr_rank'], 0.01);
    }

    public function test_compute_products_stats_handles_empty_bsr_for_stdev(): void
    {
        $products = [
            ['price' => 10.0], // No bsr_rank
        ];

        $result = $this->parser->computeProductsStats($products);

        // Should have null stdev when no BSR ranks
        $this->assertNull($result['stdev___products__bsr_rank']);
    }

    public function test_compute_products_stats_average_date_with_three_dates(): void
    {
        $products = [
            ['normalized_date' => '2020-01-01'],
            ['normalized_date' => '2021-01-01'],
            ['normalized_date' => '2022-01-01'],
        ];

        $result = $this->parser->computeProductsStats($products);

        // Average of three consecutive years
        $this->assertEquals('2020-12-31', $result['avg___products__normalized_date']);
    }

    public static function computeProductsStatsProvider(): array
    {
        return [
            'single product with all fields' => [
                'products' => [
                    [
                        'price'                  => 29.99,
                        'customer_rating'        => 4.5,
                        'customer_reviews_count' => 500,
                        'bsr_rank'               => 100,
                        'normalized_date'        => '2023-01-01',
                        'page_count'             => 250,
                        'is_available'           => 1,
                        'is_amazon_choice'       => 1,
                        'publisher'              => 'Independently published',
                    ],
                ],
                'expected' => [
                    'json___products__price'                     => [29.99],
                    'json___products__customer_rating'           => [4.5],
                    'json___products__customer_reviews_count'    => [500],
                    'json___products__bsr_rank'                  => [100],
                    'json___products__normalized_date'           => ['2023-01-01'],
                    'json___products__page_count'                => [250],
                    'avg___products__price'                      => 29.99,
                    'avg___products__customer_rating'            => 4.5,
                    'avg___products__customer_reviews_count'     => 500.0,
                    'avg___products__bsr_rank'                   => 100.0,
                    'avg___products__normalized_date'            => '2023-01-01',
                    'avg___products__page_count'                 => 250.0,
                    'cnt___products__is_available'               => 1,
                    'cnt___products__is_amazon_choice'           => 1,
                    'cnt___products__is_independently_published' => 1,
                ],
            ],
            'multiple products with averages' => [
                'products' => [
                    [
                        'price'                  => 10.0,
                        'customer_rating'        => 4.0,
                        'customer_reviews_count' => 100,
                        'bsr_rank'               => 50,
                        'normalized_date'        => '2020-01-01',
                        'page_count'             => 100,
                        'is_available'           => 1,
                        'is_amazon_choice'       => 1,
                        'publisher'              => 'Random House',
                    ],
                    [
                        'price'                  => 20.0,
                        'customer_rating'        => 5.0,
                        'customer_reviews_count' => 200,
                        'bsr_rank'               => 150,
                        'normalized_date'        => '2022-01-01',
                        'page_count'             => 200,
                        'is_available'           => 0,
                        'is_amazon_choice'       => 1,
                        'publisher'              => 'Independently published',
                    ],
                    [
                        'price'                  => 30.0,
                        'customer_rating'        => 3.0,
                        'customer_reviews_count' => 300,
                        'bsr_rank'               => 250,
                        'normalized_date'        => '2024-01-01',
                        'page_count'             => 300,
                        'is_available'           => 1,
                        'is_amazon_choice'       => 0,
                        'publisher'              => 'Penguin Books',
                    ],
                ],
                'expected' => [
                    'json___products__price'                     => [10.0, 20.0, 30.0],
                    'json___products__customer_rating'           => [4.0, 5.0, 3.0],
                    'json___products__customer_reviews_count'    => [100, 200, 300],
                    'json___products__bsr_rank'                  => [50, 150, 250],
                    'json___products__normalized_date'           => ['2020-01-01', '2022-01-01', '2024-01-01'],
                    'json___products__page_count'                => [100, 200, 300],
                    'avg___products__price'                      => 20.0,
                    'avg___products__customer_rating'            => 4.0,
                    'avg___products__customer_reviews_count'     => 200.0,
                    'avg___products__bsr_rank'                   => 150.0,
                    'avg___products__normalized_date'            => '2021-12-31',
                    'avg___products__page_count'                 => 200.0,
                    'cnt___products__is_available'               => 2,
                    'cnt___products__is_amazon_choice'           => 2,
                    'cnt___products__is_independently_published' => 1,
                ],
            ],
            'products with missing fields' => [
                'products' => [
                    [
                        'price'        => 10.0,
                        'is_available' => 1,
                        'publisher'    => 'Random House',
                    ],
                    [
                        'price'            => 20.0,
                        'is_amazon_choice' => 1,
                        'publisher'        => 'Independently published',
                    ],
                ],
                'expected' => [
                    'json___products__price'                     => [10.0, 20.0],
                    'json___products__customer_rating'           => [null, null],
                    'json___products__customer_reviews_count'    => [null, null],
                    'json___products__bsr_rank'                  => [null, null],
                    'json___products__normalized_date'           => [null, null],
                    'json___products__page_count'                => [null, null],
                    'avg___products__price'                      => 15.0,
                    'avg___products__customer_rating'            => null,
                    'avg___products__customer_reviews_count'     => null,
                    'avg___products__bsr_rank'                   => null,
                    'avg___products__normalized_date'            => null,
                    'avg___products__page_count'                 => null,
                    'cnt___products__is_available'               => 1,
                    'cnt___products__is_amazon_choice'           => 1,
                    'cnt___products__is_independently_published' => 1,
                ],
            ],
        ];
    }

    #[DataProvider('computeProductsStatsProvider')]
    public function test_compute_products_stats_with_data_provider(array $products, array $expected): void
    {
        $result = $this->parser->computeProductsStats($products);

        // Check JSON arrays
        $this->assertEquals($expected['json___products__price'], $result['json___products__price']);
        $this->assertEquals($expected['json___products__customer_rating'], $result['json___products__customer_rating']);
        $this->assertEquals($expected['json___products__customer_reviews_count'], $result['json___products__customer_reviews_count']);
        $this->assertEquals($expected['json___products__bsr_rank'], $result['json___products__bsr_rank']);
        $this->assertEquals($expected['json___products__normalized_date'], $result['json___products__normalized_date']);
        $this->assertEquals($expected['json___products__page_count'], $result['json___products__page_count']);

        // Check averages
        $this->assertEquals($expected['avg___products__price'], $result['avg___products__price']);
        $this->assertEquals($expected['avg___products__customer_rating'], $result['avg___products__customer_rating']);
        $this->assertEquals($expected['avg___products__customer_reviews_count'], $result['avg___products__customer_reviews_count']);
        $this->assertEquals($expected['avg___products__bsr_rank'], $result['avg___products__bsr_rank']);
        $this->assertEquals($expected['avg___products__normalized_date'], $result['avg___products__normalized_date']);
        $this->assertEquals($expected['avg___products__page_count'], $result['avg___products__page_count']);

        // Check counts
        $this->assertEquals($expected['cnt___products__is_available'], $result['cnt___products__is_available']);
        $this->assertEquals($expected['cnt___products__is_amazon_choice'], $result['cnt___products__is_amazon_choice']);
        $this->assertEquals($expected['cnt___products__is_independently_published'], $result['cnt___products__is_independently_published']);
    }

    public function test_compute_products_stats_includes_kdp_royalty_estimate(): void
    {
        $products = [
            ['kdp_royalty_estimate' => 2.50],
            ['kdp_royalty_estimate' => 3.75],
            ['kdp_royalty_estimate' => 1.25],
        ];

        $result = $this->parser->computeProductsStats($products);

        // Check JSON array
        $this->assertCount(3, $result['json___products__kdp_royalty_estimate']);
        $this->assertEquals([2.50, 3.75, 1.25], $result['json___products__kdp_royalty_estimate']);

        // Check average
        $this->assertEqualsWithDelta(2.50, $result['avg___products__kdp_royalty_estimate'], 0.01);
    }

    public function test_compute_products_stats_includes_monthly_sales_estimate(): void
    {
        $products = [
            ['monthly_sales_estimate' => 500],
            ['monthly_sales_estimate' => 1000],
            ['monthly_sales_estimate' => 1500],
        ];

        $result = $this->parser->computeProductsStats($products);

        // Check JSON array
        $this->assertCount(3, $result['json___products__monthly_sales_estimate']);
        $this->assertEquals([500, 1000, 1500], $result['json___products__monthly_sales_estimate']);

        // Check average
        $this->assertEquals(1000, $result['avg___products__monthly_sales_estimate']);
    }

    public function test_compute_products_stats_excludes_null_kdp_fields(): void
    {
        $products = [
            ['kdp_royalty_estimate' => 2.50, 'monthly_sales_estimate' => 500],
            ['kdp_royalty_estimate' => null, 'monthly_sales_estimate' => null],
            ['kdp_royalty_estimate' => 3.75, 'monthly_sales_estimate' => 1000],
        ];

        $result = $this->parser->computeProductsStats($products);

        // KDP royalty estimate should include nulls to maintain alignment
        $this->assertCount(3, $result['json___products__kdp_royalty_estimate']);
        $this->assertEquals([2.50, null, 3.75], $result['json___products__kdp_royalty_estimate']);
        $this->assertEqualsWithDelta(3.125, $result['avg___products__kdp_royalty_estimate'], 0.01); // Average excludes nulls

        // Monthly sales estimate should include nulls to maintain alignment
        $this->assertCount(3, $result['json___products__monthly_sales_estimate']);
        $this->assertEquals([500, null, 1000], $result['json___products__monthly_sales_estimate']);
        $this->assertEquals(750, $result['avg___products__monthly_sales_estimate']); // Average excludes nulls
    }

    public function test_compute_products_stats_kdp_fields_with_all_nulls(): void
    {
        $products = [
            ['price' => 10.0, 'kdp_royalty_estimate' => null, 'monthly_sales_estimate' => null],
            ['price' => 20.0, 'kdp_royalty_estimate' => null, 'monthly_sales_estimate' => null],
        ];

        $result = $this->parser->computeProductsStats($products);

        // KDP fields should have nulls (to maintain alignment) with null averages
        $this->assertCount(2, $result['json___products__kdp_royalty_estimate']);
        $this->assertEquals([null, null], $result['json___products__kdp_royalty_estimate']);
        $this->assertNull($result['avg___products__kdp_royalty_estimate']);

        $this->assertCount(2, $result['json___products__monthly_sales_estimate']);
        $this->assertEquals([null, null], $result['json___products__monthly_sales_estimate']);
        $this->assertNull($result['avg___products__monthly_sales_estimate']);

        // Other fields should still work
        $this->assertCount(2, $result['json___products__price']);
        $this->assertEquals([10.0, 20.0], $result['json___products__price']);
        $this->assertEquals(15.0, $result['avg___products__price']);
    }

    public function test_compute_scores_for_stats_row_returns_all_null_with_no_data(): void
    {
        $listingsRow = ['se_results_count' => 1000];
        $items       = [];
        $products    = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        $this->assertNull($result['score_1']);
        $this->assertNull($result['score_2']);
        $this->assertNull($result['score_3']);
        $this->assertNull($result['score_4']);
        $this->assertNull($result['score_5']);
        $this->assertNull($result['score_6']);
        $this->assertNull($result['score_7']);
        $this->assertNull($result['score_8']);
        $this->assertNull($result['score_9']);
        $this->assertNull($result['score_10']);
        $this->assertNull($result['cv_monthly_sales_estimate']);
    }

    public function test_compute_scores_for_stats_row_filters_items_by_rank_absolute(): void
    {
        $this->parser->setStatsItemsLimit(3);

        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200],
            ['rank_absolute' => 3, 'bought_past_month' => 3000, 'price_from' => 30.0, 'rating_value' => 5.0, 'rating_votes_count' => 300],
            ['rank_absolute' => 4, 'bought_past_month' => 4000, 'price_from' => 40.0, 'rating_value' => 3.5, 'rating_votes_count' => 400], // Should be excluded
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // Should only use first 3 items
        $this->assertNotNull($result['score_1']);
        $this->assertNotNull($result['cv_monthly_sales_estimate']);
    }

    public function test_compute_scores_for_stats_row_uses_bought_past_month_from_items(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100, 'data_asin' => 'B001'],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200, 'data_asin' => 'B002'],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_1 = log(avg(monthly_sales) * avg(price))
        // avg(monthly_sales) = (1000 + 2000) / 2 = 1500
        // avg(price) = (10 + 20) / 2 = 15
        // score_1 = log(1500 * 15) = log(22500)
        $expectedScore1 = log(1500 * 15);
        $this->assertEquals($expectedScore1, $result['score_1']);
    }

    public function test_compute_scores_for_stats_row_falls_back_to_products_for_books(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => null, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100, 'data_asin' => 'B001'],
        ];
        $products = [
            ['asin' => 'B001', 'categories' => json_encode(['Books', 'Fiction']), 'monthly_sales_estimate' => 500],
        ];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // Should use monthly_sales_estimate from products (500)
        // score_1 = log(500 * 10) = log(5000)
        $expectedScore1 = log(500 * 10);
        $this->assertEquals($expectedScore1, $result['score_1']);
    }

    public function test_compute_scores_for_stats_row_ignores_non_books_products(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => null, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100, 'data_asin' => 'B001'],
        ];
        $products = [
            ['asin' => 'B001', 'categories' => json_encode(['Electronics', 'Phones']), 'monthly_sales_estimate' => 500],
        ];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // Should not use products data because category is not Books
        $this->assertNull($result['score_1']);
    }

    public function test_compute_scores_for_stats_row_computes_cv_monthly_sales_estimate(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200],
            ['rank_absolute' => 3, 'bought_past_month' => 3000, 'price_from' => 30.0, 'rating_value' => 5.0, 'rating_votes_count' => 300],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // CV = stdev / mean
        // mean = (1000 + 2000 + 3000) / 3 = 2000
        // Should have a CV value
        $this->assertNotNull($result['cv_monthly_sales_estimate']);
        $this->assertGreaterThan(0, $result['cv_monthly_sales_estimate']);
    }

    public function test_compute_scores_for_stats_row_computes_score_2(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_2 = avg(rating) * log(avg(reviews_count))
        // avg(rating) = (4.5 + 4.0) / 2 = 4.25
        // avg(reviews_count) = (100 + 200) / 2 = 150
        // score_2 = 4.25 * log(150)
        $expectedScore2 = 4.25 * log(150);
        $this->assertEquals($expectedScore2, $result['score_2']);
    }

    public function test_compute_scores_for_stats_row_computes_score_3(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_3 = score_1 / score_2
        $this->assertNotNull($result['score_3']);
        $this->assertEquals($result['score_1'] / $result['score_2'], $result['score_3']);
    }

    public function test_compute_scores_for_stats_row_computes_score_4_and_5(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_4 = score_1 / se_results_count
        $this->assertNotNull($result['score_4']);
        $this->assertEquals($result['score_1'] / 100, $result['score_4']);

        // score_5 = score_1 / sqrt(se_results_count)
        $this->assertNotNull($result['score_5']);
        $this->assertEquals($result['score_1'] / sqrt(100), $result['score_5']);
    }

    public function test_compute_scores_for_stats_row_computes_score_6_7_8(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_6 = score_3 / cv_monthly_sales_estimate
        $this->assertNotNull($result['score_6']);
        $this->assertEquals($result['score_3'] / $result['cv_monthly_sales_estimate'], $result['score_6']);

        // score_7 = score_4 / cv_monthly_sales_estimate
        $this->assertNotNull($result['score_7']);
        $this->assertEquals($result['score_4'] / $result['cv_monthly_sales_estimate'], $result['score_7']);

        // score_8 = score_5 / cv_monthly_sales_estimate
        $this->assertNotNull($result['score_8']);
        $this->assertEquals($result['score_5'] / $result['cv_monthly_sales_estimate'], $result['score_8']);
    }

    public function test_compute_scores_for_stats_row_computes_score_9(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_9 = score_3 / se_results_count / cv_monthly_sales_estimate
        $this->assertNotNull($result['score_9']);
        $expectedScore9 = $result['score_3'] / 100 / $result['cv_monthly_sales_estimate'];
        $this->assertEquals($expectedScore9, $result['score_9']);
    }

    public function test_compute_scores_for_stats_row_handles_null_price(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => null, 'rating_value' => 4.5, 'rating_votes_count' => 100],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_1 should be null because price is null
        $this->assertNull($result['score_1']);
        // Dependent scores should also be null
        $this->assertNull($result['score_3']);
        $this->assertNull($result['score_4']);
        $this->assertNull($result['score_5']);
    }

    public function test_compute_scores_for_stats_row_handles_null_rating(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => null, 'rating_votes_count' => 100],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_2 should be null because rating is null
        $this->assertNull($result['score_2']);
        // score_3 should be null because it depends on score_2
        $this->assertNull($result['score_3']);
    }

    public function test_compute_scores_for_stats_row_handles_zero_reviews_count(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 0],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_2 should be null because log(0) is undefined
        $this->assertNull($result['score_2']);
    }

    public function test_compute_scores_for_stats_row_handles_objects_and_arrays(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            (object)['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200],
        ];
        $products = [
            (object)['asin' => 'B001', 'categories' => json_encode(['Books']), 'monthly_sales_estimate' => 500],
        ];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // Should handle mixed objects and arrays
        $this->assertNotNull($result['score_1']);
        $this->assertNotNull($result['score_2']);
    }

    public function test_compute_scores_for_stats_row_excludes_null_values_from_averages(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => null, 'rating_value' => null, 'rating_votes_count' => null],
            ['rank_absolute' => 3, 'bought_past_month' => 3000, 'price_from' => 30.0, 'rating_value' => 5.0, 'rating_votes_count' => 300],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // Should only use non-null values for averages
        // avg(price) = (10 + 30) / 2 = 20
        // avg(rating) = (4.5 + 5.0) / 2 = 4.75
        // avg(reviews) = (100 + 300) / 2 = 200
        $this->assertNotNull($result['score_1']);
        $this->assertNotNull($result['score_2']);
    }

    public function test_compute_scores_for_stats_row_with_single_item_no_cv(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // CV should be null with only 1 item (need at least 2 for stdev)
        $this->assertNull($result['cv_monthly_sales_estimate']);
        // Scores that depend on CV should be null
        $this->assertNull($result['score_6']);
        $this->assertNull($result['score_7']);
        $this->assertNull($result['score_8']);
        $this->assertNull($result['score_9']);
    }

    public function test_compute_scores_for_stats_row_books_category_case_insensitive(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => null, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100, 'data_asin' => 'B001'],
        ];
        $products = [
            ['asin' => 'B001', 'categories' => json_encode(['BOOKS', 'Fiction']), 'monthly_sales_estimate' => 500],
        ];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // Should match "BOOKS" case-insensitively
        $expectedScore1 = log(500 * 10);
        $this->assertEquals($expectedScore1, $result['score_1']);
    }

    public function test_compute_scores_for_stats_row_mixes_bought_past_month_and_books_fallback(): void
    {
        $listingsRow = ['se_results_count' => 100];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100, 'data_asin' => 'B001'],
            ['rank_absolute' => 2, 'bought_past_month' => null, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200, 'data_asin' => 'B002'],
        ];
        $products = [
            ['asin' => 'B002', 'categories' => json_encode(['Books']), 'monthly_sales_estimate' => 2000],
        ];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // Should use: 1000 from item 1 (bought_past_month), 2000 from product for item 2 (Books fallback)
        // avg(monthly_sales) = (1000 + 2000) / 2 = 1500
        // avg(price) = (10 + 20) / 2 = 15
        // score_1 = log(1500 * 15) = log(22500)
        $expectedScore1 = log(1500 * 15);
        $this->assertEqualsWithDelta($expectedScore1, $result['score_1'], 0.0001);

        // Both items should contribute to score_2
        // avg(rating) = (4.5 + 4.0) / 2 = 4.25
        // avg(reviews) = (100 + 200) / 2 = 150
        $expectedScore2 = 4.25 * log(150);
        $this->assertEqualsWithDelta($expectedScore2, $result['score_2'], 0.0001);

        // CV should be computed from both values
        $this->assertNotNull($result['cv_monthly_sales_estimate']);
    }

    public function test_compute_scores_for_stats_row_handles_zero_se_results_count(): void
    {
        $listingsRow = ['se_results_count' => 0];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_1 and score_2 should still compute (don't depend on se_results_count)
        $this->assertNotNull($result['score_1']);
        $this->assertNotNull($result['score_2']);
        $this->assertNotNull($result['score_3']);

        // But scores using se_results_count should be null (division by zero protection)
        $this->assertNull($result['score_4']);
        $this->assertNull($result['score_5']);
        $this->assertNull($result['score_9']);

        // Scores 6, 7, 8 depend on CV which is null for single item
        $this->assertNull($result['cv_monthly_sales_estimate']);
        $this->assertNull($result['score_6']);
        $this->assertNull($result['score_7']);
        $this->assertNull($result['score_8']);
    }

    public function test_compute_scores_for_stats_row_handles_null_se_results_count(): void
    {
        $listingsRow = ['se_results_count' => null];
        $items       = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200],
        ];
        $products = [];

        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        // score_1, score_2, score_3 should still compute
        $this->assertNotNull($result['score_1']);
        $this->assertNotNull($result['score_2']);
        $this->assertNotNull($result['score_3']);

        // But scores using se_results_count should be null
        $this->assertNull($result['score_4']);
        $this->assertNull($result['score_5']);
        $this->assertNull($result['score_9']);

        // CV and dependent scores should compute (have 2 items)
        $this->assertNotNull($result['cv_monthly_sales_estimate']);
        $this->assertNotNull($result['score_6']);
        $this->assertNull($result['score_7']); // Depends on score_4 which is null
        $this->assertNull($result['score_8']); // Depends on score_5 which is null
    }

    public static function computeScoresForStatsRowProvider(): array
    {
        return [
            'basic_scores_with_bought_past_month' => [
                'listingsRow' => ['se_results_count' => 100],
                'items'       => [
                    ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100],
                    ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200],
                ],
                'products'       => [],
                'expectedScores' => [
                    'score_1'                   => log(1500 * 15), // avg(1000,2000) * avg(10,20)
                    'score_2'                   => 4.25 * log(150), // avg(4.5,4.0) * log(avg(100,200))
                    'cv_monthly_sales_estimate' => 'not_null',
                ],
            ],
            'books_fallback' => [
                'listingsRow' => ['se_results_count' => 50],
                'items'       => [
                    ['rank_absolute' => 1, 'bought_past_month' => null, 'price_from' => 15.0, 'rating_value' => 4.8, 'rating_votes_count' => 500, 'data_asin' => 'B001'],
                ],
                'products' => [
                    ['asin' => 'B001', 'categories' => json_encode(['Books']), 'monthly_sales_estimate' => 300],
                ],
                'expectedScores' => [
                    'score_1'                   => log(300 * 15), // 300 from products, 15 from items
                    'score_2'                   => 4.8 * log(500),
                    'cv_monthly_sales_estimate' => null, // Only 1 item
                ],
            ],
            'no_monthly_sales_data' => [
                'listingsRow' => ['se_results_count' => 100],
                'items'       => [
                    ['rank_absolute' => 1, 'bought_past_month' => null, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100, 'data_asin' => 'B001'],
                ],
                'products' => [
                    ['asin' => 'B001', 'categories' => json_encode(['Electronics']), 'monthly_sales_estimate' => 500],
                ],
                'expectedScores' => [
                    'score_1'                   => null,
                    'score_2'                   => null,
                    'score_3'                   => null,
                    'cv_monthly_sales_estimate' => null,
                ],
            ],
        ];
    }

    #[DataProvider('computeScoresForStatsRowProvider')]
    public function test_compute_scores_for_stats_row_with_data_provider(
        array $listingsRow,
        array $items,
        array $products,
        array $expectedScores
    ): void {
        $result = $this->parser->computeScoresForStatsRow($listingsRow, $items, $products);

        foreach ($expectedScores as $key => $expectedValue) {
            if ($expectedValue === null) {
                $this->assertNull($result[$key], "Expected {$key} to be null");
            } elseif ($expectedValue === 'not_null') {
                $this->assertNotNull($result[$key], "Expected {$key} to not be null");
            } else {
                $this->assertNotNull($result[$key], "Expected {$key} to not be null");
                if (is_float($expectedValue)) {
                    $this->assertEqualsWithDelta($expectedValue, $result[$key], 0.0001, "Mismatch for {$key}");
                } else {
                    $this->assertEquals($expectedValue, $result[$key], "Mismatch for {$key}");
                }
            }
        }
    }

    public function test_compute_amazon_keywords_stats_row_with_minimal_data(): void
    {
        $listingsRow = [
            'id'               => 123,
            'keyword'          => 'test keyword',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'se_results_count' => 1000,
            'items_count'      => 50,
        ];

        $items    = [];
        $products = [];

        $result = $this->parser->computeAmazonKeywordsStatsRow($listingsRow, $items, $products);

        // Check primary identifiers
        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertEquals(2840, $result['location_code']);
        $this->assertEquals('en_US', $result['language_code']);
        $this->assertEquals('desktop', $result['device']);
        $this->assertEquals(123, $result['dataforseo_merchant_amazon_products_listings_id']);
        $this->assertEquals(1000, $result['se_results_count']);
        $this->assertEquals(50, $result['items_count']);

        // Check that items stats are empty
        $this->assertEmpty($result['json___bought_past_month']);
        $this->assertNull($result['avg___bought_past_month']);
        $this->assertEquals(0, $result['cnt___is_amazon_choice']);

        // Check that products stats are empty
        $this->assertEmpty($result['json___products__price']);
        $this->assertNull($result['avg___products__price']);
        $this->assertEquals(0, $result['cnt___products__is_available']);

        // Check that BSR-related fields are empty/null
        $this->assertNull($result['stdev___products__bsr_rank']);

        // Check score fields are null
        $this->assertNull($result['score_1']);
        $this->assertNull($result['score_10']);
    }

    public function test_compute_amazon_keywords_stats_row_integrates_items_stats(): void
    {
        $listingsRow = [
            'id'               => 789,
            'keyword'          => 'integration test',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'se_results_count' => 500,
            'items_count'      => 10,
        ];

        $items = [
            ['rank_absolute' => 1, 'price_from' => 10.0, 'rating_value' => 4.5],
            ['rank_absolute' => 2, 'price_from' => 20.0, 'rating_value' => 5.0],
        ];

        $products = [];

        $result = $this->parser->computeAmazonKeywordsStatsRow($listingsRow, $items, $products);

        // Check items stats are integrated
        $this->assertCount(2, $result['json___price_from']);
        $this->assertEquals([10.0, 20.0], $result['json___price_from']);
        $this->assertEquals(15.0, $result['avg___price_from']);
        $this->assertEquals(4.75, $result['avg___rating_value']);
    }

    public function test_compute_amazon_keywords_stats_row_integrates_products_stats(): void
    {
        $listingsRow = [
            'id'               => 999,
            'keyword'          => 'products test',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'se_results_count' => 300,
            'items_count'      => 5,
        ];

        $items = [];

        $products = [
            ['price' => 100.0, 'customer_rating' => 4.0, 'is_available' => 1, 'bsr_rank' => 100],
            ['price' => 200.0, 'customer_rating' => 5.0, 'is_available' => 1, 'bsr_rank' => 200],
        ];

        $result = $this->parser->computeAmazonKeywordsStatsRow($listingsRow, $items, $products);

        // Check products stats are integrated
        $this->assertCount(2, $result['json___products__price']);
        $this->assertEquals([100.0, 200.0], $result['json___products__price']);
        $this->assertEquals(150.0, $result['avg___products__price']);
        $this->assertEquals(4.5, $result['avg___products__customer_rating']);
        $this->assertEquals(2, $result['cnt___products__is_available']);

        // Check BSR stdev is computed for n=2
        $this->assertIsFloat($result['stdev___products__bsr_rank']);
        $this->assertGreaterThan(0, $result['stdev___products__bsr_rank']);
    }

    public function test_compute_amazon_keywords_stats_row_integrates_both_items_and_products(): void
    {
        $listingsRow = [
            'id'               => 111,
            'keyword'          => 'full integration',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'se_results_count' => 1500,
            'items_count'      => 20,
        ];

        $items = [
            ['rank_absolute' => 1, 'price_from' => 50.0, 'is_amazon_choice' => 1],
            ['rank_absolute' => 2, 'price_from' => 60.0, 'is_best_seller' => 1],
        ];

        $products = [
            ['price' => 55.0, 'customer_rating' => 4.5, 'publisher' => 'Independently published'],
            ['price' => 65.0, 'customer_rating' => 4.8, 'publisher' => 'Random House'],
        ];

        $result = $this->parser->computeAmazonKeywordsStatsRow($listingsRow, $items, $products);

        // Check listings data
        $this->assertEquals('full integration', $result['keyword']);
        $this->assertEquals(111, $result['dataforseo_merchant_amazon_products_listings_id']);
        $this->assertEquals(1500, $result['se_results_count']);
        $this->assertEquals(20, $result['items_count']);

        // Check items stats
        $this->assertEquals([50.0, 60.0], $result['json___price_from']);
        $this->assertEquals(55.0, $result['avg___price_from']);
        $this->assertEquals(1, $result['cnt___is_amazon_choice']);
        $this->assertEquals(1, $result['cnt___is_best_seller']);

        // Check products stats
        $this->assertEquals([55.0, 65.0], $result['json___products__price']);
        $this->assertEquals(60.0, $result['avg___products__price']);
        $this->assertEquals(4.65, $result['avg___products__customer_rating']);
        $this->assertEquals(1, $result['cnt___products__is_independently_published']);

        // Check score fields - should be null because items don't have bought_past_month or matching products
        $this->assertNull($result['score_1']);
        $this->assertNull($result['score_5']);
        $this->assertNull($result['score_10']);
    }

    public function test_compute_amazon_keywords_stats_row_computes_scores(): void
    {
        $listingsRow = [
            'id'               => 222,
            'keyword'          => 'score test',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'se_results_count' => 100,
            'items_count'      => 10,
        ];

        $items = [
            ['rank_absolute' => 1, 'bought_past_month' => 1000, 'price_from' => 10.0, 'rating_value' => 4.5, 'rating_votes_count' => 100, 'data_asin' => 'B001'],
            ['rank_absolute' => 2, 'bought_past_month' => 2000, 'price_from' => 20.0, 'rating_value' => 4.0, 'rating_votes_count' => 200, 'data_asin' => 'B002'],
        ];

        $products = [];

        $result = $this->parser->computeAmazonKeywordsStatsRow($listingsRow, $items, $products);

        // Check that scores are computed
        $this->assertNotNull($result['score_1']);
        $this->assertNotNull($result['score_2']);
        $this->assertNotNull($result['score_3']);
        $this->assertNotNull($result['score_4']);
        $this->assertNotNull($result['score_5']);
        $this->assertNotNull($result['score_6']);
        $this->assertNotNull($result['score_7']);
        $this->assertNotNull($result['score_8']);
        $this->assertNotNull($result['score_9']);
        $this->assertNotNull($result['cv_monthly_sales_estimate']);

        // Verify score_1 calculation
        // avg(monthly_sales) = (1000 + 2000) / 2 = 1500
        // avg(price) = (10 + 20) / 2 = 15
        // score_1 = log(1500 * 15) = log(22500)
        $expectedScore1 = log(1500 * 15);
        $this->assertEqualsWithDelta($expectedScore1, $result['score_1'], 0.0001);

        // Verify items and products stats are also present
        $this->assertEquals([10.0, 20.0], $result['json___price_from']);
        $this->assertEquals(15.0, $result['avg___price_from']);
    }

    public function test_compute_amazon_keywords_stats_row_computes_scores_with_books_fallback(): void
    {
        $listingsRow = [
            'id'               => 333,
            'keyword'          => 'books score test',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'se_results_count' => 50,
            'items_count'      => 5,
        ];

        $items = [
            ['rank_absolute' => 1, 'bought_past_month' => null, 'price_from' => 15.0, 'rating_value' => 4.8, 'rating_votes_count' => 500, 'data_asin' => 'B001'],
        ];

        $products = [
            ['asin' => 'B001', 'categories' => json_encode(['Books', 'Fiction']), 'monthly_sales_estimate' => 300, 'price' => 15.0],
        ];

        $result = $this->parser->computeAmazonKeywordsStatsRow($listingsRow, $items, $products);

        // Check that scores are computed using Books fallback
        $this->assertNotNull($result['score_1']);
        $this->assertNotNull($result['score_2']);

        // Verify score_1 uses monthly_sales_estimate from products
        // score_1 = log(300 * 15) = log(4500)
        $expectedScore1 = log(300 * 15);
        $this->assertEqualsWithDelta($expectedScore1, $result['score_1'], 0.0001);

        // CV should be null with only 1 item
        $this->assertNull($result['cv_monthly_sales_estimate']);
        $this->assertNull($result['score_6']);
    }

    public static function computeAmazonKeywordsStatsRowProvider(): array
    {
        return [
            'complete data with all fields' => [
                'listingsRow' => [
                    'id'               => 1,
                    'keyword'          => 'test product',
                    'location_code'    => 2840,
                    'language_code'    => 'en_US',
                    'device'           => 'desktop',
                    'se_results_count' => 5000,
                    'items_count'      => 100,
                ],
                'items' => [
                    [
                        'rank_absolute'      => 1,
                        'bought_past_month'  => 1000,
                        'price_from'         => 29.99,
                        'rating_value'       => 4.5,
                        'rating_votes_count' => 500,
                        'is_amazon_choice'   => 1,
                        'is_best_seller'     => 0,
                    ],
                    [
                        'rank_absolute'      => 2,
                        'bought_past_month'  => 2000,
                        'price_from'         => 39.99,
                        'rating_value'       => 5.0,
                        'rating_votes_count' => 1000,
                        'is_amazon_choice'   => 0,
                        'is_best_seller'     => 1,
                    ],
                ],
                'products' => [
                    [
                        'price'                  => 25.00,
                        'customer_rating'        => 4.3,
                        'customer_reviews_count' => 250,
                        'bsr_rank'               => 100,
                        'is_available'           => 1,
                        'is_amazon_choice'       => 1,
                        'publisher'              => 'Random House',
                    ],
                    [
                        'price'                  => 35.00,
                        'customer_rating'        => 4.7,
                        'customer_reviews_count' => 750,
                        'bsr_rank'               => 200,
                        'is_available'           => 1,
                        'is_amazon_choice'       => 0,
                        'publisher'              => 'Independently published',
                    ],
                ],
                'expected' => [
                    'keyword'                                         => 'test product',
                    'location_code'                                   => 2840,
                    'language_code'                                   => 'en_US',
                    'device'                                          => 'desktop',
                    'dataforseo_merchant_amazon_products_listings_id' => 1,
                    'se_results_count'                                => 5000,
                    'items_count'                                     => 100,
                    'avg___bought_past_month'                         => 1500.0,
                    'avg___price_from'                                => 34.99,
                    'avg___rating_value'                              => 4.75,
                    'cnt___is_amazon_choice'                          => 1,
                    'cnt___is_best_seller'                            => 1,
                    'avg___products__price'                           => 30.0,
                    'avg___products__customer_rating'                 => 4.5,
                    'avg___products__customer_reviews_count'          => 500.0,
                    'avg___products__bsr_rank'                        => 150.0,
                    'cnt___products__is_available'                    => 2,
                    'cnt___products__is_amazon_choice'                => 1,
                    'cnt___products__is_independently_published'      => 1,
                    'score_1'                                         => 'not_null', // Should be computed
                    'score_10'                                        => null,
                ],
            ],
            'empty items and products' => [
                'listingsRow' => [
                    'id'               => 2,
                    'keyword'          => 'empty test',
                    'location_code'    => 2840,
                    'language_code'    => 'en_US',
                    'device'           => 'mobile',
                    'se_results_count' => 100,
                    'items_count'      => 0,
                ],
                'items'    => [],
                'products' => [],
                'expected' => [
                    'keyword'                                         => 'empty test',
                    'location_code'                                   => 2840,
                    'language_code'                                   => 'en_US',
                    'device'                                          => 'mobile',
                    'dataforseo_merchant_amazon_products_listings_id' => 2,
                    'se_results_count'                                => 100,
                    'items_count'                                     => 0,
                    'avg___bought_past_month'                         => null,
                    'avg___price_from'                                => null,
                    'cnt___is_amazon_choice'                          => 0,
                    'cnt___is_best_seller'                            => 0,
                    'avg___products__price'                           => null,
                    'avg___products__customer_rating'                 => null,
                    'cnt___products__is_available'                    => 0,
                    'cnt___products__is_independently_published'      => 0,
                    'score_1'                                         => null, // No data
                    'score_10'                                        => null,
                ],
            ],
        ];
    }

    #[DataProvider('computeAmazonKeywordsStatsRowProvider')]
    public function test_compute_amazon_keywords_stats_row_with_data_provider(
        array $listingsRow,
        array $items,
        array $products,
        array $expected
    ): void {
        $result = $this->parser->computeAmazonKeywordsStatsRow($listingsRow, $items, $products);

        // Check primary identifiers
        $this->assertEquals($expected['keyword'], $result['keyword']);
        $this->assertEquals($expected['location_code'], $result['location_code']);
        $this->assertEquals($expected['language_code'], $result['language_code']);
        $this->assertEquals($expected['device'], $result['device']);
        $this->assertEquals($expected['dataforseo_merchant_amazon_products_listings_id'], $result['dataforseo_merchant_amazon_products_listings_id']);
        $this->assertEquals($expected['se_results_count'], $result['se_results_count']);
        $this->assertEquals($expected['items_count'], $result['items_count']);

        // Check items stats
        $this->assertEquals($expected['avg___bought_past_month'], $result['avg___bought_past_month']);
        $this->assertEquals($expected['avg___price_from'], $result['avg___price_from']);
        $this->assertEquals($expected['cnt___is_amazon_choice'], $result['cnt___is_amazon_choice']);
        $this->assertEquals($expected['cnt___is_best_seller'], $result['cnt___is_best_seller']);

        // Check products stats
        $this->assertEquals($expected['avg___products__price'], $result['avg___products__price']);
        $this->assertEquals($expected['avg___products__customer_rating'], $result['avg___products__customer_rating']);
        $this->assertEquals($expected['cnt___products__is_available'], $result['cnt___products__is_available']);
        $this->assertEquals($expected['cnt___products__is_independently_published'], $result['cnt___products__is_independently_published']);

        if (isset($expected['avg___products__customer_reviews_count'])) {
            $this->assertEquals($expected['avg___products__customer_reviews_count'], $result['avg___products__customer_reviews_count']);
        }
        if (isset($expected['avg___products__bsr_rank'])) {
            $this->assertEquals($expected['avg___products__bsr_rank'], $result['avg___products__bsr_rank']);
        }
        if (isset($expected['avg___rating_value'])) {
            $this->assertEquals($expected['avg___rating_value'], $result['avg___rating_value']);
        }

        // Check score fields - should be computed if data is available
        if (array_key_exists('score_1', $expected)) {
            if ($expected['score_1'] === null) {
                $this->assertNull($result['score_1']);
            } elseif ($expected['score_1'] === 'not_null') {
                $this->assertNotNull($result['score_1']);
            } else {
                $this->assertEqualsWithDelta($expected['score_1'], $result['score_1'], 0.0001);
            }
        }
        if (array_key_exists('score_10', $expected)) {
            $this->assertEquals($expected['score_10'], $result['score_10']);
        }
    }

    public function test_insert_amazon_keywords_stats_row_with_valid_data(): void
    {
        // Create a partial mock that allows real methods except the ones we mock
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Mock fetchListingsRow to return a listing
        $parser->shouldReceive('fetchListingsRow')
            ->once()
            ->with('test keyword', 2840, 'en_US', 'desktop')
            ->andReturn((object)[
                'id'               => 1,
                'keyword'          => 'test keyword',
                'location_code'    => 2840,
                'language_code'    => 'en_US',
                'device'           => 'desktop',
                'se_results_count' => 100,
                'items_count'      => 10,
            ]);

        // Mock fetchItems to return items
        $parser->shouldReceive('fetchItems')
            ->once()
            ->with('test keyword', 2840, 'en_US', 'desktop')
            ->andReturn([
                [
                    'rank_absolute'      => 1,
                    'bought_past_month'  => 1000,
                    'price_from'         => 10.0,
                    'rating_value'       => 4.5,
                    'rating_votes_count' => 100,
                    'data_asin'          => 'B0TEST001',
                ],
                [
                    'rank_absolute'      => 2,
                    'bought_past_month'  => 2000,
                    'price_from'         => 20.0,
                    'rating_value'       => 4.0,
                    'rating_votes_count' => 200,
                    'data_asin'          => 'B0TEST002',
                ],
            ]);

        $result = $parser->insertAmazonKeywordsStatsRow('test keyword');

        $this->assertEquals(1, $result['inserted']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertNull($result['reason']);

        // Verify data was inserted into amazon_keywords_stats
        $this->assertDatabaseHas('amazon_keywords_stats', [
            'keyword'          => 'test keyword',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'se_results_count' => 100,
            'items_count'      => 10,
        ]);
    }

    public function test_insert_amazon_keywords_stats_row_returns_no_listing_when_not_found(): void
    {
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Mock fetchListingsRow to return null (no listing found)
        $parser->shouldReceive('fetchListingsRow')
            ->once()
            ->with('nonexistent keyword', 2840, 'en_US', 'desktop')
            ->andReturn(null);

        // fetchItems should not be called when listing is not found
        $parser->shouldNotReceive('fetchItems');

        $result = $parser->insertAmazonKeywordsStatsRow('nonexistent keyword');

        $this->assertEquals(0, $result['inserted']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertEquals('nonexistent keyword', $result['keyword']);
        $this->assertEquals('no_listing', $result['reason']);

        // Verify no data was inserted
        $this->assertDatabaseCount('amazon_keywords_stats', 0);
    }

    public function test_insert_amazon_keywords_stats_row_skips_duplicate(): void
    {
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Mock fetchListingsRow
        $parser->shouldReceive('fetchListingsRow')
            ->twice()
            ->with('duplicate keyword', 2840, 'en_US', 'desktop')
            ->andReturn((object)[
                'id'               => 1,
                'keyword'          => 'duplicate keyword',
                'location_code'    => 2840,
                'language_code'    => 'en_US',
                'device'           => 'desktop',
                'se_results_count' => 50,
                'items_count'      => 5,
            ]);

        // Mock fetchItems
        $parser->shouldReceive('fetchItems')
            ->twice()
            ->with('duplicate keyword', 2840, 'en_US', 'desktop')
            ->andReturn([
                [
                    'rank_absolute'      => 1,
                    'bought_past_month'  => 500,
                    'price_from'         => 15.0,
                    'rating_value'       => 4.8,
                    'rating_votes_count' => 50,
                    'data_asin'          => 'B0DUP001',
                ],
            ]);

        // First insert
        $result1 = $parser->insertAmazonKeywordsStatsRow('duplicate keyword');
        $this->assertEquals(1, $result1['inserted']);
        $this->assertEquals(0, $result1['skipped']);

        // Second insert (should be skipped)
        $result2 = $parser->insertAmazonKeywordsStatsRow('duplicate keyword');
        $this->assertEquals(0, $result2['inserted']);
        $this->assertEquals(1, $result2['skipped']);
        $this->assertEquals('duplicate keyword', $result2['keyword']);
        $this->assertEquals('duplicate', $result2['reason']);

        // Verify only one row exists
        $this->assertDatabaseCount('amazon_keywords_stats', 1);
    }

    public function test_insert_amazon_keywords_stats_row_with_custom_parameters(): void
    {
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Mock fetchListingsRow with custom location/language/device
        $parser->shouldReceive('fetchListingsRow')
            ->once()
            ->with('uk keyword', 2826, 'en_GB', 'mobile')
            ->andReturn((object)[
                'id'               => 1,
                'keyword'          => 'uk keyword',
                'location_code'    => 2826,
                'language_code'    => 'en_GB',
                'device'           => 'mobile',
                'se_results_count' => 200,
                'items_count'      => 15,
            ]);

        // Mock fetchItems
        $parser->shouldReceive('fetchItems')
            ->once()
            ->with('uk keyword', 2826, 'en_GB', 'mobile')
            ->andReturn([
                [
                    'rank_absolute'      => 1,
                    'bought_past_month'  => 800,
                    'price_from'         => 12.0,
                    'rating_value'       => 4.3,
                    'rating_votes_count' => 80,
                    'data_asin'          => 'B0UK001',
                ],
            ]);

        $result = $parser->insertAmazonKeywordsStatsRow('uk keyword', 2826, 'en_GB', 'mobile');

        $this->assertEquals(1, $result['inserted']);
        $this->assertEquals('uk keyword', $result['keyword']);

        // Verify correct location/language/device
        $this->assertDatabaseHas('amazon_keywords_stats', [
            'keyword'       => 'uk keyword',
            'location_code' => 2826,
            'language_code' => 'en_GB',
            'device'        => 'mobile',
        ]);
    }

    public function test_insert_amazon_keywords_stats_row_with_empty_items(): void
    {
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Mock fetchListingsRow
        $parser->shouldReceive('fetchListingsRow')
            ->once()
            ->with('no items keyword', 2840, 'en_US', 'desktop')
            ->andReturn((object)[
                'id'               => 1,
                'keyword'          => 'no items keyword',
                'location_code'    => 2840,
                'language_code'    => 'en_US',
                'device'           => 'desktop',
                'se_results_count' => 10,
                'items_count'      => 0,
            ]);

        // Mock fetchItems to return empty array
        $parser->shouldReceive('fetchItems')
            ->once()
            ->with('no items keyword', 2840, 'en_US', 'desktop')
            ->andReturn([]);

        $result = $parser->insertAmazonKeywordsStatsRow('no items keyword');

        $this->assertEquals(1, $result['inserted']);
        $this->assertEquals('no items keyword', $result['keyword']);

        // Verify data was inserted even with no items
        $this->assertDatabaseHas('amazon_keywords_stats', [
            'keyword'     => 'no items keyword',
            'items_count' => 0,
        ]);
    }

    public function test_insert_amazon_keywords_stats_row_sets_processed_fields_to_null(): void
    {
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Mock fetchListingsRow
        $parser->shouldReceive('fetchListingsRow')
            ->once()
            ->andReturn((object)[
                'id'               => 1,
                'keyword'          => 'test processed',
                'location_code'    => 2840,
                'language_code'    => 'en_US',
                'device'           => 'desktop',
                'se_results_count' => 50,
                'items_count'      => 5,
            ]);

        // Mock fetchItems
        $parser->shouldReceive('fetchItems')
            ->once()
            ->andReturn([
                [
                    'rank_absolute'      => 1,
                    'bought_past_month'  => 100,
                    'price_from'         => 10.0,
                    'rating_value'       => 4.0,
                    'rating_votes_count' => 50,
                    'data_asin'          => 'B0PROC01',
                ],
            ]);

        $result = $parser->insertAmazonKeywordsStatsRow('test processed');

        $this->assertEquals(1, $result['inserted']);

        // Verify processed_at and processed_status are NULL
        $this->assertDatabaseHas('amazon_keywords_stats', [
            'keyword'          => 'test processed',
            'processed_at'     => null,
            'processed_status' => null,
        ]);
    }

    public function test_insert_amazon_keywords_stats_row_json_encodes_array_fields(): void
    {
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Explicitly set statsItemsLimit to ensure both test items are included
        $parser->setStatsItemsLimit(10);

        // Mock fetchListingsRow
        $parser->shouldReceive('fetchListingsRow')
            ->once()
            ->andReturn((object)[
                'id'               => 1,
                'keyword'          => 'test json',
                'location_code'    => 2840,
                'language_code'    => 'en_US',
                'device'           => 'desktop',
                'se_results_count' => 100,
                'items_count'      => 10,
            ]);

        // Mock fetchItems with multiple items to generate JSON arrays
        $parser->shouldReceive('fetchItems')
            ->once()
            ->andReturn([
                [
                    'rank_absolute'      => 1,
                    'bought_past_month'  => 1000,
                    'price_from'         => 10.0,
                    'rating_value'       => 4.5,
                    'rating_votes_count' => 100,
                    'data_asin'          => 'B0JSON01',
                ],
                [
                    'rank_absolute'      => 2,
                    'bought_past_month'  => 2000,
                    'price_from'         => 20.0,
                    'rating_value'       => 4.0,
                    'rating_votes_count' => 200,
                    'data_asin'          => 'B0JSON02',
                ],
            ]);

        $result = $parser->insertAmazonKeywordsStatsRow('test json');

        $this->assertEquals(1, $result['inserted']);

        // Verify JSON fields are properly encoded as strings in database
        $stats = $this->app['db']->table('amazon_keywords_stats')
            ->where('keyword', 'test json')
            ->first();

        $this->assertNotNull($stats);

        // json___price_from should be a JSON string
        $this->assertIsString($stats->json___price_from);
        $priceFromArray = json_decode($stats->json___price_from, true);
        $this->assertIsArray($priceFromArray);

        // Both items have rank_absolute <= statsItemsLimit (10), so both prices should be included
        $this->assertCount(2, $priceFromArray);
        // Values might be stored as strings or numbers, so check both
        $this->assertTrue(
            in_array(10.0, $priceFromArray, true) || in_array('10', $priceFromArray, true) || in_array(10, $priceFromArray, true),
            'Array should contain 10.0: ' . json_encode($priceFromArray)
        );
        $this->assertTrue(
            in_array(20.0, $priceFromArray, true) || in_array('20', $priceFromArray, true) || in_array(20, $priceFromArray, true),
            'Array should contain 20.0: ' . json_encode($priceFromArray)
        );
    }

    public function test_insert_amazon_keywords_stats_row_with_products_data(): void
    {
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Explicitly set statsAmazonProductsLimit to ensure all 3 test items are included
        $parser->setStatsAmazonProductsLimit(3);

        // Mock fetchListingsRow
        $parser->shouldReceive('fetchListingsRow')
            ->once()
            ->with('books keyword', 2840, 'en_US', 'desktop')
            ->andReturn((object)[
                'id'               => 1,
                'keyword'          => 'books keyword',
                'location_code'    => 2840,
                'language_code'    => 'en_US',
                'device'           => 'desktop',
                'se_results_count' => 500,
                'items_count'      => 20,
            ]);

        // Mock fetchItems - items with rank_absolute <= 3 will query products table
        $parser->shouldReceive('fetchItems')
            ->once()
            ->with('books keyword', 2840, 'en_US', 'desktop')
            ->andReturn([
                [
                    'rank_absolute'      => 1,
                    'bought_past_month'  => null, // No bought_past_month, should use products fallback
                    'price_from'         => 15.0,
                    'rating_value'       => 4.5,
                    'rating_votes_count' => 150,
                    'data_asin'          => 'B0BOOK001',
                ],
                [
                    'rank_absolute'      => 2,
                    'bought_past_month'  => 800,
                    'price_from'         => 12.0,
                    'rating_value'       => 4.3,
                    'rating_votes_count' => 120,
                    'data_asin'          => 'B0BOOK002',
                ],
                [
                    'rank_absolute'      => 3,
                    'bought_past_month'  => null,
                    'price_from'         => 18.0,
                    'rating_value'       => 4.7,
                    'rating_votes_count' => 200,
                    'data_asin'          => 'B0BOOK003',
                ],
            ]);

        // Insert products into amazon_products table (this is our internal table)
        $this->app['db']->table('amazon_products')->insert([
            [
                'asin'                   => 'B0BOOK001',
                'title'                  => 'Test Book 1',
                'categories'             => json_encode(['Books', 'Fiction']),
                'monthly_sales_estimate' => 1000,
                'price'                  => 15.0,
                'customer_rating'        => 4.5,
                'customer_reviews_count' => 150,
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            [
                'asin'                   => 'B0BOOK002',
                'title'                  => 'Test Book 2',
                'categories'             => json_encode(['Books', 'Non-Fiction']),
                'monthly_sales_estimate' => 800,
                'price'                  => 12.0,
                'customer_rating'        => 4.3,
                'customer_reviews_count' => 120,
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            [
                'asin'                   => 'B0BOOK003',
                'title'                  => 'Test Book 3',
                'categories'             => json_encode(['Books', 'Science']),
                'monthly_sales_estimate' => 1200,
                'price'                  => 18.0,
                'customer_rating'        => 4.7,
                'customer_reviews_count' => 200,
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
        ]);

        $result = $parser->insertAmazonKeywordsStatsRow('books keyword');

        $this->assertEquals(1, $result['inserted']);
        $this->assertEquals('books keyword', $result['keyword']);

        // Verify data was inserted
        $this->assertDatabaseHas('amazon_keywords_stats', [
            'keyword'          => 'books keyword',
            'se_results_count' => 500,
            'items_count'      => 20,
        ]);

        // Verify products data was used in computation
        $stats = $this->app['db']->table('amazon_keywords_stats')
            ->where('keyword', 'books keyword')
            ->first();

        $this->assertNotNull($stats);

        // Verify json___products__price contains the 3 product prices
        $productsPriceArray = json_decode($stats->json___products__price, true);
        $this->assertIsArray($productsPriceArray);
        $this->assertCount(3, $productsPriceArray);

        // Values might be stored as strings or numbers
        $this->assertTrue(
            in_array(15.0, $productsPriceArray, true) || in_array('15', $productsPriceArray, true) || in_array(15, $productsPriceArray, true),
            'Array should contain 15.0: ' . json_encode($productsPriceArray)
        );
        $this->assertTrue(
            in_array(12.0, $productsPriceArray, true) || in_array('12', $productsPriceArray, true) || in_array(12, $productsPriceArray, true),
            'Array should contain 12.0: ' . json_encode($productsPriceArray)
        );
        $this->assertTrue(
            in_array(18.0, $productsPriceArray, true) || in_array('18', $productsPriceArray, true) || in_array(18, $productsPriceArray, true),
            'Array should contain 18.0: ' . json_encode($productsPriceArray)
        );

        // Verify avg___products__monthly_sales_estimate is computed from products
        // (1000 + 800 + 1200) / 3 = 1000
        $this->assertEquals(1000.0, $stats->avg___products__monthly_sales_estimate);
    }

    public function test_insert_amazon_keywords_stats_row_computes_all_key_fields(): void
    {
        /** @var AmazonProductPageParser&\Mockery\MockInterface $parser */
        $parser = Mockery::mock(AmazonProductPageParser::class)->makePartial();

        // Explicitly set statsItemsLimit to ensure both test items are included
        $parser->setStatsItemsLimit(10);

        // Mock fetchListingsRow
        $parser->shouldReceive('fetchListingsRow')
            ->once()
            ->andReturn((object)[
                'id'               => 1,
                'keyword'          => 'comprehensive test',
                'location_code'    => 2840,
                'language_code'    => 'en_US',
                'device'           => 'desktop',
                'se_results_count' => 1000,
                'items_count'      => 50,
            ]);

        // Mock fetchItems with data that will generate all stats
        $parser->shouldReceive('fetchItems')
            ->once()
            ->andReturn([
                [
                    'rank_absolute'      => 1,
                    'bought_past_month'  => 5000,
                    'price_from'         => 25.0,
                    'rating_value'       => 4.8,
                    'rating_votes_count' => 500,
                    'data_asin'          => 'B0COMP001',
                ],
                [
                    'rank_absolute'      => 2,
                    'bought_past_month'  => 3000,
                    'price_from'         => 30.0,
                    'rating_value'       => 4.5,
                    'rating_votes_count' => 300,
                    'data_asin'          => 'B0COMP002',
                ],
            ]);

        $result = $parser->insertAmazonKeywordsStatsRow('comprehensive test');

        $this->assertEquals(1, $result['inserted']);

        // Verify all key computed fields are present and not null
        $stats = $this->app['db']->table('amazon_keywords_stats')
            ->where('keyword', 'comprehensive test')
            ->first();

        $this->assertNotNull($stats);

        // Verify listing fields
        $this->assertEquals(1, $stats->dataforseo_merchant_amazon_products_listings_id);
        $this->assertEquals('comprehensive test', $stats->keyword);
        $this->assertEquals(2840, $stats->location_code);
        $this->assertEquals('en_US', $stats->language_code);
        $this->assertEquals('desktop', $stats->device);
        $this->assertEquals(1000, $stats->se_results_count);
        $this->assertEquals(50, $stats->items_count);

        // Verify items stats are computed
        $this->assertNotNull($stats->avg___price_from);
        $this->assertEquals(27.5, $stats->avg___price_from); // (25 + 30) / 2
        $this->assertNotNull($stats->avg___bought_past_month);
        $this->assertEquals(4000.0, $stats->avg___bought_past_month); // (5000 + 3000) / 2

        // Verify scores are computed
        $this->assertNotNull($stats->score_1);
        $this->assertNotNull($stats->score_2);
        $this->assertNotNull($stats->score_3);
        $this->assertNotNull($stats->cv_monthly_sales_estimate);

        // Verify timestamps
        $this->assertNotNull($stats->created_at);
        $this->assertNotNull($stats->updated_at);
        $this->assertNull($stats->processed_at);
        $this->assertNull($stats->processed_status);
    }
}
