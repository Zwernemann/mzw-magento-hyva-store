<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Llm;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\LlmClientInterface;
use Zwernemann\ConversationalCommerce\Model\Analytics\UsageLogger;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;

/**
 * Mistral AI API client — DSGVO-konform (EU-Unternehmen, EU-Rechenzentren).
 *
 * Nutzt das OpenAI-kompatible Chat-Completions-Format von Mistral.
 * System-Prompt wird als erste Nachricht mit role:"system" übergeben.
 * Strukturierte Ausgabe via Mistral function calling (tool_calls in der Response).
 *
 * Hinweis: Native PDF-Document-Blöcke (Anthropic-spezifisch) werden ignoriert.
 * PDF-Textinhalte müssen über den AttachmentProcessor als Text extrahiert werden.
 */
class MistralClient implements LlmClientInterface
{
    private const API_URL = 'https://api.mistral.ai/v1/chat/completions';

    private const XML_PATH_KEY        = 'conversional_commerce/mistral/api_key';
    private const XML_PATH_MODEL      = 'conversional_commerce/mistral/model';
    private const XML_PATH_MAX_TOKENS = 'conversional_commerce/mistral/max_tokens';
    private const XML_PATH_FAST_MODEL = 'conversional_commerce/mistral/fast_model';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly Curl                 $curl,
        private readonly LoggerInterface      $logger,
        private readonly EncryptorInterface   $encryptor,
        private readonly PipelineLogger       $pipelineLogger,
        private readonly UsageLogger          $usageLogger
    ) {}

    public function chat(array $messages, string $systemPrompt = '', array $options = []): string
    {
        $response = $this->callApi($messages, $systemPrompt, $options);

        $content = $response['choices'][0]['message']['content'] ?? null;
        if ($content !== null && $content !== '') {
            return (string)$content;
        }

        $this->logger->error('ConversationalCommerce: Mistral API — no content in response: ' . json_encode($response));
        throw new \RuntimeException('Mistral API error: no content in response.');
    }

    /**
     * Force structured JSON output via Mistral function calling.
     *
     * $documentBlocks (Anthropic-native PDF format) are intentionally ignored —
     * Mistral has no native document understanding API. PDF content must be provided
     * as extracted text via ContextBuilder / AttachmentProcessor.
     */
    public function chatWithTool(
        array  $messages,
        string $systemPrompt,
        string $toolName,
        array  $inputSchema,
        array  $options = [],
        array  $documentBlocks = []
    ): array {
        if (!empty($documentBlocks)) {
            $this->logger->warning(
                '[MistralClient] ' . count($documentBlocks) . ' PDF document block(s) cannot be processed natively. '
                . 'PDF text content must be pre-extracted via AttachmentProcessor.'
            );
        }

        $options['tools'] = [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => $toolName,
                    'description' => 'Submit your structured response using this tool.',
                    'parameters'  => $inputSchema,
                ],
            ],
        ];
        $options['tool_choice'] = ['type' => 'function', 'function' => ['name' => $toolName]];

        $response = $this->callApi($messages, $systemPrompt, $options);

        $toolCalls = $response['choices'][0]['message']['tool_calls'] ?? [];
        foreach ($toolCalls as $call) {
            if (($call['function']['name'] ?? '') === $toolName) {
                $arguments = $call['function']['arguments'] ?? '{}';
                $parsed    = json_decode($arguments, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                    return $parsed;
                }
                $this->logger->error(
                    '[MistralClient] chatWithTool — JSON decode failed for tool "' . $toolName . '": ' . $arguments
                );
                return [];
            }
        }

        $this->logger->error(
            '[MistralClient] chatWithTool — no tool_call for "' . $toolName . '" in response: '
            . json_encode($response)
        );
        return [];
    }

    /**
     * Chat with structured JSON output (legacy path — prefer chatWithTool for new calls).
     */
    public function chatJson(array $messages, string $systemPrompt = '', array $options = []): array
    {
        $text = $this->chat($messages, $systemPrompt, $options);

        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $data = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('[MistralClient] Non-JSON response: ' . $text);
            return ['raw' => $text];
        }
        return $data;
    }

    public function getFastModelOptions(): array
    {
        $fastModel = (string)($this->config->getValue(self::XML_PATH_FAST_MODEL) ?? 'ministral-3b-latest');
        return ['model' => $fastModel, 'max_tokens' => 100];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Make the HTTP call to the Mistral Chat Completions API and return the decoded response.
     *
     * The system prompt is injected as the first message with role:"system" —
     * Mistral (OpenAI-compatible format) has no separate top-level system field.
     *
     * @param array<string, mixed> $options  May include tools, tool_choice, temperature, model, max_tokens
     * @return array<string, mixed>
     */
    private function callApi(array $messages, string $systemPrompt, array $options = []): array
    {
        $apiKey    = trim($this->encryptor->decrypt($this->config->getValue(self::XML_PATH_KEY) ?? ''));
        $model     = $options['model']      ?? ($this->config->getValue(self::XML_PATH_MODEL) ?? 'mistral-large-latest');
        $maxTokens = $options['max_tokens'] ?? (int)($this->config->getValue(self::XML_PATH_MAX_TOKENS) ?? 8192);

        if (empty($apiKey)) {
            throw new \RuntimeException('ConversationalCommerce: Mistral API key not configured.');
        }

        // Build messages array: system prompt as first message, then conversation turns
        $apiMessages = [];
        if (!empty($systemPrompt)) {
            $apiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($messages as $msg) {
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $apiMessages,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['tools'])) {
            $payload['tools']       = $options['tools'];
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $this->pipelineLogger->section('MISTRAL API CALL');
        $this->pipelineLogger->data('Model', $model);
        $this->pipelineLogger->data('Max tokens', $maxTokens);
        if (!empty($systemPrompt)) {
            $this->pipelineLogger->raw('System prompt', $systemPrompt);
        }
        $this->pipelineLogger->data('Messages', $messages);
        if (isset($options['tools'])) {
            $this->pipelineLogger->data('Tools', $options['tools']);
            $this->pipelineLogger->data('Tool choice', $options['tool_choice'] ?? null);
        }

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ]);
        $this->curl->post(self::API_URL, json_encode($payload));

        $body     = $this->curl->getBody();
        $this->pipelineLogger->addDebugBlock($model, $payload, $body);
        $this->pipelineLogger->raw('Raw HTTP response body', $body);
        $response = json_decode($body, true);

        if (!is_array($response) || !isset($response['choices'])) {
            $this->logger->error('ConversationalCommerce: Mistral API error – ' . $body);
            throw new \RuntimeException('Mistral API error: ' . ($response['error']['message'] ?? $body));
        }

        $usage = $response['usage'] ?? [];
        $costFloat = $this->estimateCostFloat($model, $usage);
        $this->pipelineLogger->data('Usage / cost', [
            'model'             => $model,
            'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens'      => $usage['total_tokens'] ?? 0,
            'cost_estimate'     => '$' . number_format($costFloat, 5),
        ]);
        $this->logger->info('[LLM] Mistral call', [
            'model'             => $model,
            'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'cost_estimate'     => '$' . number_format($costFloat, 5),
        ]);
        $this->usageLogger->log([
            'provider'      => 'mistral',
            'model'         => $model,
            'input_tokens'  => $usage['prompt_tokens'] ?? 0,
            'output_tokens' => $usage['completion_tokens'] ?? 0,
            'cost_usd'      => $costFloat,
        ]);

        return $response;
    }

    private function estimateCostFloat(string $model, array $usage): float
    {
        // Prices in USD per 1M tokens
        $prices = [
            'mistral-large-latest' => ['in' => 2.0,  'out' => 6.0],
            'mistral-small-latest' => ['in' => 0.2,  'out' => 0.6],
            'ministral-8b-latest'  => ['in' => 0.1,  'out' => 0.1],
            'ministral-3b-latest'  => ['in' => 0.04, 'out' => 0.04],
        ];
        $p = $prices[$model] ?? $prices['mistral-large-latest'];
        return (($usage['prompt_tokens'] ?? 0)     * $p['in']
              + ($usage['completion_tokens'] ?? 0) * $p['out']) / 1_000_000;
    }
}
