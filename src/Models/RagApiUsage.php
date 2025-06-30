<?php

namespace Omniglies\LaravelRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RagApiUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'operation_type',
        'tokens_used',
        'cost_usd',
        'document_id',
    ];

    protected $casts = [
        'tokens_used' => 'integer',
        'cost_usd' => 'decimal:6',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('rag.table_prefix', 'rag_') . 'api_usage';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RagKnowledgeDocument::class, 'document_id');
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeByOperation($query, string $operation)
    {
        return $query->where('operation_type', $operation);
    }

    public function scopeByDocument($query, $documentId)
    {
        return $query->where('document_id', $documentId);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', now()->toDateString());
    }

    public static function recordUsage(
        string $provider,
        string $operationType,
        ?int $tokensUsed = null,
        ?float $costUsd = null,
        ?int $documentId = null
    ): self {
        if (!config('rag.analytics.track_usage', true)) {
            return new static();
        }

        return static::create([
            'provider' => $provider,
            'operation_type' => $operationType,
            'tokens_used' => $tokensUsed,
            'cost_usd' => $costUsd,
            'document_id' => $documentId,
        ]);
    }

    public static function getTotalCostByProvider(string $provider, int $days = 30): float
    {
        return static::byProvider($provider)
                    ->recent($days)
                    ->sum('cost_usd') ?? 0.0;
    }

    public static function getTotalTokensByProvider(string $provider, int $days = 30): int
    {
        return static::byProvider($provider)
                    ->recent($days)
                    ->sum('tokens_used') ?? 0;
    }

    public static function getTotalCostByOperation(string $operation, int $days = 30): float
    {
        return static::byOperation($operation)
                    ->recent($days)
                    ->sum('cost_usd') ?? 0.0;
    }

    public static function getDailyCosts(int $days = 30): array
    {
        return static::selectRaw('DATE(created_at) as date, SUM(cost_usd) as total_cost')
                    ->recent($days)
                    ->groupBy('date')
                    ->orderBy('date')
                    ->pluck('total_cost', 'date')
                    ->toArray();
    }

    public static function getUsageStatistics(int $days = 30): array
    {
        $stats = static::recent($days)
                       ->selectRaw('
                           provider,
                           operation_type,
                           COUNT(*) as request_count,
                           SUM(tokens_used) as total_tokens,
                           SUM(cost_usd) as total_cost,
                           AVG(cost_usd) as avg_cost_per_request
                       ')
                       ->groupBy(['provider', 'operation_type'])
                       ->get();

        return $stats->groupBy('provider')->map(function ($providerStats) {
            return $providerStats->keyBy('operation_type');
        })->toArray();
    }
}