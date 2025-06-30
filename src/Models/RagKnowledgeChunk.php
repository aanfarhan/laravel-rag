<?php

namespace Omniglies\LaravelRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RagKnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'content',
        'chunk_index',
        'chunk_hash',
        'vector_id',
        'vector_database_synced_at',
        'embedding_model',
        'embedding_dimensions',
        'chunk_metadata',
        'keywords',
    ];

    protected $casts = [
        'chunk_metadata' => 'array',
        'keywords' => 'array',
        'vector_database_synced_at' => 'datetime',
        'chunk_index' => 'integer',
        'embedding_dimensions' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('rag.table_prefix', 'rag_') . 'knowledge_chunks';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RagKnowledgeDocument::class, 'document_id');
    }

    public function scopeSynced($query)
    {
        return $query->whereNotNull('vector_database_synced_at');
    }

    public function scopeUnsynced($query)
    {
        return $query->whereNull('vector_database_synced_at');
    }

    public function scopeByDocument($query, $documentId)
    {
        return $query->where('document_id', $documentId);
    }

    public function scopeFullTextSearch($query, string $searchTerm)
    {
        if (config('database.default') === 'pgsql') {
            return $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$searchTerm]);
        }

        // Fallback for other databases
        return $query->where('content', 'LIKE', '%' . $searchTerm . '%');
    }

    public function isSynced(): bool
    {
        return !is_null($this->vector_database_synced_at);
    }

    public function markAsSynced(): void
    {
        $this->update(['vector_database_synced_at' => now()]);
    }

    public function getWordCountAttribute(): int
    {
        return str_word_count($this->content);
    }

    public function getCharacterCountAttribute(): int
    {
        return strlen($this->content);
    }

    public function getSimilarityScore(): ?float
    {
        return $this->similarity_score ?? null;
    }

    public function setSimilarityScore(float $score): void
    {
        $this->similarity_score = $score;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($chunk) {
            if (empty($chunk->chunk_hash)) {
                $chunk->chunk_hash = hash('sha256', $chunk->content);
            }
        });

        static::updating(function ($chunk) {
            if ($chunk->isDirty('content')) {
                $chunk->chunk_hash = hash('sha256', $chunk->content);
                $chunk->vector_database_synced_at = null;
            }
        });
    }
}