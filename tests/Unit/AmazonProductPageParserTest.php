<?php

declare(strict_types=1);

namespace FOfX\Utility\Tests\Unit;

use FOfX\Utility\Tests\TestCase;
use FOfX\Utility\AmazonProductPageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

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
}
