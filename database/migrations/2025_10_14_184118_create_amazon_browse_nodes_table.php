<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('amazon_browse_nodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('browse_node_id');
            $table->string('name')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->text('path')->nullable();
            $table->integer('level')->nullable()->index();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->json('processed_status')->nullable();
            $table->timestamp('response_processed_at')->nullable()->index();
            $table->json('response_processed_status')->nullable();

            $table->unique('browse_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_browse_nodes');
    }
};
