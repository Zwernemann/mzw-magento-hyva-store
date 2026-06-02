<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Rag;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;

/**
 * Wrapper around the Voyage AI Embeddings REST API.
 * Endpoint: POST https://api.voyageai.com/v1/embeddings
 */
class VoyageClient
{
    private const API_URL = 'https://api.voyageai.com/v1/embeddings';

    private const XML_PATH_KEY   = 'conversional_commerce/voyage/api_key';
    private const XML_PATH_MODEL = 'conversional_commerce/voyage/model';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly Curl                 $curl,
        private readonly LoggerInterface      $logger,
        private readonly EncryptorInterface   $encryptor,
        private readonly PipelineLogger       $pipelineLogger
    ) {}

    /**
     * Embed a single text string.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        $results = $this->embedBatch([$text]);
        return $results[0] ?? [];
    }

    /**
     * Embed multiple texts in a single API call (max 128 per batch).
     *
     * @param  string[]        $texts
     * @return array<int, float[]>
     */
    public function embedBatch(array $texts): array
    {
        $apiKey = trim($this->encryptor->decrypt($this->config->getValue(self::XML_PATH_KEY) ?? ''));
        $model  = $this->config->getValue(self::XML_PATH_MODEL) ?? 'voyage-3';

        if (empty($apiKey)) {
            throw new \RuntimeException('ConversationalCommerce: Voyage API key not configured.');
        }

        $this->pipelineLogger->section('VOYAGE EMBEDDING');
        $this->pipelineLogger->data('Model', $model);
        $this->pipelineLogger->data('Texts to embed (' . count($texts) . ')', $texts);

        $payload = json_encode(['input' => $texts, 'model' => $model]);

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ]);
        $this->curl->post(self::API_URL, $payload);

        $body     = $this->curl->getBody();
        $response = json_decode($body, true);

        if (!isset($response['data']) || !is_array($response['data'])) {
            $this->logger->error('ConversationalCommerce: Voyage API error – ' . $body);
            throw new \RuntimeException('Voyage API error: ' . ($response['detail'] ?? $body));
        }

        $embeddings = [];
        foreach ($response['data'] as $item) {
            $embeddings[(int)$item['index']] = $item['embedding'];
        }
        ksort($embeddings);
        $result = array_values($embeddings);

        if (!empty($result)) {
            $first = $result[0];
            $dims  = count($first);
            $this->pipelineLogger->data(
                'Embeddings returned',
                sprintf(
                    '%d vector(s), %d dimensions each. First vector sample: [%s … %s]',
                    count($result),
                    $dims,
                    implode(', ', array_map(fn($v) => round($v, 6), array_slice($first, 0, 4))),
                    implode(', ', array_map(fn($v) => round($v, 6), array_slice($first, -3)))
                )
            );
        }

        return $result;
    }
}
