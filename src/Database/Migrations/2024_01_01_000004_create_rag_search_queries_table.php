<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        
        Schema::create($prefix . 'search_queries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->text('query_text');
            $table->enum('search_type', ['vector', 'keyword', 'hybrid'])->default('hybrid')->index();
            $table->integer('results_count')->default(0);
            $table->integer('response_time_ms')->nullable();
            $table->json('vector_similarity_scores')->nullable();
            $table->timestamps();
            
            $table->index('created_at');
        });
    }

    public function down()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        Schema::dropIfExists($prefix . 'search_queries');
    }
};