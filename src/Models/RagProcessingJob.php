<?php

namespace Omniglies\LaravelRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RagProcessingJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'job_type',
        'status',
        'external_job_id',
        'api_provider',
        'progress_percentage',
        'error_message',
        'retry_count',
        'max_retries',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'progress_percentage' => 'decimal:2',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('rag.table_prefix', 'rag_') . 'processing_jobs';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RagKnowledgeDocument::class, 'document_id');
    }

    public function scopeQueued($query)
    {
        return $query->where('status', 'queued');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRetrying($query)
    {
        return $query->where('status', 'retrying');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('job_type', $type);
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('api_provider', $provider);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function canRetry(): bool
    {
        return $this->retry_count < $this->max_retries && $this->isFailed();
    }

    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'progress_percentage' => 100.00,
        ]);
    }

    public function markAsFailed(string $errorMessage = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function markAsRetrying(): void
    {
        $this->update([
            'status' => 'retrying',
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    public function updateProgress(float $percentage): void
    {
        $this->update([
            'progress_percentage' => min(100.00, max(0.00, $percentage)),
        ]);
    }

    public function getDurationAttribute(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $endTime = $this->completed_at ?? now();
        return $this->started_at->diffInSeconds($endTime);
    }
}