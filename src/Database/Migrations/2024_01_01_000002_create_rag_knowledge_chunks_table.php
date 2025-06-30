<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        
        Schema::create($prefix . 'knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained($prefix . 'knowledge_documents')->onDelete('cascade');
            $table->text('content');
            $table->integer('chunk_index')->default(0);
            $table->string('chunk_hash')->index();
            $table->string('vector_id')->nullable()->index();
            $table->timestamp('vector_database_synced_at')->nullable();
            $table->string('embedding_model')->nullable();
            $table->integer('embedding_dimensions')->nullable();
            $table->json('chunk_metadata')->nullable();
            $table->json('keywords')->nullable();
            $table->timestamps();
            
            $table->index(['document_id', 'chunk_index']);
            $table->index('vector_database_synced_at');
        });

        // Add full-text search index for PostgreSQL
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$prefix}knowledge_chunks ADD COLUMN search_vector tsvector");
            DB::statement("CREATE INDEX {$prefix}knowledge_chunks_search_vector_idx ON {$prefix}knowledge_chunks USING GIN (search_vector)");
            DB::statement("
                CREATE OR REPLACE FUNCTION update_{$prefix}knowledge_chunks_search_vector() RETURNS trigger AS $$
                BEGIN
                    NEW.search_vector := to_tsvector('english', COALESCE(NEW.content, ''));
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            DB::statement("
                CREATE TRIGGER {$prefix}knowledge_chunks_search_vector_update 
                BEFORE INSERT OR UPDATE ON {$prefix}knowledge_chunks 
                FOR EACH ROW EXECUTE FUNCTION update_{$prefix}knowledge_chunks_search_vector();
            ");
        }
    }

    public function down()
    {
        $prefix = config('rag.table_prefix', 'rag_');
        
        // Drop PostgreSQL-specific elements
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("DROP TRIGGER IF EXISTS {$prefix}knowledge_chunks_search_vector_update ON {$prefix}knowledge_chunks");
            DB::statement("DROP FUNCTION IF EXISTS update_{$prefix}knowledge_chunks_search_vector()");
        }
        
        Schema::dropIfExists($prefix . 'knowledge_chunks');
    }
};