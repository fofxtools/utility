<?php

declare(strict_types=1);

namespace FOfX\Utility;

use Symfony\Component\DomCrawler\Crawler;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    // Database table and migration
    protected string $amazonProductsTable         = 'amazon_products';
    protected string $amazonProductsMigrationPath = __DIR__ . '/../database/migrations/2025_10_05_194500_create_amazon_products_table.php';

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
}
