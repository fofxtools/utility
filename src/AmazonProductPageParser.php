<?php

declare(strict_types=1);

namespace FOfX\Utility;

use Symfony\Component\DomCrawler\Crawler;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use FOfX\Helper;

/**
 * Parser for Amazon product pages
 *
 * Extracts product details from Amazon product pages that use either:
 * - Format 1: detailBulletsWrapper (list-based, used for books)
 * - Format 2: prodDetails (table-based, used for toys, electronics, beauty)
 */
class AmazonProductPageParser
{
    // Format 1: detailBullets (Books)
    protected string $detailBulletsWrapper = '#detailBulletsWrapper_feature_div';
    protected string $detailBulletsList    = '.detail-bullet-list li';
    protected string $labelSelector        = '.a-text-bold';

    // Format 2: prodDetails (Toys, Electronics, Beauty)
    protected string $prodDetailsWrapper  = '#prodDetails';
    protected string $prodDetailsTable    = 'table.prodDetTable';
    protected string $tableHeaderSelector = 'th.prodDetSectionEntry';

    // Special cases
    protected string $starSelector        = '.a-icon-star';
    protected string $starAltSelector     = '.a-icon-alt';
    protected string $reviewCountSelector = '#acrCustomerReviewText';

    // Text cleaning
    protected string $bidiPattern = '/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u';

    // Date field names (snake_case)
    protected array $dateFields = [
        'publication_date',
        'release_date',
        'date_first_available',
    ];

    // Database tables and migrations
    protected string $amazonProductsTable                           = 'amazon_products';
    protected string $amazonProductsMigrationPath                   = __DIR__ . '/../database/migrations/2025_10_05_194500_create_amazon_products_table.php';
    protected string $amazonKeywordsStatsTable                      = 'amazon_keywords_stats';
    protected string $amazonKeywordsStatsTableMigrationPath         = __DIR__ . '/../database/migrations/2025_10_06_111107_create_amazon_keywords_stats_table.php';
    protected string $dataforseoMerchantAmazonProductsListingsTable = 'dataforseo_merchant_amazon_products_listings';
    protected string $dataforseoMerchantAmazonProductsItemsTable    = 'dataforseo_merchant_amazon_products_items';

    // Stats limits
    protected int $statsItemsLimit          = 10;
    protected int $statsAmazonProductsLimit = 3;

    // JSON encoding flags
    protected int $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Get amazon_products table name
     *
     * @return string
     */
    public function getAmazonProductsTable(): string
    {
        return $this->amazonProductsTable;
    }

    /**
     * Set amazon_products table name
     *
     * @param string $name
     *
     * @return void
     */
    public function setAmazonProductsTable(string $name): void
    {
        $this->amazonProductsTable = $name;
    }

    /**
     * Get amazon_products migration path
     *
     * @return string
     */
    public function getAmazonProductsMigrationPath(): string
    {
        return $this->amazonProductsMigrationPath;
    }

    /**
     * Set amazon_products migration path
     *
     * @param string $path
     *
     * @return void
     */
    public function setAmazonProductsMigrationPath(string $path): void
    {
        $this->amazonProductsMigrationPath = $path;
    }

    /**
     * Get amazon_keywords_stats table name
     *
     * @return string
     */
    public function getAmazonKeywordsStatsTable(): string
    {
        return $this->amazonKeywordsStatsTable;
    }

    /**
     * Set amazon_keywords_stats table name
     *
     * @param string $name
     *
     * @return void
     */
    public function setAmazonKeywordsStatsTable(string $name): void
    {
        $this->amazonKeywordsStatsTable = $name;
    }

    /**
     * Get amazon_keywords_stats migration path
     *
     * @return string
     */
    public function getAmazonKeywordsStatsTableMigrationPath(): string
    {
        return $this->amazonKeywordsStatsTableMigrationPath;
    }

    /**
     * Set amazon_keywords_stats migration path
     *
     * @param string $path
     *
     * @return void
     */
    public function setAmazonKeywordsStatsTableMigrationPath(string $path): void
    {
        $this->amazonKeywordsStatsTableMigrationPath = $path;
    }

    /**
     * Get dataforseo_merchant_amazon_products_listings table name
     *
     * @return string
     */
    public function getDataforseoMerchantAmazonProductsListingsTable(): string
    {
        return $this->dataforseoMerchantAmazonProductsListingsTable;
    }

    /**
     * Set dataforseo_merchant_amazon_products_listings table name
     *
     * @param string $name
     *
     * @return void
     */
    public function setDataforseoMerchantAmazonProductsListingsTable(string $name): void
    {
        $this->dataforseoMerchantAmazonProductsListingsTable = $name;
    }

    /**
     * Get dataforseo_merchant_amazon_products_items table name
     *
     * @return string
     */
    public function getDataforseoMerchantAmazonProductsItemsTable(): string
    {
        return $this->dataforseoMerchantAmazonProductsItemsTable;
    }

    /**
     * Set dataforseo_merchant_amazon_products_items table name
     *
     * @param string $name
     *
     * @return void
     */
    public function setDataforseoMerchantAmazonProductsItemsTable(string $name): void
    {
        $this->dataforseoMerchantAmazonProductsItemsTable = $name;
    }

    /**
     * Get stats items limit
     *
     * @return int
     */
    public function getStatsItemsLimit(): int
    {
        return $this->statsItemsLimit;
    }

    /**
     * Set stats items limit
     *
     * @param int $limit
     *
     * @return void
     */
    public function setStatsItemsLimit(int $limit): void
    {
        $this->statsItemsLimit = $limit;
    }

    /**
     * Get stats amazon products limit
     *
     * @return int
     */
    public function getStatsAmazonProductsLimit(): int
    {
        return $this->statsAmazonProductsLimit;
    }

    /**
     * Set stats amazon products limit
     *
     * @param int $limit
     *
     * @return void
     */
    public function setStatsAmazonProductsLimit(int $limit): void
    {
        $this->statsAmazonProductsLimit = $limit;
    }

    /**
     * Get JSON encoding flags
     *
     * @return int
     */
    public function getJsonFlags(): int
    {
        return $this->jsonFlags;
    }

    /**
     * Set JSON encoding flags
     *
     * @param int $flags
     *
     * @return void
     */
    public function setJsonFlags(int $flags): void
    {
        $this->jsonFlags = $flags;
    }

    /**
     * Clean label text (removes bidi marks and trims colons)
     *
     * @param string $text
     *
     * @return string
     */
    public function cleanLabel(string $text): string
    {
        // Remove bidi marks
        $text = preg_replace($this->bidiPattern, '', $text);

        // Trim whitespace and colons (labels often have trailing colons like "Publisher:")
        return trim($text, " :\n\r\t");
    }

    /**
     * Clean value text (removes bidi marks, preserves colons)
     *
     * @param string $text
     *
     * @return string
     */
    public function cleanValue(string $text): string
    {
        // Remove bidi marks
        $text = preg_replace($this->bidiPattern, '', $text);

        // Trim whitespace only (values may legitimately contain colons)
        return trim($text);
    }

    /**
     * Extract ASIN from canonical URL
     *
     * Amazon product pages have a canonical URL in the format:
     * https://www.amazon.com/.../dp/{ASIN} or https://www.amazon.com/.../gp/product/{ASIN}
     *
     * ASINs are typically 10 alphanumeric characters. For books, the ISBN-10 is used as the ASIN.
     *
     * @param Crawler $crawler
     *
     * @return string|null The ASIN if found, null otherwise
     */
    public function extractAsin(Crawler $crawler): ?string
    {
        // Extract canonical URL
        $node         = $crawler->filter('link[rel~="canonical"]');
        $canonicalUrl = $node->count() ? trim($node->first()->attr('href')) : null;

        if ($canonicalUrl === null) {
            return null;
        }

        // Extract ASIN from URL pattern: /dp/{ASIN} or /gp/product/{ASIN}
        // ASINs are exactly 10 characters: alphanumeric for standard products, numeric for ISBNs
        // Use word boundary or non-alphanumeric to ensure exactly 10 characters
        if (preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})(?:[^A-Z0-9]|$)#i', $canonicalUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract product title
     *
     * @param Crawler $crawler
     *
     * @return string|null
     */
    public function extractTitle(Crawler $crawler): ?string
    {
        // Try common title selectors
        $selectors = [
            '#productTitle',
            '#title',
            'h1.product-title',
            'h1 span#productTitle',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                return trim($node->text());
            }
        }

        return null;
    }

    /**
     * Extract product description
     *
     * @param Crawler $crawler
     *
     * @return string|null
     */
    public function extractDescription(Crawler $crawler): ?string
    {
        // Try various description selectors
        $selectors = [
            '#feature-bullets ul li span',
            '#feature-bullets',
            '#productDescription p',
            '#bookDescription_feature_div',
            '.a-section.a-spacing-medium',
        ];

        foreach ($selectors as $selector) {
            $nodes = $crawler->filter($selector);
            if ($nodes->count() > 0) {
                $description = '';

                // If it's multiple nodes (bullet points), concatenate them
                if ($nodes->count() > 1) {
                    $nodes->each(function (Crawler $node) use (&$description) {
                        $text = trim($node->text());
                        if (!empty($text) && strlen($text) > 10) {
                            $description .= $text . ' ';
                        }
                    });
                } else {
                    $description = trim($nodes->text());
                }

                if (!empty($description) && strlen($description) > 20) {
                    return trim($description);
                }
            }
        }

        return null;
    }

    /**
     * Extract currency code
     *
     * @param Crawler $crawler
     *
     * @return string|null
     */
    public function extractCurrency(Crawler $crawler): ?string
    {
        // Try to find currency in hidden inputs or data attributes
        $selectors = [
            'input[name*="currencyCode"]',
            '[data-currency]',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                $currency = $node->attr('value') ?: $node->attr('data-currency');
                if (!empty($currency)) {
                    return strtoupper(trim($currency));
                }
            }
        }

        // Fallback: detect from price symbol
        $priceNode = $crawler->filter('.a-price .a-price-symbol');
        if ($priceNode->count() > 0) {
            $symbol      = trim($priceNode->text());
            $currencyMap = [
                '$'  => 'USD',
                '€'  => 'EUR',
                '£'  => 'GBP',
                '¥'  => 'JPY',
                'C$' => 'CAD',
                'A$' => 'AUD',
            ];

            return $currencyMap[$symbol] ?? null;
        }

        return null;
    }

    /**
     * Extract product price
     *
     * @param Crawler $crawler
     *
     * @return float|null
     */
    public function extractPrice(Crawler $crawler): ?float
    {
        // Try various price selectors
        $selectors = [
            '.a-price[data-a-color="price"] .a-offscreen',
            '.a-price .a-offscreen',
            '#priceblock_ourprice',
            '#priceblock_dealprice',
            '#price_inside_buybox',
            '.a-price-whole',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                $priceText = $node->text();
                // Extract numeric value from price text like "$29.99" or "29.99"
                if (preg_match('/[\d,]+\.?\d*/', $priceText, $matches)) {
                    $price = str_replace(',', '', $matches[0]);

                    return (float) $price;
                }
            }
        }

        return null;
    }

    /**
     * Extract availability status
     *
     * @param Crawler $crawler
     *
     * @return bool|null
     */
    public function extractIsAvailable(Crawler $crawler): ?bool
    {
        // Try various availability selectors
        $selectors = [
            '#availability span',
            '#availability',
            '.a-color-success',
            '.a-color-state',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                $availText = strtolower($node->text());

                // Check for unavailable indicators FIRST (before checking for "available")
                if (preg_match('/unavailable|out of stock|not available/i', $availText)) {
                    return false;
                }

                // Check for "in stock" or similar positive indicators
                if (preg_match('/in stock|available|get it|ships from|add to cart/i', $availText)) {
                    return true;
                }
            }
        }

        // Check if "Add to Cart" button exists (indicates availability)
        $addToCartBtn = $crawler->filter('#add-to-cart-button, #buy-now-button');
        if ($addToCartBtn->count() > 0) {
            return true;
        }

        return null;
    }

    /**
     * Extract Amazon's Choice badge
     *
     * @param Crawler $crawler
     *
     * @return bool|null True if badge found, null if not found
     */
    public function extractIsAmazonChoice(Crawler $crawler): ?bool
    {
        // Try various Amazon's Choice badge selectors
        $selectors = [
            '#amazons-choice-badge',
            '.ac-badge-wrapper',
            '[data-csa-c-type="widget"][data-csa-c-content-id="amazonChoice"]',
            'span:contains("Amazon\'s Choice")',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                return true;
            }
        }

        // Also check if the text "Amazon's Choice" appears anywhere
        $bodyNode = $crawler->filter('body');
        if ($bodyNode->count() > 0) {
            $bodyText = $bodyNode->text();
            if (Str::contains($bodyText, "Amazon's Choice", ignoreCase: true)) {
                return true;
            }
        }

        return null;
    }

    /**
     * Extract main product image URL
     *
     * @param Crawler $crawler
     *
     * @return string|null
     */
    public function extractImageUrl(Crawler $crawler): ?string
    {
        // Try various image selectors
        $selectors = [
            '#landingImage',
            '#imgBlkFront',
            '#main-image',
            '.a-dynamic-image',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                // Get src or data-old-hires attribute
                $imageUrl = $node->attr('src');
                if (empty($imageUrl)) {
                    $imageUrl = $node->attr('data-old-hires');
                }
                if (empty($imageUrl)) {
                    $imageUrl = $node->attr('data-a-dynamic-image');
                    // data-a-dynamic-image is JSON with URLs as keys
                    if (!empty($imageUrl)) {
                        $imageData = json_decode($imageUrl, true);
                        if (is_array($imageData) && !empty($imageData)) {
                            $imageUrl = array_key_first($imageData);
                        }
                    }
                }

                if (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    return $imageUrl;
                }
            }
        }

        return null;
    }

    /**
     * Extract byline info (brand/author/store - raw text from Amazon)
     *
     * @param Crawler $crawler
     *
     * @return string|null
     */
    public function extractBylineInfo(Crawler $crawler): ?string
    {
        $node = $crawler->filter('#bylineInfo');
        if ($node->count() > 0) {
            return trim($node->text());
        }

        return null;
    }

    /**
     * Extract category breadcrumbs
     *
     * @param Crawler $crawler
     *
     * @return array
     */
    public function extractCategories(Crawler $crawler): array
    {
        $categories = [];

        // Try breadcrumb navigation
        $selectors = [
            '#wayfinding-breadcrumbs_feature_div ul.a-unordered-list li a',
            '#wayfinding-breadcrumbs_container a',
            '.a-breadcrumb a',
        ];

        foreach ($selectors as $selector) {
            $nodes = $crawler->filter($selector);
            if ($nodes->count() > 0) {
                $nodes->each(function (Crawler $node) use (&$categories) {
                    $categoryText = trim($node->text());
                    if (!empty($categoryText)) {
                        $categories[] = $categoryText;
                    }
                });

                if (!empty($categories)) {
                    return $categories;
                }
            }
        }

        return $categories;
    }

    /**
     * Extract publisher (from product details table)
     *
     * @param Crawler $crawler
     *
     * @return string|null
     */
    public function extractPublisher(Crawler $crawler): ?string
    {
        $publisher = null;

        // Look in detail bullets list format (books)
        $detailBullets = $crawler->filter('#detailBulletsWrapper_feature_div .detail-bullet-list li');
        $detailBullets->each(function (Crawler $node) use (&$publisher) {
            $labelNode = $node->filter('.a-text-bold');
            if ($labelNode->count() > 0) {
                $label = trim($labelNode->text());
                if (Str::contains($label, 'Publisher', ignoreCase: true)) {
                    // Get full text and remove the label
                    $fullText  = $node->text();
                    $value     = str_replace($label, '', $fullText);
                    $publisher = trim($value, " :\n\r\t");
                }
            }
        });

        if (!empty($publisher)) {
            return $publisher;
        }

        // Look in product details table format (other products)
        $prodDetailsRows = $crawler->filter('#prodDetails table tr');
        $prodDetailsRows->each(function (Crawler $row) use (&$publisher) {
            $th = $row->filter('th');
            $td = $row->filter('td');

            if ($th->count() > 0 && $td->count() > 0) {
                $label = trim($th->text());
                if (Str::contains($label, 'Publisher', ignoreCase: true)) {
                    $publisher = trim($td->text());
                }
            }
        });

        return $publisher;
    }

    /**
     * Extract product details from detailBulletsWrapper (list format)
     *
     * @param Crawler $crawler
     *
     * @return array
     */
    public function extractFromDetailBullets(Crawler $crawler): array
    {
        $details = [];

        $detailBullets = $crawler->filter($this->detailBulletsWrapper . ' ' . $this->detailBulletsList);

        $detailBullets->each(function (Crawler $node) use (&$details) {
            $labelNode = $node->filter($this->labelSelector);

            if ($labelNode->count() > 0) {
                $fullText  = $node->text();
                $labelText = $labelNode->text();

                // Extract value by removing the label from the full text
                $value = str_replace($labelText, '', $fullText);
                $value = trim($value);

                // Clean up label and value
                $label          = $this->cleanLabel($labelText);
                $value          = $this->cleanValue($value);
                $labelLowercase = strtolower($label);

                if ($labelLowercase !== 'customer reviews' && !empty($label) && !empty($value)) {
                    $snakeCaseLabel           = Str::snake($labelLowercase);
                    $details[$snakeCaseLabel] = $value;
                }
            }

            // Handle Best Sellers Rank specially
            if (Str::contains($node->text(), 'Best Sellers Rank', ignoreCase: true)) {
                $fullText = $node->text();
                if (preg_match('/#([\d,]+)\s+in\s+([^(]+)/', $fullText, $matches)) {
                    $details['best_sellers_rank'] = '#' . $matches[1] . ' in ' . trim($matches[2]);
                }
            }

            // Handle Customer Reviews
            if (Str::contains($node->text(), 'Customer Reviews', ignoreCase: true)) {
                $stars = $node->filter($this->starSelector);
                if ($stars->count() > 0) {
                    $starText = $stars->attr('class');
                    if (preg_match('/a-star-([\d-]+)/', $starText, $matches)) {
                        $rating                     = str_replace('-', '.', $matches[1]);
                        $details['customer_rating'] = $rating;
                    }
                }

                if (preg_match('/(\d+)\s+ratings?/', $node->text(), $matches)) {
                    $details['customer_reviews_count'] = $matches[1];
                }
            }
        });

        return $details;
    }

    /**
     * Extract product details from prodDetails (table format)
     *
     * @param Crawler $crawler
     *
     * @return array
     */
    public function extractFromProdDetails(Crawler $crawler): array
    {
        $details = [];

        // Find all product detail tables
        $tables = $crawler->filter($this->prodDetailsWrapper . ' ' . $this->prodDetailsTable);

        $tables->each(function (Crawler $table) use (&$details) {
            $rows = $table->filter('tr');

            $rows->each(function (Crawler $row) use (&$details) {
                $th = $row->filter($this->tableHeaderSelector);
                $td = $row->filter('td');

                if ($th->count() > 0 && $td->count() > 0) {
                    $label          = trim($th->text());
                    $labelLowercase = strtolower($label);

                    // Special handling for Customer Reviews
                    if ($labelLowercase === 'customer reviews') {
                        $stars = $td->filter($this->starSelector);
                        if ($stars->count() > 0) {
                            $starAlt = $stars->filter($this->starAltSelector);
                            if ($starAlt->count() > 0) {
                                $altText = $starAlt->text();
                                if (preg_match('/([\d.]+)\s+out of/', $altText, $matches)) {
                                    $details['customer_rating'] = $matches[1];
                                }
                            }
                        }

                        $reviewCount = $td->filter($this->reviewCountSelector);
                        if ($reviewCount->count() > 0) {
                            $reviewText = $reviewCount->text();
                            if (preg_match('/([\d,]+)\s+ratings?/', $reviewText, $matches)) {
                                $details['customer_reviews_count'] = str_replace(',', '', $matches[1]);
                            }
                        }
                    } elseif ($labelLowercase === 'best sellers rank') {
                        // Extract the main rank
                        $tdText = $td->text();
                        if (preg_match('/#([\d,]+)\s+in\s+([^(]+)/', $tdText, $matches)) {
                            $details['best_sellers_rank'] = '#' . $matches[1] . ' in ' . trim($matches[2]);
                        }
                    } else {
                        $value = $this->cleanValue($td->text());

                        if (!empty($label) && !empty($value)) {
                            $snakeCaseLabel           = Str::snake($labelLowercase);
                            $details[$snakeCaseLabel] = $value;
                        }
                    }
                }
            });
        });

        return $details;
    }

    /**
     * Parse Amazon product page HTML and extract product details
     *
     * @param string $html Raw HTML content from Amazon product page
     *
     * @return array Associative array of product details (label => value)
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $details = [];

        // Extract ASIN from canonical URL first (most reliable, always present)
        $asin = $this->extractAsin($crawler);
        if ($asin !== null) {
            $details['asin'] = $asin;
        }

        // Extract using new extraction methods
        $title = $this->extractTitle($crawler);
        if ($title !== null) {
            $details['title'] = $title;
        }

        $description = $this->extractDescription($crawler);
        if ($description !== null) {
            $details['description'] = $description;
        }

        $currency = $this->extractCurrency($crawler);
        if ($currency !== null) {
            $details['currency'] = $currency;
        }

        $price = $this->extractPrice($crawler);
        if ($price !== null) {
            $details['price'] = $price;
        }

        $isAvailable = $this->extractIsAvailable($crawler);
        if ($isAvailable !== null) {
            $details['is_available'] = $isAvailable;
        }

        $isAmazonChoice = $this->extractIsAmazonChoice($crawler);
        if ($isAmazonChoice !== null) {
            $details['is_amazon_choice'] = $isAmazonChoice;
        }

        $imageUrl = $this->extractImageUrl($crawler);
        if ($imageUrl !== null) {
            $details['image_url'] = $imageUrl;
        }

        $bylineInfo = $this->extractBylineInfo($crawler);
        if ($bylineInfo !== null) {
            $details['byline_info'] = $bylineInfo;
        }

        $categories = $this->extractCategories($crawler);
        if (!empty($categories)) {
            $details['categories'] = $categories;
        }

        $publisher = $this->extractPublisher($crawler);
        if ($publisher !== null) {
            $details['publisher'] = $publisher;
        }

        // Try format 1: detailBullets
        if ($crawler->filter($this->detailBulletsWrapper)->count() > 0) {
            $details = array_merge($details, $this->extractFromDetailBullets($crawler));
        }

        // Try format 2: prodDetails
        if ($crawler->filter($this->prodDetailsWrapper)->count() > 0) {
            $details = array_merge($details, $this->extractFromProdDetails($crawler));
        }

        return $details;
    }

    /**
     * Get page count from parsed details
     *
     * @param array $details Parsed product details from parse()
     *
     * @return int|null Page count or null if not available
     */
    public function getPageCount(array $details): ?int
    {
        if (!isset($details['print_length'])) {
            return null;
        }

        $printLength = $details['print_length'];

        // Extract number from "1 page", "103 pages" or "688 pages"
        // s? matches singular or plural pages
        if (preg_match('/(\d+)\s+pages?/i', $printLength, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get Best Sellers Rank from parsed details
     *
     * @param array $details Parsed product details from parse()
     *
     * @return int|null BSR rank or null if not available
     */
    public function getBsrRank(array $details): ?int
    {
        if (!isset($details['best_sellers_rank'])) {
            return null;
        }

        $bsrRank = $details['best_sellers_rank'];

        // Extract number from "#18,775 in Books" or "#4 in Toys & Games"
        if (preg_match('/#([\d,]+)/', $bsrRank, $matches)) {
            // Remove commas and convert to int
            return (int) str_replace(',', '', $matches[1]);
        }

        return null;
    }

    /**
     * Get Best Sellers Rank category from parsed details
     *
     * @param array $details Parsed product details from parse()
     *
     * @return string|null Category name or null if not available
     */
    public function getBsrCategory(array $details): ?string
    {
        if (!isset($details['best_sellers_rank'])) {
            return null;
        }

        $bsrRank = $details['best_sellers_rank'];

        // Extract category from "#18,775 in Books" or "#4 in Toys & Games"
        if (preg_match('/#[\d,]+\s+in\s+(.+)/', $bsrRank, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Get normalized date from parsed details
     *
     * Checks for date fields from $this->dateFields:
     * - Publication date
     * - Release date
     * - Date First Available
     *
     * @param array $details      Parsed product details from parse()
     * @param bool  $convertToYmd If true, converts date to Y-m-d format using Carbon (returns null if conversion fails)
     *
     * @return string|null Date string or null if not available
     */
    public function getNormalizedDate(array $details, bool $convertToYmd = true): ?string
    {
        foreach ($this->dateFields as $field) {
            if (isset($details[$field]) && !empty($details[$field])) {
                $dateString = $details[$field];

                if ($convertToYmd) {
                    try {
                        return Carbon::parse($dateString)->format('Y-m-d');
                    } catch (\Exception $e) {
                        // If parsing fails, return null
                        Log::warning('Carbon failed to parse date: ' . $dateString, ['exception' => $e]);

                        return null;
                    }
                }

                return $dateString;
            }
        }

        return null;
    }

    /**
     * Parse Amazon product page HTML and extract all data including computed helper fields
     *
     * This method calls parse() and then adds computed fields from helper methods:
     * - page_count (from getPageCount)
     * - bsr_rank (from getBsrRank)
     * - bsr_category (from getBsrCategory)
     * - normalized_date (from getNormalizedDate)
     *
     * @param string $html Raw HTML content from Amazon product page
     *
     * @return array Associative array with all parsed data and computed fields
     */
    public function parseAll(string $html): array
    {
        $details = $this->parse($html);

        // Add computed helper fields
        $pageCount = $this->getPageCount($details);
        if ($pageCount !== null) {
            $details['page_count'] = $pageCount;
        }

        $bsrRank = $this->getBsrRank($details);
        if ($bsrRank !== null) {
            $details['bsr_rank'] = $bsrRank;
        }

        $bsrCategory = $this->getBsrCategory($details);
        if ($bsrCategory !== null) {
            $details['bsr_category'] = $bsrCategory;
        }

        $normalizedDate = $this->getNormalizedDate($details);
        if ($normalizedDate !== null) {
            $details['normalized_date'] = $normalizedDate;
        }

        // Add KDP fields if independently published
        $publisher = $details['publisher'] ?? null;
        if ($publisher !== null && Str::contains($publisher, 'Independently published', ignoreCase: true)) {
            // Get trim size from dimensions (books use 'dimensions', non-books use 'product_dimensions')
            $dimensions = $details['dimensions'] ?? null;
            if ($dimensions !== null) {
                $kdpTrimSize = kdp_trim_size_inches($dimensions);
                if ($kdpTrimSize !== null) {
                    $details['kdp_trim_size'] = $kdpTrimSize;
                }
            }

            // Calculate KDP royalty estimate (assuming black ink)
            $price = $details['price'] ?? null;
            if ($price !== null && $pageCount !== null && isset($details['kdp_trim_size'])) {
                $isTrimSizeLarge = $details['kdp_trim_size'] === 'large';

                try {
                    $kdpRoyalty = kdp_royalty_us(
                        listPrice: (float) $price,
                        numPages: $pageCount,
                        isColor: false,
                        isPremiumInk: false,
                        isTrimSizeLarge: $isTrimSizeLarge
                    );
                    $details['kdp_royalty_estimate'] = $kdpRoyalty;
                } catch (\InvalidArgumentException $e) {
                    // Page count out of valid range, skip royalty calculation
                }
            }
        }

        // Calculate monthly sales estimate from BSR
        if ($bsrRank !== null) {
            $monthlySales = bsr_to_monthly_sales_books($bsrRank);
            if ($monthlySales !== null) {
                $details['bsr_monthly_sales_estimate_books'] = (int) round($monthlySales);
            }
        }

        return $details;
    }

    /**
     * Insert Amazon product from parsed data array into amazon_products table
     *
     * Uses insertOrIgnore to skip duplicates safely.
     * Expects data from parseAll() method.
     *
     * @param array $data Parsed product data from parseAll()
     *
     * @return array{inserted:int,skipped:int,asin:?string,reason:?string}
     */
    public function insertProduct(array $data): array
    {
        ensure_table_exists($this->amazonProductsTable, $this->amazonProductsMigrationPath);

        // Get ASIN with fallback to isbn-10
        $asin = $data['asin'] ?? $data['isbn-10'] ?? null;

        if ($asin === null) {
            return [
                'inserted' => 0,
                'skipped'  => 1,
                'asin'     => null,
                'reason'   => 'no_asin',
            ];
        }

        // If first category is Books, set monthly_sales_estimate from bsr_monthly_sales_estimate_books
        $monthlySalesEstimate = null;
        if (isset($data['categories'][0]) && strtolower($data['categories'][0]) === 'books') {
            $monthlySalesEstimate = $data['bsr_monthly_sales_estimate_books'] ?? null;
        }

        // Prepare data for insertion
        $insertData = [
            'asin'                   => $asin,
            'parsed_data'            => json_encode($data, $this->jsonFlags),
            'title'                  => $data['title'] ?? null,
            'description'            => $data['description'] ?? null,
            'currency'               => $data['currency'] ?? null,
            'price'                  => $data['price'] ?? null,
            'is_available'           => $data['is_available'] ?? null,
            'is_amazon_choice'       => $data['is_amazon_choice'] ?? null,
            'image_url'              => $data['image_url'] ?? null,
            'byline_info'            => $data['byline_info'] ?? null,
            'categories'             => isset($data['categories']) ? json_encode($data['categories'], $this->jsonFlags) : null,
            'publisher'              => $data['publisher'] ?? null,
            'customer_rating'        => isset($data['customer_rating']) ? (float)$data['customer_rating'] : null,
            'customer_reviews_count' => isset($data['customer_reviews_count']) ? (int)$data['customer_reviews_count'] : null,
            'bsr_rank'               => $data['bsr_rank'] ?? null,
            'bsr_category'           => $data['bsr_category'] ?? null,
            'normalized_date'        => $data['normalized_date'] ?? null,
            'page_count'             => $data['page_count'] ?? null,
            'kdp_trim_size'          => $data['kdp_trim_size'] ?? null,
            'kdp_royalty_estimate'   => $data['kdp_royalty_estimate'] ?? null,
            'monthly_sales_estimate' => $monthlySalesEstimate,
            'processed_at'           => null,
            'processed_status'       => null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ];

        // Insert using insertOrIgnore (skips duplicates)
        $inserted = DB::table($this->amazonProductsTable)->insertOrIgnore($insertData);

        if ($inserted > 0) {
            return [
                'inserted' => 1,
                'skipped'  => 0,
                'asin'     => $asin,
                'reason'   => null,
            ];
        } else {
            return [
                'inserted' => 0,
                'skipped'  => 1,
                'asin'     => $asin,
                'reason'   => 'duplicate',
            ];
        }
    }

    /**
     * Compute statistics from dataforseo_merchant_amazon_products_items
     *
     * Filters items by rank_absolute <= $statsItemsLimit and computes:
     * - JSON arrays of values (from items where rank_absolute <= $statsItemsLimit)
     * - Averages for numeric fields (excluding nulls)
     * - Counts for boolean fields (counting true values)
     *
     * @param array $items Array of items from dataforseo_merchant_amazon_products_items table
     *
     * @return array Statistics array with json___, avg___, and cnt___ keys
     */
    public function computeItemsStats(array $items): array
    {
        // Filter items by rank_absolute <= statsItemsLimit
        $filteredItems = array_filter($items, function ($item) {
            $rankAbsolute = is_object($item) ? $item->rank_absolute : ($item['rank_absolute'] ?? null);

            return $rankAbsolute !== null && $rankAbsolute <= $this->statsItemsLimit;
        });

        // Initialize result arrays
        $stats = [
            // JSON arrays
            'json___bought_past_month'  => [],
            'json___price_from'         => [],
            'json___price_to'           => [],
            'json___rating_value'       => [],
            'json___rating_votes_count' => [],
            'json___rating_rating_max'  => [],
            'json___is_amazon_choice'   => [],
            'json___is_best_seller'     => [],
            // Averages
            'avg___bought_past_month'  => null,
            'avg___price_from'         => null,
            'avg___price_to'           => null,
            'avg___rating_value'       => null,
            'avg___rating_votes_count' => null,
            'avg___rating_rating_max'  => null,
            // Counts
            'cnt___is_amazon_choice' => 0,
            'cnt___is_best_seller'   => 0,
        ];

        // Collect values for JSON arrays and averages
        foreach ($filteredItems as $item) {
            // Helper function to get value from object or array
            $getValue = function ($key) use ($item) {
                return is_object($item) ? ($item->$key ?? null) : ($item[$key] ?? null);
            };

            // Collect numeric values
            $boughtPastMonth = $getValue('bought_past_month');
            if ($boughtPastMonth !== null) {
                $stats['json___bought_past_month'][] = $boughtPastMonth;
            }

            $priceFrom = $getValue('price_from');
            if ($priceFrom !== null) {
                $stats['json___price_from'][] = $priceFrom;
            }

            $priceTo = $getValue('price_to');
            if ($priceTo !== null) {
                $stats['json___price_to'][] = $priceTo;
            }

            $ratingValue = $getValue('rating_value');
            if ($ratingValue !== null) {
                $stats['json___rating_value'][] = $ratingValue;
            }

            $ratingVotesCount = $getValue('rating_votes_count');
            if ($ratingVotesCount !== null) {
                $stats['json___rating_votes_count'][] = $ratingVotesCount;
            }

            $ratingRatingMax = $getValue('rating_rating_max');
            if ($ratingRatingMax !== null) {
                $stats['json___rating_rating_max'][] = $ratingRatingMax;
            }

            // Collect boolean values
            $isAmazonChoice = $getValue('is_amazon_choice');
            if ($isAmazonChoice !== null) {
                $stats['json___is_amazon_choice'][] = (bool) $isAmazonChoice;
                if ($isAmazonChoice) {
                    $stats['cnt___is_amazon_choice']++;
                }
            }

            $isBestSeller = $getValue('is_best_seller');
            if ($isBestSeller !== null) {
                $stats['json___is_best_seller'][] = (bool) $isBestSeller;
                if ($isBestSeller) {
                    $stats['cnt___is_best_seller']++;
                }
            }
        }

        // Compute averages (excluding nulls)
        $stats['avg___bought_past_month'] = !empty($stats['json___bought_past_month'])
            ? array_sum($stats['json___bought_past_month']) / count($stats['json___bought_past_month'])
            : null;
        $stats['avg___price_from'] = !empty($stats['json___price_from'])
            ? array_sum($stats['json___price_from']) / count($stats['json___price_from'])
            : null;
        $stats['avg___price_to'] = !empty($stats['json___price_to'])
            ? array_sum($stats['json___price_to']) / count($stats['json___price_to'])
            : null;
        $stats['avg___rating_value'] = !empty($stats['json___rating_value'])
            ? array_sum($stats['json___rating_value']) / count($stats['json___rating_value'])
            : null;
        $stats['avg___rating_votes_count'] = !empty($stats['json___rating_votes_count'])
            ? array_sum($stats['json___rating_votes_count']) / count($stats['json___rating_votes_count'])
            : null;
        $stats['avg___rating_rating_max'] = !empty($stats['json___rating_rating_max'])
            ? array_sum($stats['json___rating_rating_max']) / count($stats['json___rating_rating_max'])
            : null;

        return $stats;
    }

    /**
     * Compute statistics from amazon_products table
     *
     * Computes:
     * - JSON arrays of values (all products provided)
     * - Averages for numeric fields (excluding nulls)
     * - Counts for boolean fields (counting true values)
     * - Average date (computed by converting to timestamps, averaging, and converting back)
     *
     * Note: is_independently_published is computed from publisher field
     * (true if publisher contains "Independently")
     *
     * @param array $products Array of products from amazon_products table
     *
     * @return array Statistics array with json___products__, avg___products__, and cnt___products__ keys
     */
    public function computeProductsStats(array $products): array
    {
        // Initialize result arrays
        $stats = [
            // JSON arrays
            'json___products__price'                      => [],
            'json___products__customer_rating'            => [],
            'json___products__customer_reviews_count'     => [],
            'json___products__bsr_rank'                   => [],
            'json___products__normalized_date'            => [],
            'json___products__page_count'                 => [],
            'json___products__is_available'               => [],
            'json___products__is_amazon_choice'           => [],
            'json___products__is_independently_published' => [],
            'json___products__kdp_royalty_estimate'       => [],
            'json___products__monthly_sales_estimate'     => [],
            // Averages
            'avg___products__price'                  => null,
            'avg___products__customer_rating'        => null,
            'avg___products__customer_reviews_count' => null,
            'avg___products__bsr_rank'               => null,
            'avg___products__normalized_date'        => null,
            'avg___products__page_count'             => null,
            'avg___products__kdp_royalty_estimate'   => null,
            'avg___products__monthly_sales_estimate' => null,
            // Counts
            'cnt___products__is_available'               => 0,
            'cnt___products__is_amazon_choice'           => 0,
            'cnt___products__is_independently_published' => 0,
            // Standard deviation in BSR rank
            'stdev___products__bsr_rank' => null,
        ];

        // Collect values for JSON arrays and averages
        foreach ($products as $product) {
            // Helper function to get value from object or array
            $getValue = function ($key) use ($product) {
                return is_object($product) ? ($product->$key ?? null) : ($product[$key] ?? null);
            };

            // Collect numeric values
            $price = $getValue('price');
            if ($price !== null) {
                $stats['json___products__price'][] = (float) $price;
            }

            $customerRating = $getValue('customer_rating');
            if ($customerRating !== null) {
                $stats['json___products__customer_rating'][] = (float) $customerRating;
            }

            $customerReviewsCount = $getValue('customer_reviews_count');
            if ($customerReviewsCount !== null) {
                $stats['json___products__customer_reviews_count'][] = (int) $customerReviewsCount;
            }

            $bsrRank = $getValue('bsr_rank');
            if ($bsrRank !== null) {
                $stats['json___products__bsr_rank'][] = (int) $bsrRank;
            }

            $normalizedDate = $getValue('normalized_date');
            if ($normalizedDate !== null) {
                $stats['json___products__normalized_date'][] = $normalizedDate;
            }

            $pageCount = $getValue('page_count');
            if ($pageCount !== null) {
                $stats['json___products__page_count'][] = (int) $pageCount;
            }

            $kdpRoyaltyEstimate = $getValue('kdp_royalty_estimate');
            if ($kdpRoyaltyEstimate !== null) {
                $stats['json___products__kdp_royalty_estimate'][] = (float) $kdpRoyaltyEstimate;
            }

            $monthlySalesEstimate = $getValue('monthly_sales_estimate');
            if ($monthlySalesEstimate !== null) {
                $stats['json___products__monthly_sales_estimate'][] = (int) $monthlySalesEstimate;
            }

            // Collect boolean values
            $isAvailable = $getValue('is_available');
            if ($isAvailable !== null) {
                $stats['json___products__is_available'][] = (bool) $isAvailable;
                if ($isAvailable) {
                    $stats['cnt___products__is_available']++;
                }
            }

            $isAmazonChoice = $getValue('is_amazon_choice');
            if ($isAmazonChoice !== null) {
                $stats['json___products__is_amazon_choice'][] = (bool) $isAmazonChoice;
                if ($isAmazonChoice) {
                    $stats['cnt___products__is_amazon_choice']++;
                }
            }

            // Compute is_independently_published from publisher field
            $publisher = $getValue('publisher');
            if ($publisher !== null) {
                $isIndependentlyPublished                               = Str::contains($publisher, 'Independently published', ignoreCase: true);
                $stats['json___products__is_independently_published'][] = $isIndependentlyPublished;
                if ($isIndependentlyPublished) {
                    $stats['cnt___products__is_independently_published']++;
                }
            }
        }

        // Compute averages (excluding nulls)
        $stats['avg___products__price'] = !empty($stats['json___products__price'])
            ? array_sum($stats['json___products__price']) / count($stats['json___products__price'])
            : null;
        $stats['avg___products__customer_rating'] = !empty($stats['json___products__customer_rating'])
            ? array_sum($stats['json___products__customer_rating']) / count($stats['json___products__customer_rating'])
            : null;
        $stats['avg___products__customer_reviews_count'] = !empty($stats['json___products__customer_reviews_count'])
            ? array_sum($stats['json___products__customer_reviews_count']) / count($stats['json___products__customer_reviews_count'])
            : null;
        $stats['avg___products__bsr_rank'] = !empty($stats['json___products__bsr_rank'])
            ? array_sum($stats['json___products__bsr_rank']) / count($stats['json___products__bsr_rank'])
            : null;
        $stats['avg___products__page_count'] = !empty($stats['json___products__page_count'])
            ? array_sum($stats['json___products__page_count']) / count($stats['json___products__page_count'])
            : null;
        $stats['avg___products__kdp_royalty_estimate'] = !empty($stats['json___products__kdp_royalty_estimate'])
            ? array_sum($stats['json___products__kdp_royalty_estimate']) / count($stats['json___products__kdp_royalty_estimate'])
            : null;
        $stats['avg___products__monthly_sales_estimate'] = !empty($stats['json___products__monthly_sales_estimate'])
            ? array_sum($stats['json___products__monthly_sales_estimate']) / count($stats['json___products__monthly_sales_estimate'])
            : null;

        // Compute average date (convert to timestamps, average, convert back)
        if (!empty($stats['json___products__normalized_date'])) {
            $dates                                    = $stats['json___products__normalized_date'];
            $timestamps                               = array_map(fn ($date) => strtotime($date), $dates);
            $avgTimestamp                             = array_sum($timestamps) / count($timestamps);
            $stats['avg___products__normalized_date'] = date('Y-m-d', (int) $avgTimestamp);
        }

        // Compute standard deviation of BSR ranks (null for n<=1)
        if (count($stats['json___products__bsr_rank']) > 1) {
            $stats['stdev___products__bsr_rank'] = Helper\array_stdev($stats['json___products__bsr_rank']);
        } else {
            $stats['stdev___products__bsr_rank'] = null;
        }

        return $stats;
    }

    /**
     * Compute amazon_keywords_stats row
     *
     * Combines data from:
     * - Listings table row (keyword identifiers, se_results_count, items_count)
     * - Items table stats (via computeItemsStats)
     * - Products table stats (via computeProductsStats)
     *
     * @param array $listingsRow Listings table row matching (keyword, location_code, language_code, device)
     * @param array $items       Array of items table rows matching the keyword combination
     * @param array $products    Array of products table rows where ASIN was in data_asin values
     *
     * @return array Row data ready for insertion into amazon_keywords_stats (excluding id, timestamps, processed_at, processed_status)
     */
    public function computeAmazonKeywordsStatsRow(
        array $listingsRow,
        array $items,
        array $products
    ): array {
        // Helper function to get value from object or array
        $getValue = function ($key, $data) {
            return is_object($data) ? ($data->$key ?? null) : ($data[$key] ?? null);
        };

        // Extract primary identifiers from listings row
        $row = [
            'keyword'                                         => $getValue('keyword', $listingsRow),
            'location_code'                                   => $getValue('location_code', $listingsRow),
            'language_code'                                   => $getValue('language_code', $listingsRow),
            'device'                                          => $getValue('device', $listingsRow),
            'dataforseo_merchant_amazon_products_listings_id' => $getValue('id', $listingsRow),
            'se_results_count'                                => $getValue('se_results_count', $listingsRow),
            'items_count'                                     => $getValue('items_count', $listingsRow),
        ];

        // Compute items stats
        $itemsStats = $this->computeItemsStats($items);

        // Compute products stats
        $productsStats = $this->computeProductsStats($products);

        // Merge items stats into row
        $row = array_merge($row, $itemsStats);

        // Merge products stats into row
        $row = array_merge($row, $productsStats);

        // Initialize score fields (to be computed later)
        $row['score_1']  = null;
        $row['score_2']  = null;
        $row['score_3']  = null;
        $row['score_4']  = null;
        $row['score_5']  = null;
        $row['score_6']  = null;
        $row['score_7']  = null;
        $row['score_8']  = null;
        $row['score_9']  = null;
        $row['score_10'] = null;

        return $row;
    }
}
