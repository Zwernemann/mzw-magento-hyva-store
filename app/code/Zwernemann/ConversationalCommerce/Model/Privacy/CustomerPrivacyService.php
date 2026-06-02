<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Privacy;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Zwernemann\ConversationalCommerce\Api\CustomerPrivacyInterface;
use Zwernemann\ConversationalCommerce\Api\GdprServiceInterface;

/**
 * Customer self-service GDPR erasure via REST API.
 * The authenticated customer can only delete their own data.
 */
class CustomerPrivacyService implements CustomerPrivacyInterface
{
    public function __construct(
        private readonly GdprServiceInterface       $gdprService,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly RestRequest                 $request
    ) {}

    public function deleteMyData(): string
    {
        $customerId = (int)$this->request->getParam('customerId', 0);

        if ($customerId <= 0) {
            throw new LocalizedException(__('Kunden-ID konnte nicht ermittelt werden.'));
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Kunden-Account nicht gefunden.'));
        }

        $email = $customer->getEmail();
        $count = $this->gdprService->anonymizeByEmail($email);

        return (string)__(
            'Ihre Gesprächsdaten (%1 Gespräch(e)) wurden gemäß Art. 17 DSGVO anonymisiert.',
            $count
        );
    }
}
