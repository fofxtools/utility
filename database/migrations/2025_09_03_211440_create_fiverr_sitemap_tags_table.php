<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fiverr_sitemap_tags', function (Blueprint $table) {
            $table->id();
            $table->string('url')->unique();
            $table->string('slug')->index()->nullable();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiverr_sitemap_tags');
    }
};
