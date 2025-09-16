<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::create('fiverr_listings', function (Blueprint $table) {
            $table->id();

            // Category IDs
            $table->string('categoryIds__categoryId')->nullable();
            $table->string('categoryIds__subCategoryId')->nullable();
            $table->string('categoryIds__nestedSubCategoryId')->nullable();

            // Listing data
            $table->string('listingQuery')->nullable();
            $table->text('activeFilters')->nullable();

            // Currency
            $table->string('currency__name')->nullable();
            $table->float('currency__rate')->nullable();
            $table->string('currency__symbol')->nullable();

            // Listing attributes
            $table->string('listingAttributes__id');
            $table->string('listingAttributes__sortBy')->nullable();
            $table->integer('listingAttributes__offset')->nullable();
            $table->string('listingAttributes__platform')->nullable();

            // Raw listing data
            $table->boolean('rawListingData__has_more')->nullable();

            // Location and language
            $table->string('countryCode')->nullable();
            $table->string('assumedLanguage')->nullable();

            // Display data
            $table->string('displayData__categoryName')->nullable();
            $table->string('displayData__subCategoryName')->nullable();
            $table->string('displayData__nestedSubCategoryName')->nullable();
            $table->string('displayData__cachedSlug')->nullable();
            $table->string('displayData__name')->nullable();

            // V2 report data
            $table->integer('v2__report__search_total_results')->nullable();
            $table->boolean('v2__report__sort_pro_first')->nullable();
            $table->integer('v2__report__category_id')->nullable();
            $table->integer('v2__report__sub_category_id')->nullable();
            $table->string('v2__report__nested_sub_category_id')->nullable();

            // App data pagination
            $table->integer('appData__pagination__page')->nullable();
            $table->integer('appData__pagination__page_size')->nullable();
            $table->integer('appData__pagination__total')->nullable();

            // Main data
            $table->mediumText('listings')->nullable();

            // Facets
            $table->text('facets__file_format')->nullable();
            $table->text('facets__has_hourly')->nullable();
            $table->text('facets__is_agency')->nullable();
            $table->text('facets__is_pa_online')->nullable();
            $table->text('facets__is_seller_online')->nullable();
            $table->text('facets__leaf_categories')->nullable();
            $table->text('facets__package_includes')->nullable();
            $table->text('facets__pro')->nullable();
            $table->text('facets__seller_language')->nullable();
            $table->text('facets__seller_level')->nullable();
            $table->text('facets__seller_location')->nullable();
            $table->text('facets__style')->nullable();
            $table->text('facets__sub_categories')->nullable();
            $table->text('facets__service_offerings')->nullable();
            $table->text('facets__nested_sub_categories')->nullable();

            // Pricing
            $table->text('priceBucketsSkeleton')->nullable();
            $table->text('pricingFactorSkeleton')->nullable();

            // Tracking
            $table->boolean('tracking__isNonExperiential')->nullable();
            $table->integer('tracking__fiverrChoiceGigPosition')->nullable();
            $table->boolean('tracking__hasFiverrChoiceGigs')->nullable();
            $table->boolean('tracking__hasPromotedGigs')->nullable();
            $table->integer('tracking__promotedGigsCount')->nullable();
            $table->boolean('tracking__searchAutoComplete__is_autocomplete')->nullable();

            // Other
            $table->text('breadcrumbs')->nullable();

            // Standard Laravel timestamps
            $table->timestamps();

            // Custom processing fields
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();
            $table->timestamp('stats_processed_at')->nullable();
            $table->text('stats_processed_status')->nullable();
            $table->timestamp('gigs_stats_processed_at')->nullable();
            $table->text('gigs_stats_processed_status')->nullable();

            // Unique index on listingAttributes__id
            $table->unique('listingAttributes__id');

            // Indexes
            $table->index('v2__report__search_total_results');
            $table->index('tracking__hasFiverrChoiceGigs');
            $table->index('tracking__hasPromotedGigs');
            $table->index('tracking__promotedGigsCount');
            $table->index('processed_at');
            $table->index('stats_processed_at');
            $table->index('gigs_stats_processed_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fiverr_listings');
    }
};
