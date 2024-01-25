<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Processor\Category;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Synolia\SyliusAkeneoPlugin\Builder\TaxonAttribute\TaxonAttributeValueBuilder;
use Synolia\SyliusAkeneoPlugin\Component\TaxonAttribute\Model\TaxonAttributeSubjectInterface;
use Synolia\SyliusAkeneoPlugin\Entity\TaxonAttribute;
use Synolia\SyliusAkeneoPlugin\Entity\TaxonAttributeInterface;
use Synolia\SyliusAkeneoPlugin\Entity\TaxonAttributeValueInterface;
use Synolia\SyliusAkeneoPlugin\Exceptions\UnsupportedAttributeTypeException;
use Synolia\SyliusAkeneoPlugin\Provider\SyliusAkeneoLocaleCodeProvider;
use Synolia\SyliusAkeneoPlugin\TypeMatcher\TaxonAttribute\TaxonAttributeTypeMatcher;
use Webmozart\Assert\Assert;

class AttributeProcessor implements CategoryProcessorInterface
{
    private array $taxonAttributes = [];

    public static function getDefaultPriority(): int
    {
        return 700;
    }

    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private RepositoryInterface $taxonAttributeRepository,
        private RepositoryInterface $taxonAttributeValueRepository,
        private FactoryInterface $taxonAttributeFactory,
        private FactoryInterface $taxonAttributeValueFactory,
        private TaxonAttributeTypeMatcher $taxonAttributeTypeMatcher,
        private TaxonAttributeValueBuilder $taxonAttributeValueBuilder,
        private SyliusAkeneoLocaleCodeProvider $syliusAkeneoLocaleCodeProvider,
    ) {
    }

    public function process(TaxonInterface $taxon, array $resource): void
    {
        foreach ($resource['values'] as $attributeValue) {
            try {
                $taxonAttribute = $this->getTaxonAttributes(
                    $attributeValue['attribute_code'],
                    $attributeValue['type'],
                );

                $value = $this->taxonAttributeValueBuilder->build(
                    $attributeValue['attribute_code'],
                    $attributeValue['type'],
                    $attributeValue['locale'],
                    $attributeValue['channel'],
                    $attributeValue['data'],
                );

                $this->getTaxonAttributeValues(
                    $attributeValue,
                    $taxon,
                    $taxonAttribute,
                    $value,
                );
            } catch (UnsupportedAttributeTypeException $e) {
                $this->logger->warning($e->getMessage(), [
                    'trace' => $e->getTrace(),
                    'exception' => $e,
                ]);
            }
        }
    }

    public function support(TaxonInterface $taxon, array $resource): bool
    {
        return $taxon instanceof TaxonAttributeSubjectInterface && array_key_exists('values', $resource);
    }

    private function getTaxonAttributes(string $attributeCode, string $type): TaxonAttributeInterface
    {
        if (array_key_exists($attributeCode, $this->taxonAttributes)) {
            return $this->taxonAttributes[$attributeCode];
        }

        $taxonAttribute = $this->taxonAttributeRepository->findOneBy(['code' => $attributeCode]);

        if ($taxonAttribute instanceof TaxonAttribute) {
            $this->taxonAttributes[$attributeCode] = $taxonAttribute;

            return $taxonAttribute;
        }

        $matcher = $this->taxonAttributeTypeMatcher->match($type);

        /** @var TaxonAttributeInterface $taxonAttribute */
        $taxonAttribute = $this->taxonAttributeFactory->createNew();
        $taxonAttribute->setCode($attributeCode);
        $taxonAttribute->setType($type);
        $taxonAttribute->setStorageType($matcher->getAttributeType()->getStorageType());
        $taxonAttribute->setTranslatable(false);

        $this->entityManager->persist($taxonAttribute);
        $this->taxonAttributes[$attributeCode] = $taxonAttribute;

        return $taxonAttribute;
    }

    private function getTaxonAttributeValues(
        array $attributeValue,
        TaxonInterface $taxon,
        TaxonAttributeInterface $taxonAttribute,
        mixed $value,
    ): void {
        Assert::string($taxon->getCode());
        Assert::string($taxonAttribute->getCode());

        if (null === $attributeValue['locale']) {
            $this->handleValue($taxon, $taxonAttribute, null, $value);

            return;
        }

        foreach ($this->syliusAkeneoLocaleCodeProvider->getSyliusLocales($attributeValue['locale']) as $syliusLocale) {
            $this->handleValue($taxon, $taxonAttribute, $syliusLocale, $value);
        }
    }

    private function handleValue(
        TaxonInterface $taxon,
        TaxonAttributeInterface $taxonAttribute,
        ?string $locale,
        mixed $value,
    ): void {
        $taxonAttributeValue = $this->taxonAttributeValueRepository->findOneBy([
            'subject' => $taxon,
            'attribute' => $taxonAttribute,
            'localeCode' => $locale,
        ]);

        if ($taxonAttributeValue instanceof TaxonAttributeValueInterface) {
            $taxonAttributeValue->setValue($value);

            return;
        }

        /** @var TaxonAttributeValueInterface $taxonAttributeValue */
        $taxonAttributeValue = $this->taxonAttributeValueFactory->createNew();
        $taxonAttributeValue->setAttribute($taxonAttribute);
        $taxonAttributeValue->setTaxon($taxon);
        $taxonAttributeValue->setLocaleCode($locale);
        $taxonAttributeValue->setValue($value);

        $this->entityManager->persist($taxonAttributeValue);
    }
}
