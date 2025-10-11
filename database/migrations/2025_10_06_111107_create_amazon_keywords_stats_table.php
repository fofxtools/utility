<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::create('amazon_keywords_stats', function (Blueprint $table) {
            $table->id();

            // Primary identifiers (composite unique key)
            $table->string('keyword')->index();
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();

            // Listings table row ID (for traceability)
            $table->unsignedBigInteger('dataforseo_merchant_amazon_products_listings_id')->nullable();

            // From dataforseo_merchant_amazon_products_listings
            $table->unsignedBigInteger('se_results_count')->nullable()->index();
            $table->integer('items_count')->nullable()->index();

            // From dataforseo_merchant_amazon_products_items - JSON arrays
            $table->json('json___bought_past_month')->nullable();
            $table->json('json___price_from')->nullable();
            $table->json('json___price_to')->nullable();
            $table->json('json___rating_value')->nullable();
            $table->json('json___rating_votes_count')->nullable();
            $table->json('json___rating_rating_max')->nullable();
            $table->json('json___is_amazon_choice')->nullable();
            $table->json('json___is_best_seller')->nullable();

            // From dataforseo_merchant_amazon_products_items - Averages
            $table->float('avg___bought_past_month')->nullable()->index();
            $table->float('avg___price_from')->nullable()->index();
            $table->float('avg___price_to')->nullable()->index();
            $table->float('avg___rating_value')->nullable()->index();
            $table->float('avg___rating_votes_count')->nullable()->index();
            $table->float('avg___rating_rating_max')->nullable()->index();

            // From dataforseo_merchant_amazon_products_items - Counts
            $table->integer('cnt___is_amazon_choice')->nullable()->index();
            $table->integer('cnt___is_best_seller')->nullable()->index();

            // From amazon_products (joined by data_asin) - JSON arrays
            $table->json('json___products__price')->nullable();
            $table->json('json___products__customer_rating')->nullable();
            $table->json('json___products__customer_reviews_count')->nullable();
            $table->json('json___products__bsr_rank')->nullable();
            $table->json('json___products__normalized_date')->nullable();
            $table->json('json___products__page_count')->nullable();
            $table->json('json___products__is_available')->nullable();
            $table->json('json___products__is_amazon_choice')->nullable();
            $table->json('json___products__is_independently_published')->nullable();
            $table->json('json___products__kdp_royalty_estimate')->nullable();
            $table->json('json___products__monthly_sales_estimate')->nullable();

            // From amazon_products - Averages
            $table->float('avg___products__price')->nullable()->index();
            $table->float('avg___products__customer_rating')->nullable()->index();
            $table->float('avg___products__customer_reviews_count')->nullable()->index();
            $table->float('avg___products__bsr_rank')->nullable()->index();
            $table->date('avg___products__normalized_date')->nullable()->index();
            $table->float('avg___products__page_count')->nullable()->index();
            $table->float('avg___products__kdp_royalty_estimate')->nullable()->index();
            $table->float('avg___products__monthly_sales_estimate')->nullable()->index();

            // From amazon_products - Counts
            $table->integer('cnt___products__is_available')->nullable()->index();
            $table->integer('cnt___products__is_amazon_choice')->nullable()->index();
            $table->integer('cnt___products__is_independently_published')->nullable()->index();

            // Standard deviation in BSR rank
            $table->float('stdev___products__bsr_rank')->nullable()->index();

            // Computed scores
            $table->float('score_1')->nullable()->index();
            $table->float('score_2')->nullable()->index();
            $table->float('score_3')->nullable()->index();
            $table->float('score_4')->nullable()->index();
            $table->float('score_5')->nullable()->index();
            $table->float('score_6')->nullable()->index();
            $table->float('score_7')->nullable()->index();
            $table->float('score_8')->nullable()->index();
            $table->float('score_9')->nullable()->index();
            $table->float('score_10')->nullable()->index();

            // Standard Laravel timestamps
            $table->timestamps();

            // Custom processing fields
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();

            // Composite unique index
            $table->unique(
                ['keyword', 'location_code', 'language_code', 'device'],
                'idx_aks_keyword_location_language_device'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('amazon_keywords_stats');
    }
};
