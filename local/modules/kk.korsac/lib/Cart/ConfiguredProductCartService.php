<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
use KK\Korsac\Configurator\ConfiguredProductPricingServiceInterface;
use Throwable;
final class ConfiguredProductCartService
{
    public function __construct(
        private readonly ConfiguredProductPricingServiceInterface $pricing,
        private readonly ConfigurationSnapshotBuilder $snapshots,
        private readonly ConfigurationSnapshotRepositoryInterface $repository,
        private readonly BasketGatewayInterface $basket,
        private readonly BasketPropertyProjector $properties,
        private readonly SiteResolverInterface $sites,
        private readonly ProductViewProviderInterface $products,
    ) {}
    public function add(int $iblockId, int $productId, array $selection): array
    {
        $quote=$this->pricing->quote($iblockId,$productId,$selection);
        if ($quote->currency !== 'RUB') throw new CartException(['code'=>'unsupported_currency']);
        $siteId=$this->sites->currentSiteId();
        $snapshot=$this->snapshots->build($quote,$siteId);
        try { $reference=$this->repository->create($snapshot); }
        catch (CartException $error) { throw $error; }
        catch (Throwable) { throw new CartException(['code'=>'snapshot_persist_failed']); }
        try {
            $result=$this->basket->addConfiguredProduct($quote,$siteId,$this->products->name($iblockId,$productId),$this->properties->project($snapshot));
        } catch (Throwable $error) {
            try { $this->repository->deleteUnattached($reference); } catch (Throwable) {}
            if ($error instanceof CartException) throw $error;
            throw new CartException(['code'=>'basket_save_failed']);
        }
        return [
            'basket'=>['basketItemId'=>$result->basketItemId,'productId'=>$productId,'quantity'=>1],
            'snapshot'=>['key'=>$snapshot->key,'hash'=>$snapshot->hash,'schemaVersion'=>ConfigurationSnapshot::SCHEMA_VERSION],
            'selection'=>$quote->selection->toArray(),
            'price'=>array_diff_key($quote->publicPrice(),['groupDeltas'=>true]),
        ];
    }
}
