<?php

namespace NoBugfixToDisableNonExistingVariants\Decorators;

use Shopware\Bundle\StoreFrontBundle\Gateway\ConfiguratorGatewayInterface;
use Shopware\Bundle\StoreFrontBundle\Service\ConfiguratorServiceInterface;
use Shopware\Bundle\StoreFrontBundle\Service\Core\ConfiguratorService;
use Shopware\Bundle\StoreFrontBundle\Struct;

class StorefrontConfiguratorService implements ConfiguratorServiceInterface
{
    /** @var ConfiguratorServiceInterface */
    private $decorated;

    /** @var ConfiguratorGatewayInterface */
    private $gateway;

    public function __construct(ConfiguratorServiceInterface $decorated, ConfiguratorGatewayInterface $gateway)
    {
        $this->decorated = $decorated;
        $this->gateway = $gateway;
    }

    /**
     * {@inheritdoc}
     */
    public function getProductConfiguration(Struct\BaseProduct $product, Struct\ShopContextInterface $context)
    {
        return $this->decorated->getProductConfiguration($product, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function getProductsConfigurations($products, Struct\ShopContextInterface $context)
    {
        return $this->decorated->getProductsConfigurations($products, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function getProductConfigurator(
        Struct\BaseProduct $product,
        Struct\ShopContextInterface $context,
        array $selection
    ) {
        $configurator = $this->gateway->get($product, $context);
        $combinations = call_user_func([$this->gateway, 'getProductCombinations'], $product, $selection);

        $media = [];
        if ($configurator->getType() === ConfiguratorService::CONFIGURATOR_TYPE_PICTURE) {
            $media = $this->gateway->getConfiguratorMedia($product, $context);
        }

        foreach ($configurator->getGroups() as $group) {
            $group->setSelected(isset($selection[$group->getId()]));

            foreach ($group->getOptions() as $option) {
                $option->setSelected(in_array($option->getId(), $selection));
                $option->setActive($option->isSelected() || $this->isOptionInCombinations($group, $option, $combinations));

                if (isset($media[$option->getId()])) {
                    $option->setMedia($media[$option->getId()]);
                }
            }
        }

        return $configurator;
    }

    protected function isOptionInCombinations(
        Struct\Configurator\Group $group,
        Struct\Configurator\Option $option,
        array $combinations
    ) {
        foreach ($combinations as $combination) {
            if ($combination[$group->getId()] === $option->getId()) {
                return true;
            }
        }

        return false;
    }
}
