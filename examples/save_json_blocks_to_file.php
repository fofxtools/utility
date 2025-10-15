<?php

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility;
use Illuminate\Support\Facades\Storage;

$start = microtime(true);

$files = [
    __DIR__ . '/../resources/1-httpswwwfiverrcomcategories-fiverrcom-common.html',
    __DIR__ . '/../resources/2-httpswwwfiverrcomcategoriesgraphics-designcreative-logo-design-fiverrcom-browserhtml.html',
    __DIR__ . '/../resources/25-httpswwwfiverrcomvprosscreate-a-top-quality-retro-vintage-logo-for-youcontext-referrersubcategory-listingsourcepaginationref-ctx-idb46fa114ae314be9926cc4a808c204efpckg-id1pos8conte.html',
    __DIR__ . '/../resources/33-httpswwwfiverrcomtonigdesign-fiverrcom-browserhtml.html',
    __DIR__ . '/../resources/42-httpswwwfiverrcomsearchgigsquerywriter27s20block-fiverrcom-browserhtml.html',
];

foreach ($files as $filename) {
    $html = file_get_contents($filename);
    // Save to file if not existing
    $saveFilename = pathinfo($filename, PATHINFO_FILENAME) . '.json';
    if (!Storage::disk('local')->exists($saveFilename)) {
        // Saves to Storage::disk('local')
        $newFile = Utility\save_json_blocks_to_file($html, $saveFilename, 'perseus-initial-props');
        echo $newFile . PHP_EOL;
    } else {
        echo "File exists: $saveFilename" . PHP_EOL;
    }
}

$end = microtime(true);
echo "\nTotal time: " . ($end - $start) . " seconds\n";
