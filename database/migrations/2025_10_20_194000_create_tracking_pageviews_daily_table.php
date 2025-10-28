<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tracking_pageviews_daily', function (Blueprint $table) {
            $table->id();
            $table->date('pageview_date')->index();
            $table->string('domain')->index();
            $table->boolean('is_internal')->index();
            $table->string('category')->default('none')->index();
            $table->integer('pageviews')->default(0)->index();
            $table->integer('googlebot_ua_pageviews')->default(0)->index();
            $table->integer('googlebot_ip_pageviews')->default(0)->index();
            $table->integer('google_ip_pageviews')->default(0)->index();
            $table->integer('bingbot_ua_pageviews')->default(0)->index();
            $table->integer('bingbot_ip_pageviews')->default(0)->index();
            $table->integer('microsoft_ip_pageviews')->default(0)->index();
            $table->integer('cnt_pageviews_with_metrics')->default(0)->index(); // Count of pageviews with metrics passed
            $table->float('avg_ttfb_ms')->nullable()->index();
            $table->float('median_ttfb_ms')->nullable()->index();
            $table->float('p95_ttfb_ms')->nullable()->index();
            $table->float('avg_dom_content_loaded_ms')->nullable()->index();
            $table->float('median_dom_content_loaded_ms')->nullable()->index();
            $table->float('p95_dom_content_loaded_ms')->nullable()->index();
            $table->float('avg_load_event_end_ms')->nullable()->index();
            $table->float('median_load_event_end_ms')->nullable()->index();
            $table->float('p95_load_event_end_ms')->nullable()->index();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->json('processed_status')->nullable();

            $table->unique(['pageview_date', 'domain', 'is_internal', 'category'], 'tpv_pageview_date_domain_is_internal_category_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracking_pageviews_daily');
    }
};
