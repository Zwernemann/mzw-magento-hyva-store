<?php
/**
 * Demo_Catalog – demo store catalog (attributes, attribute sets, categories, importer).
 * Part of the Magento AI Starter demo shop. Not for production use.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Demo_Catalog', __DIR__);
