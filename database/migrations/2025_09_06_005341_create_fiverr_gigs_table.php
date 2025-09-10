<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::create('fiverr_gigs', function (Blueprint $table) {
            $table->id();

            // General fields
            $table->integer('general__gigId')->unique();
            $table->string('general__gigStatus')->nullable();
            $table->integer('general__categoryId')->nullable();
            $table->string('general__categoryName')->nullable();
            $table->string('general__categorySlug')->nullable();
            $table->integer('general__subCategoryId')->nullable();
            $table->string('general__subCategoryName')->nullable();
            $table->string('general__subCategorySlug')->nullable();
            $table->string('general__nestedSubCategoryId')->nullable();
            $table->string('general__nestedSubCategorySlug')->nullable();
            $table->boolean('general__isOnVacation')->nullable();
            $table->boolean('general__isBuyerBlocked')->nullable();
            $table->boolean('general__isPro')->nullable();
            $table->boolean('general__isHandpicked')->nullable();
            $table->boolean('general__isStudio')->nullable();
            $table->string('general__gigTitle')->nullable();
            $table->string('general__encryptedGigId')->nullable();
            $table->integer('general__sellerId')->nullable();
            $table->boolean('general__traffiqed')->nullable();
            $table->boolean('general__isSellerBlocked')->nullable();
            $table->boolean('general__gigVisibleToSeller')->nullable();
            $table->boolean('general__gigVisibleToBuyer')->nullable();
            $table->boolean('general__includeWorkSample')->nullable();

            // Out of office fields
            $table->string('outOfOffice__username')->nullable();
            $table->integer('outOfOffice__sellerId')->nullable();
            $table->boolean('outOfOffice__isOnVacation')->nullable();
            $table->string('outOfOffice__endDate')->nullable();
            $table->text('outOfOffice__profilePhoto')->nullable();
            $table->boolean('outOfOffice__allowContact')->nullable();
            $table->string('outOfOffice__awayReason')->nullable();
            $table->string('outOfOffice__awayMessage')->nullable();
            $table->integer('outOfOffice__gigId')->nullable();

            // Seller card fields
            $table->string('sellerCard__oneLiner')->nullable();
            $table->float('sellerCard__rating')->nullable();
            $table->integer('sellerCard__achievement')->nullable();
            $table->integer('sellerCard__ratingsCount')->nullable();
            $table->string('sellerCard__countryCode')->nullable();
            $table->text('sellerCard__proficientLanguages')->nullable();
            $table->integer('sellerCard__memberSince')->nullable();
            $table->integer('sellerCard__responseTime')->nullable();
            $table->bigInteger('sellerCard__recentDelivery')->nullable();
            $table->text('sellerCard__description')->nullable();
            $table->boolean('sellerCard__isPro')->nullable();
            $table->text('sellerCard__proSubCategories')->nullable();
            $table->boolean('sellerCard__hasProfilePhoto')->nullable();
            $table->text('sellerCard__profilePhoto')->nullable();

            // Description, gallery, FAQ, packages, tags
            $table->text('description__content')->nullable();
            $table->mediumText('gallery__slides')->nullable();
            $table->text('faq__questionsAndAnswers')->nullable();
            $table->text('packages__packageList')->nullable();
            $table->text('packages__recurringOptions')->nullable();
            $table->text('tags__tagsGigList')->nullable();

            // Overview fields
            $table->integer('overview__gig__id')->nullable();
            $table->string('overview__gig__title')->nullable();
            $table->float('overview__gig__rating')->nullable();
            $table->integer('overview__gig__ratingsCount')->nullable();
            $table->boolean('overview__gig__isRestrictedByRegion')->nullable();
            $table->integer('overview__gig__ordersInQueue')->nullable();
            $table->integer('overview__seller__id')->nullable();
            $table->string('overview__seller__username')->nullable();
            $table->integer('overview__seller__achievement')->nullable();
            $table->boolean('overview__seller__isPro')->nullable();
            $table->boolean('overview__seller__hasProfilePhoto')->nullable();
            $table->text('overview__seller__profilePhoto')->nullable();
            $table->string('overview__seller__countryCode')->nullable();
            $table->text('overview__seller__proficientLanguages')->nullable();
            $table->string('overview__categories__category__name')->nullable();
            $table->string('overview__categories__category__slug')->nullable();
            $table->string('overview__categories__subCategory__name')->nullable();
            $table->string('overview__categories__subCategory__slug')->nullable();
            $table->string('overview__categories__nestedSubCategory__name')->nullable();
            $table->string('overview__categories__nestedSubCategory__slug')->nullable();

            // Pro fields
            $table->boolean('pro__isPro')->nullable();

            // Top nav fields
            $table->integer('topNav__gigId')->nullable();
            $table->string('topNav__gigTitle')->nullable();
            $table->integer('topNav__gigCollectedCount')->nullable();
            $table->integer('topNav__sellerId')->nullable();
            $table->string('topNav__sellerName')->nullable();

            // Portfolio fields
            $table->string('portfolio__slug')->nullable();
            $table->string('portfolio__username')->nullable();
            $table->integer('portfolio__projectsCount')->nullable();
            $table->text('portfolio__nextProjectIds')->nullable();
            $table->text('portfolio__portfolioProjects')->nullable();
            $table->text('portfolio__portfolioProjectsThumbs')->nullable();

            // SEO fields
            $table->string('seo__title__gigTitle')->nullable();
            $table->string('seo__title__sellerUsername')->nullable();
            $table->string('seo__description__sellerAchievement')->nullable();
            $table->string('seo__description__subCategoryName')->nullable();
            $table->string('seo__description__gigTitle')->nullable();
            $table->string('seo__description__serviceInclude')->nullable();
            $table->string('seo__description__deliveryTime')->nullable();
            $table->string('seo__schemaMarkup__type')->nullable();
            $table->string('seo__schemaMarkup__gigTitle')->nullable();
            $table->text('seo__schemaMarkup__gigImage')->nullable();
            $table->string('seo__schemaMarkup__gigOffers__type')->nullable();
            $table->string('seo__schemaMarkup__gigOffers__lowPrice')->nullable();
            $table->string('seo__schemaMarkup__gigOffers__highPrice')->nullable();
            $table->string('seo__schemaMarkup__gigOffers__currency')->nullable();
            $table->string('seo__schemaMarkup__gigOffers__availability')->nullable();

            // Custom order fields
            $table->string('customOrder__seller__status')->nullable();
            $table->boolean('customOrder__seller__isOnVacation')->nullable();
            $table->string('customOrder__gig__status')->nullable();
            $table->integer('customOrder__gig__categoryId')->nullable();
            $table->integer('customOrder__gig__subCategoryId')->nullable();
            $table->string('customOrder__currentUserStatus')->nullable();
            $table->boolean('customOrder__allowCustomOrders')->nullable();
            $table->integer('customOrder__responseTime')->nullable();

            // More services fields
            $table->string('moreServices__subCategoryName')->nullable();
            $table->text('moreServices__otherNestedSubCategoryGigs')->nullable();

            // Other gigs and open graph fields
            $table->text('otherGigs__gigIds')->nullable();
            $table->text('openGraph__description')->nullable();
            $table->string('openGraph__title')->nullable();
            $table->integer('openGraph__price')->nullable();
            $table->text('openGraph__image')->nullable();
            $table->string('openGraph__video')->nullable();

            // Additional fields
            $table->text('choiceEligibilities')->nullable();
            $table->text('repeatScore')->nullable();
            $table->text('workflow')->nullable();
            $table->text('workProcess')->nullable();
            $table->text('instantOrderSettings')->nullable();
            $table->text('promotedGigs')->nullable();
            $table->text('highlightsData')->nullable();

            // Reviews fields
            $table->boolean('reviews__has_next')->nullable();
            $table->integer('reviews__rating_algo_type')->nullable();
            $table->integer('reviews__gig_id')->nullable();
            $table->float('reviews__average_valuation')->nullable();
            $table->integer('reviews__total_count')->nullable();
            $table->text('reviews__reviews')->nullable();
            $table->text('reviews__reviews_snippet')->nullable();
            $table->float('reviews__star_summary__communication_valuation')->nullable();
            $table->float('reviews__star_summary__quality_of_delivery_valuation')->nullable();
            $table->float('reviews__star_summary__value_for_money_valuation')->nullable();
            $table->text('reviews__breakdown')->nullable();
            $table->string('reviews__seller_username')->nullable();
            $table->text('reviews__seller_img_url')->nullable();
            $table->text('reviews__filters_counters')->nullable();
            $table->text('reviews__dynamicTranslations')->nullable();

            // Notable clients and other fields
            $table->text('notableClients')->nullable();
            $table->boolean('isTrustedFreelancer')->nullable();
            $table->text('gigTranslationsCacheFlagsValues')->nullable();

            // Floating chat data fields
            $table->boolean('floatingChatData__isEligible')->nullable();
            $table->boolean('floatingChatData__showConversationSavedTooltip')->nullable();

            // Seller fields
            $table->string('seller__user__name')->nullable();
            $table->string('seller__user__profile__displayName')->nullable();
            $table->string('seller__user__id')->nullable();
            $table->text('seller__user__languages')->nullable();
            $table->string('seller__user__fullName')->nullable();
            $table->string('seller__user__address__countryCode')->nullable();
            $table->string('seller__user__profileImage__previewUrl__transformation')->nullable();
            $table->text('seller__user__profileImage__previewUrl__url')->nullable();
            $table->integer('seller__user__joinedAt')->nullable();
            $table->string('seller__sellerLevel')->nullable();
            $table->text('seller__description')->nullable();
            $table->boolean('seller__isPro')->nullable();
            $table->string('seller__oneLinerTitle')->nullable();
            $table->text('seller__proSubCategories')->nullable();
            $table->integer('seller__rating__count')->nullable();
            $table->float('seller__rating__score')->nullable();
            $table->integer('seller__responseTime__inHours')->nullable();
            $table->string('seller__id')->nullable();
            $table->boolean('seller__isHighlyResponsive')->nullable();
            $table->text('seller__introVideo__url')->nullable();
            $table->boolean('seller__isVerified')->nullable();
            $table->text('seller__activeStructuredSkills')->nullable();
            $table->integer('seller__completedOrdersCount')->nullable();
            $table->text('seller__courses__nodes')->nullable();
            $table->text('seller__certifications')->nullable();
            $table->text('seller__activeEducations')->nullable();
            $table->boolean('seller__isOnVacation')->nullable();
            $table->string('seller__vacation')->nullable();
            $table->integer('seller__portfolios__totalCount')->nullable();
            $table->text('seller__portfolios__portfolioProjects')->nullable();
            $table->string('seller__agency')->nullable();
            $table->string('seller__hourlyRate')->nullable();
            $table->text('seller__regionalGroupRestrictions')->nullable();
            $table->boolean('seller__hasLoyaltyScores')->nullable();
            $table->boolean('seller__isActive')->nullable();

            // Translation and other fields
            $table->boolean('gigSellerTranslation__isEnabled')->nullable();
            $table->text('centralizedGig__status')->nullable();
            $table->text('consultationData')->nullable();
            $table->boolean('sellerHourlyEligibility__sellerHourlyEligible')->nullable();
            $table->text('regionCode')->nullable();
            $table->text('source')->nullable();

            // Currency fields
            $table->text('currency__name')->nullable();
            $table->float('currency__rate')->nullable();
            $table->text('currency__template')->nullable();
            $table->boolean('currency__forceRound')->nullable();
            $table->integer('currency__forceRoundFromAmount')->nullable();
            $table->text('currency__symbol')->nullable();

            // More fields
            $table->text('gigTheme')->nullable();
            $table->boolean('newProBanner__wasClosed')->nullable();

            // Standard Laravel timestamps
            $table->timestamps();

            // Custom processing fields
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Indexes
            $table->index('general__isOnVacation');
            $table->index('general__isPro');
            $table->index('general__isHandpicked');
            $table->index('general__isStudio');
            $table->index('sellerCard__ratingsCount');
            $table->index('sellerCard__responseTime');
            $table->index('sellerCard__recentDelivery');
            $table->index('overview__gig__rating');
            $table->index('overview__gig__ratingsCount');
            $table->index('overview__gig__ordersInQueue');
            $table->index('topNav__gigCollectedCount');
            $table->index('portfolio__projectsCount');
            $table->index('seo__description__deliveryTime');
            $table->index('seo__schemaMarkup__gigOffers__lowPrice');
            $table->index('seo__schemaMarkup__gigOffers__highPrice');
            $table->index('openGraph__price');
            $table->index('reviews__average_valuation');
            $table->index('reviews__total_count');
            $table->index('processed_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fiverr_gigs');
    }
};
