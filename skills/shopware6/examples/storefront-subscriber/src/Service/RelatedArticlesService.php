<?php declare(strict_types=1);

namespace VendorName\MyPlugin\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Content\Product\ProductCollection;

class RelatedArticlesService
{
    private const LIMIT = 4;

    public function __construct(
        private readonly SalesChannelRepository $productRepository,
    ) {
    }

    public function findRelated(string $productId, SalesChannelContext $context): ProductCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('id', $productId),
        ]));
        $criteria->addAssociation('cover.media');
        $criteria->setLimit(self::LIMIT);

        // Sales channel repository applies visibility, availability and price context automatically
        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        return $products;
    }
}
