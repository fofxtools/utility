<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Arr;

use function FOfX\Utility\list_embedded_json_selectors;
use function FOfX\Utility\extract_embedded_json_blocks;

$html = file_get_contents(__DIR__ . '/../resources/2-httpswwwfiverrcomcategoriesgraphics-designcreative-logo-design-fiverrcom-browserhtml.html');

// See what JSON <script> tags exist
$selectors = list_embedded_json_selectors($html, includeLdJson: true);
//print_r($selectors); // e.g., ["script#layout-routes", "script#perseus-initial-props", ...]

// Extract blocks (decoded as associative arrays by default)
$blocks = extract_embedded_json_blocks($html, includeLdJson: true, assoc: true);

// Pick a block (ID match if present)
$index = 0;
foreach ($blocks as $i => $b) {
    if (($b['id'] ?? '') === 'perseus-initial-props') {
        $index = $i;

        break;
    }
}

$data = $blocks[$index]['json'] ?? [];

// Inspect keys to discover structure. Use array_keys() to remove values.
$paths = array_keys(Arr::dot($data));
//print_r($paths);

// For a Fiverr category listings page:
$data = $data['listings'][0]['gigs'] ?? [];
// Or use rawListingData section, which has less information:
//$data = $data['rawListingData']['gigs'] ?? [];

$paths = array_keys(Arr::dot($data));
print_r($paths);
