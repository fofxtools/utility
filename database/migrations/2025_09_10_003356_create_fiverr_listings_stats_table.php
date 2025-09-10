<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::create('fiverr_listings_stats', function (Blueprint $table) {
            $table->id();

            // Link to fiverr_listings row (unique, not nullable)
            $table->unsignedBigInteger('fiverr_listings_row_id');

            $table->string('listingAttributes__id')->nullable();

            $table->float('currency__rate')->nullable();

            $table->boolean('rawListingData__has_more')->nullable();

            $table->string('countryCode')->nullable();
            $table->string('assumedLanguage')->nullable();

            $table->integer('v2__report__search_total_results')->nullable();
            $table->integer('appData__pagination__page')->nullable();
            $table->integer('appData__pagination__page_size')->nullable();
            $table->integer('appData__pagination__total')->nullable();

            // Counts of possible values for listings.gigs.type
            // "missing" is either empty string, or null
            // "other" is any other type not accounted for
            $table->integer('cnt___listings__gigs__type___promoted_gigs')->nullable();
            $table->integer('cnt___listings__gigs__type___fiverr_choice')->nullable();
            $table->integer('cnt___listings__gigs__type___fixed_pricing')->nullable();
            $table->integer('cnt___listings__gigs__type___pro')->nullable();
            $table->integer('cnt___listings__gigs__type___missing')->nullable();
            $table->integer('cnt___listings__gigs__type___other')->nullable();

            // Counts and averages for other listings.gigs fields
            $table->integer('cnt___listings__gigs__is_fiverr_choice')->nullable();
            $table->integer('cnt___listings__gigs__packages__recommended__extra_fast')->nullable();
            $table->float('avg___listings__gigs__packages__recommended__price')->nullable();
            $table->float('avg___listings__gigs__packages__recommended__duration')->nullable();
            $table->float('avg___listings__gigs__packages__recommended__price_tier')->nullable();
            $table->integer('cnt___listings__gigs__is_pro')->nullable();
            $table->integer('cnt___listings__gigs__is_featured')->nullable();
            $table->integer('cnt___listings__gigs__seller_online')->nullable();
            $table->integer('cnt___listings__gigs__offer_consultation')->nullable();
            $table->integer('cnt___listings__gigs__personalized_pricing_fail')->nullable();
            $table->integer('cnt___listings__gigs__has_recurring_option')->nullable();
            $table->float('avg___listings__gigs__buying_review_rating_count')->nullable();
            $table->float('avg___listings__gigs__buying_review_rating')->nullable();
            $table->float('avg___listings__gigs__seller_rating__count')->nullable();
            $table->float('avg___listings__gigs__seller_rating__score')->nullable();
            $table->integer('cnt___listings__gigs__is_seller_unavailable')->nullable();
            $table->float('avg___listings__gigs__price_i')->nullable();
            $table->float('avg___listings__gigs__package_i')->nullable();
            $table->integer('cnt___listings__gigs__extra_fast')->nullable();
            $table->float('avg___listings__gigs__num_of_packages')->nullable();

            // Process string listings.gigs.seller_level and count each level
            // Note: Not the same as facets.seller_level. That applies to all listings pages.
            // listings.gigs is for the single page only.
            $table->integer('cnt___listings__gigs__seller_level___na')->nullable();
            $table->integer('cnt___listings__gigs__seller_level___level_one_seller')->nullable();
            $table->integer('cnt___listings__gigs__seller_level___level_two_seller')->nullable();
            $table->integer('cnt___listings__gigs__seller_level___top_rated_seller')->nullable();

            // Weighted average of the seller levels (first convert to 0, 1, 2, 3)
            $table->float('avg___listings__gigs__seller_level')->nullable();

            // JSON path: $[?(@.id=='true')].count
            $table->integer('facets__has_hourly___true___count')->nullable();
            $table->integer('facets__is_agency___true___count')->nullable();
            $table->integer('facets__is_pa_online___true___count')->nullable();
            $table->integer('facets__is_seller_online___true___count')->nullable();
            $table->integer('facets__pro___true___count')->nullable();

            // JSON path: $[?(@.id=='en')].count
            $table->integer('facets__seller_language___en___count')->nullable();

            // JSON path: $[?(@.id=='X')].count
            $table->integer('facets__seller_level___na___count')->nullable();
            $table->integer('facets__seller_level___level_one_seller___count')->nullable();
            $table->integer('facets__seller_level___level_two_seller___count')->nullable();
            $table->integer('facets__seller_level___top_rated_seller___count')->nullable();

            // Weighted average of the facets seller levels (first convert to 0, 1, 2, 3)
            $table->float('avg___facets___seller_level')->nullable();

            // JSON path: $[?(@.id=='US')].count
            $table->integer('facets__seller_location___us___count')->nullable();

            // JSON path: $[?(@.id=='X')].count
            $table->integer('facets__service_offerings__offer_consultation___count')->nullable();
            $table->integer('facets__service_offerings__subscription___count')->nullable();

            // JSON path: $[?(@.id=='X')].max
            $table->integer('priceBucketsSkeleton___0___max')->nullable();
            $table->integer('priceBucketsSkeleton___1___max')->nullable();
            $table->integer('priceBucketsSkeleton___2___max')->nullable();

            $table->boolean('tracking__isNonExperiential')->nullable();
            $table->integer('tracking__fiverrChoiceGigPosition')->nullable();
            $table->boolean('tracking__hasFiverrChoiceGigs')->nullable();
            $table->boolean('tracking__hasPromotedGigs')->nullable();
            $table->integer('tracking__promotedGigsCount')->nullable();
            $table->boolean('tracking__searchAutoComplete__is_autocomplete')->nullable();

            // Standard Laravel timestamps
            $table->timestamps();

            // Custom processing fields
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Unique index on fiverr_listings_row_id
            $table->unique('fiverr_listings_row_id', 'idx_fls_fiverr_listings_row_id');

            // Indexes (named using actual column name prefixed with 'idx_fls_')
            $table->index('v2__report__search_total_results', 'idx_fls_v2__report__search_total_results');
            $table->index('appData__pagination__total', 'idx_fls_appData__pagination__total');

            $table->index('cnt___listings__gigs__type___promoted_gigs', 'idx_fls_cnt___listings__gigs__type___promoted_gigs');
            $table->index('cnt___listings__gigs__type___fiverr_choice', 'idx_fls_cnt___listings__gigs__type___fiverr_choice');
            $table->index('cnt___listings__gigs__type___pro', 'idx_fls_cnt___listings__gigs__type___pro');
            $table->index('cnt___listings__gigs__is_fiverr_choice', 'idx_fls_cnt___listings__gigs__is_fiverr_choice');
            $table->index('avg___listings__gigs__packages__recommended__price', 'idx_fls_avg___listings__gigs__packages__recommended__price');
            $table->index('avg___listings__gigs__packages__recommended__duration', 'idx_fls_avg___listings__gigs__packages__recommended__duration');
            $table->index('cnt___listings__gigs__is_pro', 'idx_fls_cnt___listings__gigs__is_pro');
            $table->index('cnt___listings__gigs__is_featured', 'idx_fls_cnt___listings__gigs__is_featured');
            $table->index('avg___listings__gigs__buying_review_rating_count', 'idx_fls_avg___listings__gigs__buying_review_rating_count');
            $table->index('avg___listings__gigs__buying_review_rating', 'idx_fls_avg___listings__gigs__buying_review_rating');
            $table->index('avg___listings__gigs__seller_rating__count', 'idx_fls_avg___listings__gigs__seller_rating__count');
            $table->index('avg___listings__gigs__seller_rating__score', 'idx_fls_avg___listings__gigs__seller_rating__score');
            $table->index('avg___listings__gigs__price_i', 'idx_fls_avg___listings__gigs__price_i');
            $table->index('cnt___listings__gigs__seller_level___na', 'idx_fls_cnt___listings__gigs__seller_level___na');
            $table->index('cnt___listings__gigs__seller_level___level_one_seller', 'idx_fls_cnt___listings__gigs__seller_level___level_one_seller');
            $table->index('cnt___listings__gigs__seller_level___level_two_seller', 'idx_fls_cnt___listings__gigs__seller_level___level_two_seller');
            $table->index('cnt___listings__gigs__seller_level___top_rated_seller', 'idx_fls_cnt___listings__gigs__seller_level___top_rated_seller');
            $table->index('avg___listings__gigs__seller_level', 'idx_fls_avg___listings__gigs__seller_level');

            $table->index('facets__is_agency___true___count', 'idx_fls_facets__is_agency___true___count');
            $table->index('facets__pro___true___count', 'idx_fls_facets__pro___true___count');
            $table->index('facets__seller_level___na___count', 'idx_fls_facets__seller_level___na___count');
            $table->index('facets__seller_level___level_one_seller___count', 'idx_fls_facets__seller_level___level_one_seller___count');
            $table->index('facets__seller_level___level_two_seller___count', 'idx_fls_facets__seller_level___level_two_seller___count');
            $table->index('facets__seller_level___top_rated_seller___count', 'idx_fls_facets__seller_level___top_rated_seller___count');
            $table->index('avg___facets___seller_level', 'idx_fls_avg___facets___seller_level');

            $table->index('priceBucketsSkeleton___0___max', 'idx_fls_priceBucketsSkeleton___0___max');
            $table->index('priceBucketsSkeleton___1___max', 'idx_fls_priceBucketsSkeleton___1___max');
            $table->index('priceBucketsSkeleton___2___max', 'idx_fls_priceBucketsSkeleton___2___max');

            $table->index('tracking__promotedGigsCount', 'idx_fls_tracking__promotedGigsCount');

            $table->index('processed_at', 'idx_fls_processed_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fiverr_listings_stats');
    }
};
