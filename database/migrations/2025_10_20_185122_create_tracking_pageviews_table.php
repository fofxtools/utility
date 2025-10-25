<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tracking_pageviews', function (Blueprint $table) {
            $table->id();
            $table->string('view_id')->unique();
            $table->date('pageview_date')->index();
            $table->text('url');
            $table->string('domain')->index();
            $table->text('referrer')->nullable();
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('language')->nullable()->index();
            $table->string('timezone')->nullable()->index();
            $table->integer('viewport_width')->nullable();
            $table->integer('viewport_height')->nullable();
            $table->unsignedBigInteger('ts_pageview_ms')->nullable();
            $table->unsignedBigInteger('ts_metrics_ms')->nullable();
            $table->float('ttfb_ms')->nullable()->index();
            $table->float('dom_content_loaded_ms')->nullable()->index();
            $table->float('load_event_end_ms')->nullable()->index();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->json('processed_status')->nullable();

            // Composite indexes on pageview_date, domain
            $table->index(['pageview_date', 'domain']);
            $table->index(['domain', 'pageview_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracking_pageviews');
    }
};
