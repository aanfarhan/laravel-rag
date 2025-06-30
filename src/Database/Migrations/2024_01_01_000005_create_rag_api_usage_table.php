<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        
        Schema::create($prefix . 'api_usage', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index(); // processing_api, openai, pinecone, etc
            $table->string('operation_type')->index(); // document_processing, embedding, vector_search
            $table->integer('tokens_used')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->foreignId('document_id')->nullable()->constrained($prefix . 'knowledge_documents')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['provider', 'created_at']);
            $table->index(['operation_type', 'created_at']);
        });
    }

    public function down()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        Schema::dropIfExists($prefix . 'api_usage');
    }
};