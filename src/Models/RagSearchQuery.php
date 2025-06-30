<?php

namespace Omniglies\LaravelRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RagSearchQuery extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'query_text',
        'search_type',
        'results_count',
        'response_time_ms',
        'vector_similarity_scores',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'results_count' => 'integer',
        'response_time_ms' => 'integer',
        'vector_similarity_scores' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('rag.table_prefix', 'rag_') . 'search_queries';
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('search_type', $type);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeSlowQueries($query, int $thresholdMs = 1000)
    {
        return $query->where('response_time_ms', '>', $thresholdMs);
    }

    public function getAverageSimilarityAttribute(): ?float
    {
        if (!$this->vector_similarity_scores || empty($this->vector_similarity_scores)) {
            return null;
        }

        return array_sum($this->vector_similarity_scores) / count($this->vector_similarity_scores);
    }

    public function getMaxSimilarityAttribute(): ?float
    {
        if (!$this->vector_similarity_scores || empty($this->vector_similarity_scores)) {
            return null;
        }

        return max($this->vector_similarity_scores);
    }

    public function getMinSimilarityAttribute(): ?float
    {
        if (!$this->vector_similarity_scores || empty($this->vector_similarity_scores)) {
            return null;
        }

        return min($this->vector_similarity_scores);
    }

    public function isSlow(int $thresholdMs = 1000): bool
    {
        return $this->response_time_ms && $this->response_time_ms > $thresholdMs;
    }

    public static function recordQuery(
        string $queryText,
        string $searchType,
        int $resultsCount,
        ?int $responseTimeMs = null,
        ?array $similarityScores = null,
        ?int $userId = null
    ): self {
        if (!config('rag.analytics.track_searches', true)) {
            return new static();
        }

        return static::create([
            'user_id' => $userId,
            'query_text' => $queryText,
            'search_type' => $searchType,
            'results_count' => $resultsCount,
            'response_time_ms' => $responseTimeMs,
            'vector_similarity_scores' => $similarityScores,
        ]);
    }
}