<?php

namespace Omniglies\LaravelRag\Exceptions;

use Exception;

class AiProviderException extends Exception
{
    public static function apiKeyMissing(string $provider): self
    {
        return new static("API key missing for provider: {$provider}");
    }

    public static function rateLimitExceeded(string $provider): self
    {
        return new static("Rate limit exceeded for provider: {$provider}");
    }

    public static function modelNotAvailable(string $model, string $provider): self
    {
        return new static("Model '{$model}' not available for provider: {$provider}");
    }

    public static function requestFailed(string $provider, string $reason): self
    {
        return new static("Request to {$provider} failed: {$reason}");
    }

    public static function invalidResponse(string $provider): self
    {
        return new static("Invalid response from provider: {$provider}");
    }

    public static function tokenLimitExceeded(string $provider, int $tokenCount, int $limit): self
    {
        return new static("Token limit exceeded for {$provider}: {$tokenCount} tokens exceeds limit of {$limit}");
    }

    public static function unsupportedProvider(string $provider): self
    {
        return new static("Unsupported AI provider: {$provider}");
    }

    public static function configurationError(string $provider, string $issue): self
    {
        return new static("Configuration error for {$provider}: {$issue}");
    }
}