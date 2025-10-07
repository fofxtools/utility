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

            // URL
            $table->string('url')->nullable();

            $table->string('listingAttributes__id')->nullable();

            // Category information
            $table->string('categoryIds__categoryId')->nullable();
            $table->string('categoryIds__subCategoryId')->nullable();
            $table->string('categoryIds__nestedSubCategoryId')->nullable();
            $table->string('displayData__categoryName')->nullable();
            $table->string('displayData__subCategoryName')->nullable();
            $table->string('displayData__nestedSubCategoryName')->nullable();
            $table->string('displayData__cachedSlug')->nullable();
            $table->string('displayData__name')->nullable();

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

            // Process string listings.gigs.seller_level and count each level
            // Note: Not the same as facets.seller_level. That applies to all listings pages.
            // listings.gigs is for the single page only.
            $table->integer('cnt___listings__gigs__seller_level___na')->nullable();
            $table->integer('cnt___listings__gigs__seller_level___level_one_seller')->nullable();
            $table->integer('cnt___listings__gigs__seller_level___level_two_seller')->nullable();
            $table->integer('cnt___listings__gigs__seller_level___top_rated_seller')->nullable();

            // Weighted average of the seller levels (first convert to 0, 1, 2, 3)
            $table->float('avg___listings__gigs__seller_level')->nullable();

            // Remaining listings fields
            $table->float('avg___listings__gigs__seller_rating__count')->nullable();
            $table->float('avg___listings__gigs__seller_rating__score')->nullable();
            $table->integer('cnt___listings__gigs__is_seller_unavailable')->nullable();
            $table->float('avg___listings__gigs__price_i')->nullable();
            $table->float('avg___listings__gigs__package_i')->nullable();
            $table->integer('cnt___listings__gigs__extra_fast')->nullable();
            $table->float('avg___listings__gigs__num_of_packages')->nullable();

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

            // JSON of gigs
            $table->json('json___sellerCard__memberSince')->nullable();
            $table->json('json___sellerCard__responseTime')->nullable();
            $table->json('json___sellerCard__recentDelivery')->nullable();
            $table->json('json___overview__gig__rating')->nullable();
            $table->json('json___overview__gig__ratingsCount')->nullable();
            $table->json('json___overview__gig__ordersInQueue')->nullable();
            $table->json('json___topNav__gigCollectedCount')->nullable();
            $table->json('json___portfolio__projectsCount')->nullable();
            $table->json('json___seo__description__deliveryTime')->nullable();
            $table->json('json___seo__schemaMarkup__gigOffers__lowPrice')->nullable();
            $table->json('json___seo__schemaMarkup__gigOffers__highPrice')->nullable();
            $table->json('json___seller__user__joinedAt')->nullable();
            $table->json('json___seller__sellerLevel')->nullable();
            $table->json('json___seller__sellerLevel___adjusted')->nullable();
            $table->json('json___seller__isPro')->nullable();
            $table->json('json___seller__rating__count')->nullable();
            $table->json('json___seller__rating__score')->nullable();
            $table->json('json___seller__responseTime__inHours')->nullable();
            $table->json('json___seller__completedOrdersCount')->nullable();

            // Averages of gigs
            $table->float('avg___sellerCard__memberSince')->nullable();
            $table->float('avg___sellerCard__responseTime')->nullable();
            $table->float('avg___sellerCard__recentDelivery')->nullable();
            $table->float('avg___overview__gig__rating')->nullable();
            $table->float('avg___overview__gig__ratingsCount')->nullable();
            $table->float('avg___overview__gig__ordersInQueue')->nullable();
            $table->float('avg___topNav__gigCollectedCount')->nullable();
            $table->float('avg___portfolio__projectsCount')->nullable();
            $table->float('avg___seo__description__deliveryTime')->nullable();
            $table->float('avg___seo__schemaMarkup__gigOffers__lowPrice')->nullable();
            $table->float('avg___seo__schemaMarkup__gigOffers__highPrice')->nullable();
            $table->float('avg___seller__user__joinedAt')->nullable();
            $table->float('avg___seller__sellerLevel')->nullable();
            $table->float('avg___seller__sellerLevel___adjusted')->nullable();
            $table->float('avg___seller__isPro')->nullable();
            $table->float('avg___seller__rating__count')->nullable();
            $table->float('avg___seller__rating__score')->nullable();
            $table->float('avg___seller__responseTime__inHours')->nullable();
            $table->float('avg___seller__completedOrdersCount')->nullable();

            // Computed scores
            $table->float('score_1')->nullable();
            $table->float('score_2')->nullable();
            $table->float('score_3')->nullable();
            $table->float('score_4')->nullable();
            $table->float('score_5')->nullable();
            $table->float('score_6')->nullable();
            $table->float('score_7')->nullable();
            $table->float('score_8')->nullable();
            $table->float('score_9')->nullable();
            $table->float('score_10')->nullable();

            // Standard Laravel timestamps
            $table->timestamps();

            // Custom processing fields
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Unique index on fiverr_listings_row_id
            $table->unique('fiverr_listings_row_id', 'idx_fls_fiverr_listings_row_id');

            // Indexes (named using actual column name prefixed with 'idx_fls_')
            $table->index('url', 'idx_fls_url');
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

            $table->index('avg___sellerCard__memberSince', 'idx_fls_avg___sellerCard__memberSince');
            $table->index('avg___sellerCard__responseTime', 'idx_fls_avg___sellerCard__responseTime');
            $table->index('avg___sellerCard__recentDelivery', 'idx_fls_avg___sellerCard__recentDelivery');
            $table->index('avg___overview__gig__rating', 'idx_fls_avg___overview__gig__rating');
            $table->index('avg___overview__gig__ratingsCount', 'idx_fls_avg___overview__gig__ratingsCount');
            $table->index('avg___overview__gig__ordersInQueue', 'idx_fls_avg___overview__gig__ordersInQueue');
            $table->index('avg___topNav__gigCollectedCount', 'idx_fls_avg___topNav__gigCollectedCount');
            $table->index('avg___portfolio__projectsCount', 'idx_fls_avg___portfolio__projectsCount');
            $table->index('avg___seo__description__deliveryTime', 'idx_fls_avg___seo__description__deliveryTime');
            $table->index('avg___seo__schemaMarkup__gigOffers__lowPrice', 'idx_fls_avg___seo__schemaMarkup__gigOffers__lowPrice');
            $table->index('avg___seo__schemaMarkup__gigOffers__highPrice', 'idx_fls_avg___seo__schemaMarkup__gigOffers__highPrice');
            $table->index('avg___seller__user__joinedAt', 'idx_fls_avg___seller__user__joinedAt');
            $table->index('avg___seller__sellerLevel', 'idx_fls_avg___seller__sellerLevel');
            $table->index('avg___seller__sellerLevel___adjusted', 'idx_fls_avg___seller__sellerLevel___adjusted');
            $table->index('avg___seller__isPro', 'idx_fls_avg___seller__isPro');
            $table->index('avg___seller__rating__count', 'idx_fls_avg___seller__rating__count');
            $table->index('avg___seller__rating__score', 'idx_fls_avg___seller__rating__score');
            $table->index('avg___seller__responseTime__inHours', 'idx_fls_avg___seller__responseTime__inHours');
            $table->index('avg___seller__completedOrdersCount', 'idx_fls_avg___seller__completedOrdersCount');

            $table->index('score_1', 'idx_fls_score_1');
            $table->index('score_2', 'idx_fls_score_2');
            $table->index('score_3', 'idx_fls_score_3');
            $table->index('score_4', 'idx_fls_score_4');
            $table->index('score_5', 'idx_fls_score_5');
            $table->index('score_6', 'idx_fls_score_6');
            $table->index('score_7', 'idx_fls_score_7');
            $table->index('score_8', 'idx_fls_score_8');
            $table->index('score_9', 'idx_fls_score_9');
            $table->index('score_10', 'idx_fls_score_10');

            $table->index('processed_at', 'idx_fls_processed_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fiverr_listings_stats');
    }
};
