<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('amazon_products', function (Blueprint $table) {
            $table->id();
            $table->string('asin')->unique();
            $table->json('parsed_data')->nullable();
            $table->string('title')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('currency')->nullable()->index();
            $table->decimal('price', 10, 2)->nullable()->index();
            $table->boolean('is_available')->nullable()->index();
            $table->boolean('is_amazon_choice')->nullable()->index();
            $table->text('image_url')->nullable();
            $table->string('byline_info')->nullable()->index();
            $table->json('categories')->nullable();
            $table->string('publisher')->nullable()->index();
            $table->decimal('customer_rating', 2, 1)->nullable()->index();
            $table->unsignedInteger('customer_reviews_count')->nullable()->index();
            $table->unsignedBigInteger('bsr_rank')->nullable()->index();
            $table->string('bsr_category')->nullable()->index();
            $table->date('normalized_date')->nullable()->index();
            $table->unsignedInteger('page_count')->nullable()->index();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->json('processed_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_products');
    }
};
