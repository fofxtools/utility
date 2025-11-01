<?php
require_once __DIR__ . '/../pageview-tracking-core/track_bots.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Tracking Test</title>
    <script src="track_daily.js"></script>
</head>
<body>
    <h1>Daily Tracking Test</h1>
    <p>This page tests the simplified daily tracking script with:</p>
    <ul>
        <li>Single beacon per pageview</li>
        <li>Daily aggregation only (no event-level tracking)</li>
        <li>No performance metrics</li>
        <li>Visibility-aware tracking</li>
    </ul>
    <p>Page loaded at: <span id="loadTime"></span></p>

    <h2>Performance Metrics</h2>
    <ul>
        <li>TTFB: <span id="ttfb">calculating...</span></li>
        <li>DOM Content Loaded: <span id="dcl">calculating...</span></li>
        <li>Load Event End: <span id="load">calculating...</span></li>
    </ul>
    <p><em>Note: These metrics are displayed for testing but NOT sent to the server.</em></p>

    <script>
    document.getElementById('loadTime').textContent = new Date().toLocaleTimeString();

    // Display performance metrics after page load (for testing only, not sent to server)
    window.addEventListener('load', function() {
        setTimeout(function() {
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            var t = performance.timing;
            var metrics = nav ? {
                ttfb_ms: Math.round(nav.responseStart - nav.startTime),
                dom_content_loaded_ms: Math.round(nav.domContentLoadedEventEnd - nav.startTime),
                load_event_end_ms: Math.round(nav.loadEventEnd - nav.startTime)
            } : (t && t.loadEventEnd ? {
                ttfb_ms: Math.round(t.responseStart - t.navigationStart),
                dom_content_loaded_ms: Math.round(t.domContentLoadedEventEnd - t.navigationStart),
                load_event_end_ms: Math.round(t.loadEventEnd - t.navigationStart)
            } : null);

            if (metrics) {
                document.getElementById('ttfb').textContent = metrics.ttfb_ms + 'ms';
                document.getElementById('dcl').textContent = metrics.dom_content_loaded_ms + 'ms';
                document.getElementById('load').textContent = metrics.load_event_end_ms + 'ms';
            } else {
                document.getElementById('ttfb').textContent = 'unavailable';
                document.getElementById('dcl').textContent = 'unavailable';
                document.getElementById('load').textContent = 'unavailable';
            }
        }, 0);
    });
    </script>
</body>
</html>
