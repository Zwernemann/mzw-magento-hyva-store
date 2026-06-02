<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $loggerType = Logger::DEBUG;
    protected $fileName   = '/var/log/conversationalcommerce.log';
}
