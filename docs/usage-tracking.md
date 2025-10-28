# Pageview and Bot Tracking

Pageview tracking system with bot detection using JavaScript and PHP.

## Database Setup

Migrations for the `tracking_pageviews` and `tracking_pageviews_daily` tables:

```bash
database/migrations/2025_10_20_185122_create_tracking_pageviews_table.php
database/migrations/2025_10_20_194000_create_tracking_pageviews_daily_table.php
```

To ensure tables exist:

```bash
php scripts/ensure_tracking_tables.php
```

## Bot IP Ranges Setup

For bot IP detection, we use source data from Google (gstatic.com), Microsoft (bing.com), and searches from the ARIN whois registry.

Download source IP data for Google and Bing from within the `resources/` folder:

```bash
curl -L "https://whois.arin.net/rest/nets;q=google?showDetails=true&showARIN=true&showNonArinTopLevelNet=false&ext=netref2" \
  | xmllint --format - > whois.arin.net-rest-nets-q-google.xml

curl -s https://www.gstatic.com/ipranges/goog.json -o gstatic.com-ipranges-goog.json

curl -s https://www.gstatic.com/ipranges/cloud.json -o gstatic.com-ipranges-cloud.json

curl -L "https://whois.arin.net/rest/nets;q=microsoft?showDetails=true&showARIN=true&showNonArinTopLevelNet=false&ext=netref2" \
  | xmllint --format - > whois.arin.net-rest-nets-q-microsoft.xml

curl -s https://www.bing.com/toolbox/bingbot.json -o bing.com-toolbox-bingbot.json
```

Process the downloaded data:

```bash
php scripts/convert_arin_xml_to_cidr.php
php scripts/generate_bot_ip_arrays.php
```

This creates:
- `scripts/google_ip_ranges.php`
- `scripts/bing_ip_ranges.php`

## Tracking Scripts

### Two Tracking Approaches

**Full Tracking** (dual-beacon with metrics):
- JavaScript: `scripts/track_pageview.js`
- PHP Backend: `scripts/track.php`
- Test Page: `scripts/test_track_pageview.php`
- Features: Event-level data + performance metrics (TTFB, DCL, Load)

**Daily Tracking** (simplified):
- JavaScript: `scripts/track_daily.js`
- PHP Backend: `scripts/track_daily.php`
- Test Page: `scripts/test_track_daily.php`
- Features: Daily aggregates only, no event-level data or metrics

### Bot Tracking

- `scripts/track_bots.php` - Tracks all pageviews in PHP (no JavaScript)
- Detects Googlebot and Bingbot by IP and User-Agent
- Runs on all pageviews including bot traffic

### Supporting Files

- `scripts/track_config.php` - Blacklist configuration for IPs and User-Agents
- `scripts/track_common.php` - Shared functions for all tracking scripts

### Testing

Visit the test pages in a browser to verify tracking is working:
- `scripts/test_track_pageview.php`
- `scripts/test_track_daily.php`

## Dummy Data Generation

These utility scripts can generate dummy pageview data for `tracking_pageviews`, and then fill the `tracking_pageviews_daily` table based on the dummy data. And calculate statistics in `tracking_pageviews_daily` from the dummy data:

```bash
php scripts/generate_dummy_pageviews.php
php scripts/fill_tracking_pageviews_daily.php
php scripts/calculate_daily_stats.php
```

## Integration

Include the tracking script in your HTML pages:

**For full tracking:**
```html
<script src="/scripts/track_pageview.js"></script>
```

**For daily tracking only:**
```html
<script src="/scripts/track_daily.js"></script>
```

**For bot tracking** (add to your PHP pages):
```php
<?php require_once __DIR__ . '/scripts/track_bots.php'; ?>
```
