<?php

namespace Omniglies\LaravelRag\Services\AiProviders;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Omniglies\LaravelRag\Models\RagApiUsage;
use Omniglies\LaravelRag\Exceptions\AiProviderException;

class OpenAiProvider implements AiProviderInterface
{
    protected Client $client;
    protected array $config;

    public function __construct()
    {
        $this->config = config('rag.providers.openai', []);
        
        if (empty($this->config['api_key'])) {
            throw AiProviderException::apiKeyMissing('OpenAI');
        }

        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'timeout' => 60,
        ]);
    }

    public function generateResponse(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? $this->getModel();
        $maxTokens = $options['max_tokens'] ?? $this->config['max_tokens'] ?? 1000;
        $temperature = $options['temperature'] ?? $this->config['temperature'] ?? 0.7;
        $systemPrompt = $options['system_prompt'] ?? null;

        $messages = [];
        
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                    'stream' => false,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \Exception('Invalid response format from OpenAI');
            }

            $inputTokens = $data['usage']['prompt_tokens'] ?? 0;
            $outputTokens = $data['usage']['completion_tokens'] ?? 0;
            $totalTokens = $data['usage']['total_tokens'] ?? ($inputTokens + $outputTokens);

            return [
                'content' => $data['choices'][0]['message']['content'],
                'model' => $data['model'] ?? $model,
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $totalTokens,
                    'cost' => $this->calculateCost($inputTokens, $outputTokens),
                ],
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
                'raw_response' => $data,
            ];

        } catch (RequestException $e) {
            $this->handleRequestException($e);
        }
    }

    public function streamResponse(string $prompt, array $options = []): \Generator
    {
        $model = $options['model'] ?? $this->getModel();
        $maxTokens = $options['max_tokens'] ?? $this->config['max_tokens'] ?? 1000;
        $temperature = $options['temperature'] ?? $this->config['temperature'] ?? 0.7;
        $systemPrompt = $options['system_prompt'] ?? null;

        $messages = [];
        
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                    'stream' => true,
                ],
                'stream' => true,
            ]);

            $body = $response->getBody();
            $content = '';
            $inputTokens = 0;
            $outputTokens = 0;

            while (!$body->eof()) {
                $line = $body->read(1024);
                $lines = explode("\n", $line);
                
                foreach ($lines as $line) {
                    if (strpos($line, 'data: ') === 0) {
                        $json = substr($line, 6);
                        
                        if (trim($json) === '[DONE]') {
                            break 2;
                        }
                        
                        $data = json_decode($json, true);
                        
                        if (isset($data['choices'][0]['delta']['content'])) {
                            $chunk = $data['choices'][0]['delta']['content'];
                            $content .= $chunk;
                            $outputTokens++;
                            
                            yield [
                                'content' => $chunk,
                                'accumulated_content' => $content,
                                'is_complete' => false,
                            ];
                        }
                    }
                }
            }

            $inputTokens = $this->estimateTokenCount($prompt);
            
            yield [
                'content' => '',
                'accumulated_content' => $content,
                'is_complete' => true,
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $inputTokens + $outputTokens,
                    'cost' => $this->calculateCost($inputTokens, $outputTokens),
                ],
            ];

        } catch (RequestException $e) {
            $this->handleRequestException($e);
        }
    }

    public function getModel(): string
    {
        return $this->config['model'] ?? 'gpt-3.5-turbo';
    }

    public function getMaxTokens(): int
    {
        $model = $this->getModel();
        
        return match (true) {
            str_contains($model, 'gpt-4') => 8192,
            str_contains($model, 'gpt-3.5-turbo') => 4096,
            default => 4096,
        };
    }

    public function validateApiKey(): bool
    {
        try {
            $response = $this->client->get('models');
            return $response->getStatusCode() === 200;
            
        } catch (RequestException $e) {
            Log::error('OpenAI API key validation failed', [
                'error' => $e->getMessage(),
                'status_code' => $e->getResponse() ? $e->getResponse()->getStatusCode() : null,
            ]);
            
            return false;
        }
    }

    public function calculateCost(int $inputTokens, int $outputTokens): float
    {
        $model = $this->getModel();
        
        $rates = match (true) {
            str_contains($model, 'gpt-4') => ['input' => 0.03, 'output' => 0.06],
            str_contains($model, 'gpt-3.5-turbo') => ['input' => 0.0015, 'output' => 0.002],
            default => ['input' => 0.0015, 'output' => 0.002],
        };

        return ($inputTokens * $rates['input'] + $outputTokens * $rates['output']) / 1000;
    }

    public function getUsageStats(int $days = 30): array
    {
        $stats = RagApiUsage::byProvider('openai')
                           ->byOperation('chat_completion')
                           ->recent($days)
                           ->selectRaw('
                               COUNT(*) as total_requests,
                               SUM(tokens_used) as total_tokens,
                               SUM(cost_usd) as total_cost,
                               AVG(cost_usd) as avg_cost_per_request
                           ')
                           ->first();

        return [
            'provider' => 'openai',
            'model' => $this->getModel(),
            'total_requests' => $stats->total_requests ?? 0,
            'total_tokens' => $stats->total_tokens ?? 0,
            'total_cost' => $stats->total_cost ?? 0.0,
            'avg_cost_per_request' => $stats->avg_cost_per_request ?? 0.0,
        ];
    }

    public function getAvailableModels(): array
    {
        try {
            $response = $this->client->get('models');
            $data = json_decode($response->getBody()->getContents(), true);
            
            $chatModels = array_filter($data['data'] ?? [], function ($model) {
                return str_contains($model['id'], 'gpt');
            });
            
            return array_column($chatModels, 'id');
            
        } catch (RequestException $e) {
            Log::error('Failed to get OpenAI models', ['error' => $e->getMessage()]);
            return ['gpt-3.5-turbo', 'gpt-4'];
        }
    }

    public function moderateContent(string $content): array
    {
        try {
            $response = $this->client->post('moderations', [
                'json' => ['input' => $content],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            return [
                'flagged' => $data['results'][0]['flagged'] ?? false,
                'categories' => $data['results'][0]['categories'] ?? [],
                'category_scores' => $data['results'][0]['category_scores'] ?? [],
            ];

        } catch (RequestException $e) {
            Log::error('Content moderation failed', ['error' => $e->getMessage()]);
            
            return [
                'flagged' => false,
                'categories' => [],
                'category_scores' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function handleRequestException(RequestException $e): void
    {
        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
        $errorMessage = $e->getMessage();
        
        if ($e->hasResponse()) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($errorBody, true);
            
            if (isset($errorData['error']['message'])) {
                $errorMessage = $errorData['error']['message'];
            }
            
            if (isset($errorData['error']['type'])) {
                $errorType = $errorData['error']['type'];
                
                switch ($errorType) {
                    case 'insufficient_quota':
                        throw AiProviderException::rateLimitExceeded('OpenAI');
                    case 'invalid_request_error':
                        throw AiProviderException::requestFailed('OpenAI', $errorMessage);
                    default:
                        break;
                }
            }
        }

        switch ($statusCode) {
            case 401:
                throw AiProviderException::apiKeyMissing('OpenAI');
            case 429:
                throw AiProviderException::rateLimitExceeded('OpenAI');
            case 400:
                throw AiProviderException::requestFailed('OpenAI', $errorMessage);
            default:
                throw AiProviderException::requestFailed('OpenAI', $errorMessage);
        }
    }

    protected function estimateTokenCount(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }
}