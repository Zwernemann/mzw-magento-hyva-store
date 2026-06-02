<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Api;

/**
 * REST endpoint for customer self-service GDPR erasure.
 *
 * Route: DELETE /V1/cc/privacy/me
 * Authentication: customer token (Magento_Customer::self)
 */
interface CustomerPrivacyInterface
{
    /**
     * Anonymize all conversation data for the currently authenticated customer.
     *
     * @return string Confirmation message
     */
    public function deleteMyData(): string;
}
