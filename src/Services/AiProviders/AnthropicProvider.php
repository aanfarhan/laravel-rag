<?php

namespace Omniglies\LaravelRag\Services\AiProviders;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Omniglies\LaravelRag\Models\RagApiUsage;
use Omniglies\LaravelRag\Exceptions\AiProviderException;

class AnthropicProvider implements AiProviderInterface
{
    protected Client $client;
    protected array $config;

    public function __construct()
    {
        $this->config = config('rag.providers.anthropic', []);
        
        if (empty($this->config['api_key'])) {
            throw AiProviderException::apiKeyMissing('Anthropic');
        }

        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'headers' => [
                'x-api-key' => $this->config['api_key'],
                'anthropic-version' => '2023-06-01',
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

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        try {
            $response = $this->client->post('messages', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['content'][0]['text'])) {
                throw new \Exception('Invalid response format from Anthropic');
            }

            $inputTokens = $data['usage']['input_tokens'] ?? 0;
            $outputTokens = $data['usage']['output_tokens'] ?? 0;
            $totalTokens = $inputTokens + $outputTokens;

            return [
                'content' => $data['content'][0]['text'],
                'model' => $data['model'] ?? $model,
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $totalTokens,
                    'cost' => $this->calculateCost($inputTokens, $outputTokens),
                ],
                'stop_reason' => $data['stop_reason'] ?? null,
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

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => true,
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        try {
            $response = $this->client->post('messages', [
                'json' => $payload,
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
                        
                        if ($data['type'] === 'content_block_delta' && isset($data['delta']['text'])) {
                            $chunk = $data['delta']['text'];
                            $content .= $chunk;
                            $outputTokens++;
                            
                            yield [
                                'content' => $chunk,
                                'accumulated_content' => $content,
                                'is_complete' => false,
                            ];
                        }
                        
                        if ($data['type'] === 'message_start' && isset($data['message']['usage'])) {
                            $inputTokens = $data['message']['usage']['input_tokens'] ?? 0;
                        }
                        
                        if ($data['type'] === 'message_delta' && isset($data['usage'])) {
                            $outputTokens = $data['usage']['output_tokens'] ?? $outputTokens;
                        }
                    }
                }
            }

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
        return $this->config['model'] ?? 'claude-3-sonnet-20240229';
    }

    public function getMaxTokens(): int
    {
        $model = $this->getModel();
        
        return match (true) {
            str_contains($model, 'claude-3') => 200000,
            str_contains($model, 'claude-2') => 100000,
            default => 100000,
        };
    }

    public function validateApiKey(): bool
    {
        try {
            $response = $this->client->post('messages', [
                'json' => [
                    'model' => $this->getModel(),
                    'max_tokens' => 1,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Test']
                    ],
                ],
            ]);
            
            return $response->getStatusCode() === 200;
            
        } catch (RequestException $e) {
            Log::error('Anthropic API key validation failed', [
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
            str_contains($model, 'claude-3-opus') => ['input' => 15.0, 'output' => 75.0],
            str_contains($model, 'claude-3-sonnet') => ['input' => 3.0, 'output' => 15.0],
            str_contains($model, 'claude-3-haiku') => ['input' => 0.25, 'output' => 1.25],
            str_contains($model, 'claude-2') => ['input' => 8.0, 'output' => 24.0],
            default => ['input' => 3.0, 'output' => 15.0],
        };

        return ($inputTokens * $rates['input'] + $outputTokens * $rates['output']) / 1000000;
    }

    public function getUsageStats(int $days = 30): array
    {
        $stats = RagApiUsage::byProvider('anthropic')
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
            'provider' => 'anthropic',
            'model' => $this->getModel(),
            'total_requests' => $stats->total_requests ?? 0,
            'total_tokens' => $stats->total_tokens ?? 0,
            'total_cost' => $stats->total_cost ?? 0.0,
            'avg_cost_per_request' => $stats->avg_cost_per_request ?? 0.0,
        ];
    }

    public function getAvailableModels(): array
    {
        return [
            'claude-3-opus-20240229',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307',
            'claude-2.1',
            'claude-2.0',
        ];
    }

    public function analyzeText(string $text, array $options = []): array
    {
        $analysisType = $options['type'] ?? 'sentiment';
        
        $prompts = [
            'sentiment' => "Please analyze the sentiment of the following text. Respond with just 'positive', 'negative', or 'neutral':\n\n{$text}",
            'toxicity' => "Please analyze if the following text contains toxic content. Respond with just 'toxic' or 'safe':\n\n{$text}",
            'language' => "Please identify the language of the following text. Respond with just the language name:\n\n{$text}",
            'summary' => "Please provide a brief summary of the following text in 1-2 sentences:\n\n{$text}",
        ];

        $prompt = $prompts[$analysisType] ?? $prompts['sentiment'];
        
        try {
            $response = $this->generateResponse($prompt, [
                'max_tokens' => 100,
                'temperature' => 0.1,
            ]);
            
            return [
                'analysis_type' => $analysisType,
                'result' => trim($response['content']),
                'usage' => $response['usage'],
            ];
            
        } catch (\Exception $e) {
            Log::error('Text analysis failed', [
                'type' => $analysisType,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'analysis_type' => $analysisType,
                'result' => 'unknown',
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
                    case 'rate_limit_error':
                        throw AiProviderException::rateLimitExceeded('Anthropic');
                    case 'invalid_request_error':
                        throw AiProviderException::requestFailed('Anthropic', $errorMessage);
                    default:
                        break;
                }
            }
        }

        switch ($statusCode) {
            case 401:
                throw AiProviderException::apiKeyMissing('Anthropic');
            case 429:
                throw AiProviderException::rateLimitExceeded('Anthropic');
            case 400:
                throw AiProviderException::requestFailed('Anthropic', $errorMessage);
            default:
                throw AiProviderException::requestFailed('Anthropic', $errorMessage);
        }
    }
}