<?php

namespace Omniglies\LaravelRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RagKnowledgeDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'source_type',
        'source_path',
        'file_hash',
        'file_size',
        'mime_type',
        'processing_status',
        'processing_job_id',
        'external_document_id',
        'processing_started_at',
        'processing_completed_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('rag.table_prefix', 'rag_') . 'knowledge_documents';
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(RagKnowledgeChunk::class, 'document_id');
    }

    public function processingJobs(): HasMany
    {
        return $this->hasMany(RagProcessingJob::class, 'document_id');
    }

    public function apiUsage(): HasMany
    {
        return $this->hasMany(RagApiUsage::class, 'document_id');
    }

    public function scopePending($query)
    {
        return $query->where('processing_status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('processing_status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('processing_status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('processing_status', 'failed');
    }

    public function isProcessed(): bool
    {
        return $this->processing_status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->processing_status === 'failed';
    }

    public function isProcessing(): bool
    {
        return $this->processing_status === 'processing';
    }

    public function getTotalChunksAttribute(): int
    {
        return $this->chunks()->count();
    }

    public function getSyncedChunksAttribute(): int
    {
        return $this->chunks()->whereNotNull('vector_database_synced_at')->count();
    }

    public function getProcessingProgressAttribute(): float
    {
        if ($this->processing_status === 'completed') {
            return 100.0;
        }

        if ($this->processing_status === 'failed') {
            return 0.0;
        }

        $totalChunks = $this->getTotalChunksAttribute();
        if ($totalChunks === 0) {
            return 0.0;
        }

        $syncedChunks = $this->getSyncedChunksAttribute();
        return ($syncedChunks / $totalChunks) * 100;
    }
}