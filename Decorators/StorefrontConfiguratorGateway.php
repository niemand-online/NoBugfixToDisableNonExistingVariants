<?php

namespace NoBugfixToDisableNonExistingVariants\Decorators;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Shopware\Bundle\StoreFrontBundle\Gateway\ConfiguratorGatewayInterface;
use Shopware\Bundle\StoreFrontBundle\Struct;
use Shopware_Components_Config;

class StorefrontConfiguratorGateway implements ConfiguratorGatewayInterface
{
    /** @var ConfiguratorGatewayInterface */
    private $decorated;

    /** @var Connection */
    private $connection;

    /** @var Shopware_Components_Config */
    private $shopwareConfig;

    public function __construct(
        ConfiguratorGatewayInterface $decorated,
        Connection $connection,
        Shopware_Components_Config $shopwareConfig
    ) {
        $this->decorated = $decorated;
        $this->connection = $connection;
        $this->shopwareConfig = $shopwareConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function get(Struct\BaseProduct $product, Struct\ShopContextInterface $context)
    {
        return $this->decorated->get($product, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguratorMedia(Struct\BaseProduct $product, Struct\ShopContextInterface $context)
    {
        return $this->decorated->getConfiguratorMedia($product, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function getProductCombinations(Struct\BaseProduct $product)
    {
        if (func_num_args() === 2) {
            $currentSelection = func_get_arg(1);
            return $this->getProductCombinationsBySelection($product, $currentSelection);
        }

        return $this->decorated->getProductCombinations($product);
    }

    public function getProductCombinationsBySelection(Struct\BaseProduct $product, array $currentSelection)
    {
        $queryBuilder = $this->getQuery();
        $queryBuilder->select(
            'otherConfiguratorGroup.id AS groupId',
            'includedOption.id AS optionId'
        )
            // load additional groups from same configurator set
            ->innerJoin(
                'configuratorSet',
                's_article_configurator_set_group_relations',
                'otherGroupRelation',
                $queryBuilder->expr()->eq('otherGroupRelation.set_id', 'configuratorSet.id')
            )
            ->innerJoin(
                'otherGroupRelation',
                's_article_configurator_groups',
                'otherConfiguratorGroup',
                $queryBuilder->expr()->eq('otherGroupRelation.group_id', 'otherConfiguratorGroup.id')
            )
            // load options to additional groups from the same configurator set
            ->innerJoin(
                'configuratorOption',
                's_article_configurator_set_option_relations',
                'alternateOptionRelation',
                $queryBuilder->expr()->eq('alternateOptionRelation.option_id', 'configuratorOption.id')
            )
            ->innerJoin(
                'alternateOptionRelation',
                's_article_configurator_set_group_relations',
                'alternateGroupRelation',
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq('alternateGroupRelation.set_id', 'alternateOptionRelation.set_id'),
                    $queryBuilder->expr()->eq('alternateGroupRelation.group_id', 'configuratorOption.group_id')
                )
            )
            // load additional options from the same group except the selected one
            ->leftJoin(
                'configuratorOption',
                's_article_configurator_options',
                'alternateOption',
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq('alternateOption.group_id', 'configuratorOption.group_id'),
                    $queryBuilder->expr()->neq('alternateOption.id', 'configuratorOption.id')
                )
            )
            ->innerJoin(
                'alternateGroupRelation',
                's_article_configurator_set_option_relations',
                'veryAlternateOptionRelation',
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq('veryAlternateOptionRelation.set_id', 'alternateGroupRelation.set_id '),
                    $queryBuilder->expr()->eq('veryAlternateOptionRelation.option_id', 'alternateOption.id')
                )
            )
            // map alternative values to chosen values
            ->innerJoin(
                'groupRelation',
                's_article_configurator_set_group_relations',
                'includedGroupRelation',
                $queryBuilder->expr()->eq('includedGroupRelation.set_id', 'groupRelation.set_id')
            )
            ->innerJoin(
                'includedGroupRelation',
                's_article_configurator_set_option_relations',
                'includedOptionRelation',
                $queryBuilder->expr()->eq('includedGroupRelation.set_id', 'includedOptionRelation.set_id')
            )
            ->innerJoin(
                'includedOptionRelation',
                's_article_configurator_options',
                'includedOption',
                $queryBuilder->expr()->eq('includedOptionRelation.option_id', 'includedOption.id')
            )
            ->where(
                $queryBuilder->expr()->eq('products.id', ':productId'),
                $queryBuilder->expr()->eq('includedOption.group_id', 'otherConfiguratorGroup.id')
            )
            ->setParameter('productId', $product->getId())
            ->groupBy(
                'otherConfiguratorGroup.id',
                'includedOption.id'
            );

        if (!empty($currentSelection)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('configuratorOption.id', ':selection'),
                $queryBuilder->expr()->notIn('includedOption.id', ':selection')
            )
                ->setParameter('selection', $currentSelection, Connection::PARAM_INT_ARRAY);
        }

        $possibleSelections = [];
        $rows = $queryBuilder->execute()->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $alternation) {
            $groupId = (int)$alternation['groupId'];
            $alternateOptionId = (int)$alternation['optionId'];

            $possibleSelection = $currentSelection;
            $possibleSelection[$groupId] = $alternateOptionId;

            if ($this->selectionHasVariants($product, $possibleSelection)) {
                $possibleSelections[] = $possibleSelection;
            }
        }

        return $possibleSelections;
    }

    /**
     * @param Struct\BaseProduct $product
     * @param int[] $possibleSelection
     *
     * @return bool
     */
    protected function selectionHasVariants(Struct\BaseProduct $product, $possibleSelection)
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->from('s_articles_details', 'variants')
            ->select(
                'variants.id AS id',
                'COUNT(1) AS relationCount'
            )
            ->innerJoin(
                'variants',
                's_article_configurator_option_relations',
                'relation',
                $queryBuilder->expr()->eq('relation.article_id', 'variants.id')
            )
            ->where(
                $queryBuilder->expr()->eq('variants.articleID', ':productId'),
                $queryBuilder->expr()->in('relation.option_id', ':selection'),
                'variants.active'
            )
            ->groupBy('variants.id')
            ->having($queryBuilder->expr()->eq('relationCount', ':count'))
            ->setParameter('productId', $product->getId())
            ->setParameter('selection', $possibleSelection, Connection::PARAM_INT_ARRAY)
            ->setParameter('count', count($possibleSelection));

        if ($this->shopwareConfig->get('hideNoInstock')) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte(
                    'variants.laststock * variants.instock',
                    'variants.laststock * variants.minpurchase'
                )
            );
        }

        return $queryBuilder->execute()->fetchColumn() !== false;
    }

    /**
     * @return QueryBuilder
     */
    private function getQuery()
    {
        $query = $this->connection->createQueryBuilder();

        $query->from('s_article_configurator_sets', 'configuratorSet')
            ->innerJoin('configuratorSet', 's_articles', 'products', 'products.configurator_set_id = configuratorSet.id')
            ->innerJoin('configuratorSet', 's_article_configurator_set_group_relations', 'groupRelation', 'groupRelation.set_id = configuratorSet.id')
            ->innerJoin('groupRelation', 's_article_configurator_groups', 'configuratorGroup', 'configuratorGroup.id = groupRelation.group_id')
            ->innerJoin('configuratorSet', 's_article_configurator_set_option_relations', 'optionRelation', 'optionRelation.set_id = configuratorSet.id')
            ->innerJoin('optionRelation', 's_article_configurator_options', 'configuratorOption', 'configuratorOption.id = optionRelation.option_id AND configuratorOption.group_id = configuratorGroup.id')
            ->leftJoin('configuratorGroup', 's_article_configurator_groups_attributes', 'configuratorGroupAttribute','configuratorGroupAttribute.groupID = configuratorGroup.id')
            ->leftJoin('configuratorOption', 's_article_configurator_options_attributes', 'configuratorOptionAttribute','configuratorOptionAttribute.optionID = configuratorOption.id')
            ->addOrderBy('configuratorGroup.position')
            ->addOrderBy('configuratorGroup.name')
            ->addOrderBy('configuratorOption.position')
            ->addOrderBy('configuratorOption.name')
            ->groupBy('configuratorOption.id');

        return $query;
    }

}
