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
- `scripts/plugins/pageview-tracking-core/google_ip_ranges.php`
- `scripts/plugins/pageview-tracking-core/bing_ip_ranges.php`

## track_common.php - Shared Functions

Shared functions for all tracking scripts:
- `is_internal_page($url)` - Check if URL is an internal page
- `is_googlebot_ua($userAgent)` - Check if user agent is Googlebot
- `is_bingbot_ua($userAgent)` - Check if user agent is Bingbot
- `is_googlebot_ip($ip)` - Check if IP is Googlebot (only 'goog' source)
- `is_google_ip($ip)` - Check if IP is any Google IP (all sources)
- `is_bingbot_ip($ip)` - Check if IP is Bingbot (only 'bingbot' source)
- `is_microsoft_ip($ip)` - Check if IP is any Microsoft IP (all sources)
- `is_ip_excluded($ip)` - Check if IP is excluded according to `track_config.php`
- `is_user_agent_excluded($userAgent)` - Check if user agent is excluded according to `track_config.php`
- `is_excluded($ip, $userAgent)` - Check if IP or user agent is excluded according to `track_config.php`
- `get_tracking_config()` - Parse request data, create PDO connection, and return tracking configuration array

## WordPress Plugins

The tracking scripts are organized as three WordPress plugins in `scripts/plugins/`:

### pageview-tracking-core (required)
Core shared functionality required by the other two plugins.

**Files:**
- `pageview-tracking-core.php` - Main plugin file
- `track_common.php` - Shared functions
- `track_config.php` - Exclude configuration for IPs and User-Agents
- `track_bots.php` - Bot tracking (detects Googlebot and Bingbot)
- `google_ip_ranges.php` - Google bot IP ranges
- `bing_ip_ranges.php` - Bing bot IP ranges
- `generate_dummy_pageviews.php` - Utility script
- `fill_tracking_pageviews_daily.php` - Utility script
- `calculate_daily_stats.php` - Utility script

### pageview-tracking
Full tracking with dual-beacon approach and performance metrics.

**Files:**
- `pageview-tracking.php` - Main plugin file
- `track_pageview.js` - JavaScript beacon
- `track_pageview.php` - PHP backend
- `test_track_pageview.php` - Test page

**Features:** Event-level data + performance metrics (TTFB, DCL, Load)

### pageview-tracking-daily
Simplified daily tracking without event-level data.

**Files:**
- `pageview-tracking-daily.php` - Main plugin file
- `track_daily.js` - JavaScript beacon
- `track_daily.php` - PHP backend
- `test_track_daily.php` - Test page

**Features:** Daily aggregates only, no event-level data or metrics

### Testing

Visit the test pages in a browser to verify tracking is working:
- `scripts/plugins/pageview-tracking/test_track_pageview.php`
- `scripts/plugins/pageview-tracking-daily/test_track_daily.php`

## Dummy Data Generation

These utility scripts can generate dummy pageview data for `tracking_pageviews`, and then fill the `tracking_pageviews_daily` table based on the dummy data. And calculate statistics in `tracking_pageviews_daily` from the dummy data:

```bash
php scripts/plugins/pageview-tracking-core/generate_dummy_pageviews.php
php scripts/plugins/pageview-tracking-core/fill_tracking_pageviews_daily.php
php scripts/plugins/pageview-tracking-core/calculate_daily_stats.php
```

## Integration

### WordPress
Activate the plugins in WordPress:
1. Activate `pageview-tracking-core` (required)
2. Activate `pageview-tracking` (for full tracking) or `pageview-tracking-daily` (for daily tracking only)

The plugins will automatically enqueue the JavaScript tracking scripts and handle bot tracking.

### Standalone (Non-WordPress)
Include the tracking script in your HTML pages:

**For full tracking:**
```html
<script src="/scripts/plugins/pageview-tracking/track_pageview.js"></script>
```

**For daily tracking only:**
```html
<script src="/scripts/plugins/pageview-tracking-daily/track_daily.js"></script>
```

**For bot tracking** (add to your PHP pages):
```php
<?php require_once __DIR__ . '/scripts/plugins/pageview-tracking-core/track_bots.php'; ?>
```

### Configuration

The tracking plugins can be configured by editing `scripts/plugins/pageview-tracking-core/track_config.php`.

You can set the database configuration file path. The default is to auto-detect WordPress `wp-config.php`, then fallback to `.env`. You can also specify a relative path to a custom `wp-config.php` or `.env` file.

You can also set the exclusion lists for IPs, CIDR ranges, and user agents in `track_config.php`. e.g.:
```php
'exclude_ips' => ['127.0.0.1', '2001:db8::1'],
'exclude_ips_cidr' => ['192.168.1.0/24', '2001:db8::/32'],
'exclude_user_agents_exact' => ['BadBot/1.0', 'Scraper/2.0'],
'exclude_user_agents_substring' => ['badbot', 'scraper'],
```