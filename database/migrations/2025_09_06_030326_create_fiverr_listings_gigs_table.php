<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::create('fiverr_listings_gigs', function (Blueprint $table) {
            $table->id();

            // Core gig identification
            $table->integer('gigId');
            $table->string('listingAttributes__id')->nullable();
            $table->integer('pos')->nullable();
            $table->string('type')->nullable();
            $table->string('auction__id')->nullable();
            $table->boolean('is_fiverr_choice')->nullable();

            // Package information
            $table->integer('packages__recommended__id')->nullable();
            $table->boolean('packages__recommended__extra_fast')->nullable();
            $table->integer('packages__recommended__price')->nullable();
            $table->integer('packages__recommended__duration')->nullable();
            $table->integer('packages__recommended__price_tier')->nullable();
            $table->string('packages__recommended__type')->nullable();

            // Category information
            $table->integer('category_id')->nullable();
            $table->integer('sub_category_id')->nullable();
            $table->integer('nested_sub_category_id')->nullable();
            $table->string('displayData__categoryName')->nullable();
            $table->string('displayData__subCategoryName')->nullable();
            $table->string('displayData__nestedSubCategoryName')->nullable();
            $table->string('displayData__cachedSlug')->nullable();
            $table->string('displayData__name')->nullable();

            // Gig flags
            $table->boolean('is_pro')->nullable();
            $table->boolean('is_featured')->nullable();

            // Gig basic info
            $table->string('cached_slug')->nullable();
            $table->string('title')->nullable();

            // Seller information
            $table->string('seller_name')->nullable();
            $table->integer('seller_id')->nullable();
            $table->string('seller_country')->nullable();
            $table->string('seller_img')->nullable();
            $table->string('seller_display_name')->nullable();
            $table->boolean('seller_online')->nullable();
            $table->string('status')->nullable();
            $table->boolean('offer_consultation')->nullable();
            $table->text('seller_languages')->nullable();

            // Pricing and options
            $table->text('recurring_options')->nullable();
            $table->boolean('personalized_pricing_fail')->nullable();
            $table->boolean('has_recurring_option')->nullable();

            // Reviews and ratings
            $table->integer('buying_review_rating_count')->nullable();
            $table->float('buying_review_rating')->nullable();

            // URLs and seller details
            $table->string('seller_url')->nullable();
            $table->string('seller_level')->nullable();
            $table->integer('seller_rating__count')->nullable();
            $table->float('seller_rating__score')->nullable();
            $table->string('gig_url')->nullable();

            // Additional flags and pricing
            $table->boolean('is_seller_unavailable')->nullable();
            $table->integer('price_i')->nullable();
            $table->integer('package_i')->nullable();
            $table->boolean('extra_fast')->nullable();
            $table->integer('num_of_packages')->nullable();
            $table->string('u_id')->nullable();

            // Standard Laravel timestamps
            $table->timestamps();

            // Custom processing fields
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Unique index on listingAttributes__id and gigId
            $table->unique(['listingAttributes__id', 'gigId']);

            // Indexes
            $table->index('gigId');
            $table->index('pos');
            $table->index('type');
            $table->index('is_fiverr_choice');
            $table->index('packages__recommended__extra_fast');
            $table->index('packages__recommended__price');
            $table->index('packages__recommended__duration');
            $table->index('packages__recommended__price_tier');
            $table->index('packages__recommended__type');
            $table->index('is_pro');
            $table->index('is_featured');
            $table->index('seller_online');
            $table->index('offer_consultation');
            $table->index('has_recurring_option');
            $table->index('buying_review_rating_count');
            $table->index('buying_review_rating');
            $table->index('seller_level');
            $table->index('seller_rating__count');
            $table->index('seller_rating__score');
            $table->index('is_seller_unavailable');
            $table->index('price_i');
            $table->index('extra_fast');
            $table->index('num_of_packages');
            $table->index('processed_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fiverr_listings_gigs');
    }
};
