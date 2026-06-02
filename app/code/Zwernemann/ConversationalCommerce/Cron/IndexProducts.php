<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\Rag\ProductIndexer;

/**
 * Cron job: Re-index changed Magento products into Pinecone.
 * Runs nightly. Only processes products whose checksum has changed.
 */
class IndexProducts
{
    private const XML_PATH_ENABLED = 'conversional_commerce/general/enabled';

    public function __construct(
        private readonly ProductIndexer        $indexer,
        private readonly ScopeConfigInterface  $config,
        private readonly LoggerInterface       $logger
    ) {}

    public function execute(): void
    {
        if (!$this->config->isSetFlag(self::XML_PATH_ENABLED)) {
            return;
        }

        $this->logger->info('ConversationalCommerce: Starting nightly product index...');
        try {
            $this->indexer->indexAll(false);
        } catch (\Throwable $e) {
            $this->logger->error('ConversationalCommerce: Product indexing failed – ' . $e->getMessage());
        }
    }
}
