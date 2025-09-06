<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::create('fiverr_seller_profiles', function (Blueprint $table) {
            $table->id();

            // User identification fields
            $table->string('seller__user__id')->unique();
            $table->string('seller__user__name')->nullable();
            $table->boolean('seller__user__isActivationCompleted')->nullable();
            $table->integer('seller__user__joinedAt')->nullable();
            $table->string('seller__user__profile__displayName')->nullable();
            $table->string('seller__user__address__countryName')->nullable();
            $table->string('seller__user__address__countryCode')->nullable();
            $table->text('seller__user__languages')->nullable();
            $table->string('seller__user__localization__timezone')->nullable();
            $table->string('seller__user__profileImageUrl')->nullable();

            // Seller profile fields
            $table->string('seller__isSelfIdentifiedAsAgency')->nullable();
            $table->text('seller__regionalGroupRestrictions')->nullable();
            $table->integer('seller__hourlyRate__priceInCents')->nullable();
            $table->integer('seller__approvedGigsCount')->nullable();
            $table->text('seller__gigs__nodes')->nullable();
            $table->text('seller__notableClients')->nullable();
            $table->boolean('seller__isActive')->nullable();
            $table->string('seller__introVideo__url')->nullable();
            $table->string('seller__oneLinerTitle')->nullable();
            $table->text('seller__description')->nullable();
            $table->boolean('seller__isVerified')->nullable();
            $table->boolean('seller__isHighlyResponsive')->nullable();
            $table->string('seller__profileBackgroundImage')->nullable();

            // Professional credentials
            $table->text('seller__certifications')->nullable();
            $table->text('seller__activeEducations')->nullable();
            $table->text('seller__activeStructuredSkills')->nullable();
            $table->text('seller__gigCategories')->nullable();
            $table->boolean('seller__isPro')->nullable();
            $table->text('seller__proSubCategories')->nullable();

            // Portfolio and work experience
            $table->text('seller__portfolios__nodes')->nullable();
            $table->integer('seller__portfolios__totalCount')->nullable();
            $table->text('seller__workExperiences__nodes')->nullable();
            $table->text('seller__onlinePresences')->nullable();
            $table->text('seller__professionalPresences')->nullable();
            $table->text('seller__courses__nodes')->nullable();

            // Rating and performance
            $table->integer('seller__rating__score')->nullable();
            $table->integer('seller__rating__count')->nullable();
            $table->boolean('seller__isOnVacation')->nullable();
            $table->string('seller__vacation')->nullable();
            $table->string('seller__achievementLevel')->nullable();
            $table->string('seller__sellerLevel')->nullable();
            $table->integer('seller__responseTime__inHours')->nullable();
            $table->text('seller__testimonials')->nullable();
            $table->string('seller__agency')->nullable();

            // Currency fields
            $table->string('currency__name')->nullable();
            $table->integer('currency__rate')->nullable();
            $table->string('currency__template')->nullable();
            $table->boolean('currency__forceRound')->nullable();
            $table->integer('currency__forceRoundFromAmount')->nullable();
            $table->string('currency__symbol')->nullable();

            // Additional seller data
            $table->boolean('sellerHasProfessions')->nullable();

            // Buying reviews data
            $table->text('reviewsData__buying_reviews__reviews')->nullable();
            $table->boolean('reviewsData__buying_reviews__has_next')->nullable();
            $table->integer('reviewsData__buying_reviews__total_count')->nullable();
            $table->boolean('reviewsData__buying_reviews__reviews_as_seller')->nullable();
            $table->integer('reviewsData__buying_reviews__filters_counters__work_sample')->nullable();
            $table->integer('reviewsData__buying_reviews__filters_counters__business')->nullable();
            $table->integer('reviewsData__buying_reviews__user_id')->nullable();
            $table->string('reviewsData__buying_reviews__seller_username')->nullable();
            $table->text('reviewsData__buying_reviews__breakdown')->nullable();
            $table->integer('reviewsData__buying_reviews__average_valuation')->nullable();
            $table->text('reviewsData__buying_reviews__reviews_snippet')->nullable();

            // Selling reviews data
            $table->integer('reviewsData__selling_reviews__user_id')->nullable();
            $table->text('reviewsData__selling_reviews__reviews')->nullable();
            $table->string('reviewsData__selling_reviews__total_count')->nullable();
            $table->string('reviewsData__selling_reviews__average_valuation')->nullable();
            $table->boolean('reviewsData__selling_reviews__reviews_as_seller')->nullable();
            $table->boolean('reviewsData__selling_reviews__has_next')->nullable();

            // Additional data fields
            $table->text('consultationData')->nullable();
            $table->integer('localizationData__user_id')->nullable();
            $table->string('localizationData__currency')->nullable();
            $table->string('localizationData__locale')->nullable();
            $table->string('localizationData__timezone')->nullable();
            $table->integer('localizationData__recent_utc_offset')->nullable();
            $table->text('gigsData')->nullable();
            $table->text('ordersData')->nullable();
            $table->text('sellerRolesData')->nullable();
            $table->text('portfoliosData')->nullable();

            // Additional flags
            $table->boolean('isActivationCompleted')->nullable();
            $table->boolean('floatingChatData__isFloatingChatEnabled')->nullable();
            $table->boolean('floatingChatData__showConversationSavedTooltip')->nullable();
            $table->boolean('isSellerReferralBadge')->nullable();

            // Standard Laravel timestamps
            $table->timestamps();

            // Custom processing fields
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Indexes
            $table->index('seller__user__joinedAt');
            $table->index('seller__isSelfIdentifiedAsAgency');
            $table->index('seller__hourlyRate__priceInCents');
            $table->index('seller__approvedGigsCount');
            $table->index('seller__isActive');
            $table->index('seller__isPro');
            $table->index('seller__portfolios__totalCount');
            $table->index('seller__rating__score');
            $table->index('seller__rating__count');
            $table->index('seller__isOnVacation');
            $table->index('seller__sellerLevel');
            $table->index('seller__responseTime__inHours');
            $table->index('reviewsData__buying_reviews__total_count', 'idx_buying_reviews__total_count');
            $table->index('reviewsData__buying_reviews__reviews_as_seller', 'idx_buying_reviews__reviews_as_seller');
            $table->index('reviewsData__buying_reviews__filters_counters__work_sample', 'idx_buying_reviews__filters_counters__work_sample');
            $table->index('reviewsData__buying_reviews__average_valuation', 'idx_buying_reviews__average_valuation');
            $table->index('reviewsData__selling_reviews__total_count', 'idx_selling_reviews__total_count');
            $table->index('reviewsData__selling_reviews__average_valuation', 'idx_selling_reviews__average_valuation');
            $table->index('processed_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fiverr_seller_profiles');
    }
};
