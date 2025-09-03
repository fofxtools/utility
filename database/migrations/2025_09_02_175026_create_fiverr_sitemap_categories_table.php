<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fiverr_sitemap_categories', function (Blueprint $table) {
            $table->id();
            $table->string('url')->unique();
            $table->string('slug')->index()->nullable();
            $table->float('priority')->nullable();
            $table->string('alternate_href')->nullable();
            $table->integer('category_id')->nullable()->index();
            $table->integer('sub_category_id')->nullable()->index();
            $table->integer('nested_sub_category_id')->nullable()->index();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiverr_sitemap_categories');
    }
};
