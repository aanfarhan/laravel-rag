<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        
        Schema::create($prefix . 'knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('source_type')->default('upload'); // upload, url, api
            $table->string('source_path')->nullable();
            $table->string('file_hash')->nullable()->index();
            $table->bigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->index();
            $table->string('processing_job_id')->nullable();
            $table->string('external_document_id')->nullable()->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['processing_status', 'created_at']);
        });
    }

    public function down()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        Schema::dropIfExists($prefix . 'knowledge_documents');
    }
};