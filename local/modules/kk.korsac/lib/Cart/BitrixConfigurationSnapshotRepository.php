<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

use Bitrix\Main\Type\DateTime;

final class BitrixConfigurationSnapshotRepository implements ConfigurationSnapshotRepositoryInterface
{
    public function create(ConfigurationSnapshot $snapshot): ConfigurationSnapshotReference
    {
        $result = ConfigurationSnapshotTable::add([
            'SNAPSHOT_KEY'=>$snapshot->key, 'SNAPSHOT_HASH'=>$snapshot->hash, 'SCHEMA_VERSION'=>ConfigurationSnapshot::SCHEMA_VERSION,
            'SITE_ID'=>$snapshot->siteId, 'IBLOCK_ID'=>$snapshot->iblockId, 'PRODUCT_ID'=>$snapshot->productId,
            'PRICE_TYPE_ID'=>$snapshot->priceTypeId, 'CURRENCY'=>$snapshot->currency,
            'BASE_PRICE_MINOR'=>$snapshot->basePriceMinor, 'CONFIGURATION_DELTA_MINOR'=>$snapshot->configurationDeltaMinor,
            'FINAL_PRICE_MINOR'=>$snapshot->finalPriceMinor, 'PAYLOAD'=>$snapshot->payload, 'CREATED_AT'=>new DateTime(),
        ]);
        if (!$result->isSuccess()) { throw new CartException(['code'=>'snapshot_persist_failed']); }
        return new ConfigurationSnapshotReference((int)$result->getId(), $snapshot->key);
    }

    public function findByKey(string $snapshotKey): ?ConfigurationSnapshot
    {
        $row = ConfigurationSnapshotTable::getList(['filter'=>['=SNAPSHOT_KEY'=>$snapshotKey], 'limit'=>1])->fetch();
        if (!$row) return null;
        $payload = json_decode((string)$row['PAYLOAD'], true, 512, JSON_THROW_ON_ERROR);
        return new ConfigurationSnapshot((string)$row['SNAPSHOT_KEY'],(string)$row['SNAPSHOT_HASH'],(string)$row['SITE_ID'],(int)$row['IBLOCK_ID'],(int)$row['PRODUCT_ID'],(int)$row['PRICE_TYPE_ID'],(string)$row['CURRENCY'],(int)$row['BASE_PRICE_MINOR'],(int)$row['CONFIGURATION_DELTA_MINOR'],(int)$row['FINAL_PRICE_MINOR'],(string)$row['PAYLOAD'],$payload['display']);
    }

    public function deleteUnattached(ConfigurationSnapshotReference $reference): void
    {
        $row = ConfigurationSnapshotTable::getByPrimary($reference->id, ['select'=>['SNAPSHOT_KEY']])->fetch();
        if ($row && hash_equals($reference->key, (string)$row['SNAPSHOT_KEY'])) ConfigurationSnapshotTable::delete($reference->id);
    }
}
