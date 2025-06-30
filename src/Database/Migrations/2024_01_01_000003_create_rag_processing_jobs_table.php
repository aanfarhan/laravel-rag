<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        
        Schema::create($prefix . 'processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained($prefix . 'knowledge_documents')->onDelete('cascade');
            $table->enum('job_type', ['document_processing', 'embedding_generation', 'vector_sync']);
            $table->enum('status', ['queued', 'processing', 'completed', 'failed', 'retrying'])->default('queued')->index();
            $table->string('external_job_id')->nullable()->index();
            $table->string('api_provider')->nullable();
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
            $table->index(['job_type', 'status']);
        });
    }

    public function down()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        Schema::dropIfExists($prefix . 'processing_jobs');
    }
};