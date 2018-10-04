<?php

namespace NoBugfixToDisableNonExistingVariants;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\ActivateContext;

class NoBugfixToDisableNonExistingVariants extends Plugin
{
    public function activate(ActivateContext $context)
    {
        parent::activate($context);
        $context->scheduleClearCache(ActivateContext::CACHE_LIST_FRONTEND);
    }
}
