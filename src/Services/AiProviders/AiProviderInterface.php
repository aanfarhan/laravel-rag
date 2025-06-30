<?php

namespace Omniglies\LaravelRag\Services\AiProviders;

interface AiProviderInterface
{
    public function generateResponse(string $prompt, array $options = []): array;
    
    public function streamResponse(string $prompt, array $options = []): \Generator;
    
    public function getModel(): string;
    
    public function getMaxTokens(): int;
    
    public function validateApiKey(): bool;
    
    public function calculateCost(int $inputTokens, int $outputTokens): float;
    
    public function getUsageStats(int $days = 30): array;
}