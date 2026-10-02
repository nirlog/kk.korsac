<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

use Bitrix\Main\Entity;

final class ConfigurationSnapshotTable extends Entity\DataManager
{
    public static function getTableName(): string { return 'b_kk_korsac_config_snapshot'; }

    public static function getMap(): array
    {
        return [
            (new Entity\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new Entity\StringField('SNAPSHOT_KEY'))->configureRequired()->configureSize(64),
            (new Entity\StringField('SNAPSHOT_HASH'))->configureRequired()->configureSize(64),
            (new Entity\IntegerField('SCHEMA_VERSION'))->configureRequired(),
            (new Entity\StringField('SITE_ID'))->configureRequired()->configureSize(10),
            (new Entity\IntegerField('IBLOCK_ID'))->configureRequired(),
            (new Entity\IntegerField('PRODUCT_ID'))->configureRequired(),
            (new Entity\IntegerField('PRICE_TYPE_ID'))->configureRequired(),
            (new Entity\StringField('CURRENCY'))->configureRequired()->configureSize(3),
            (new Entity\IntegerField('BASE_PRICE_MINOR'))->configureRequired(),
            (new Entity\IntegerField('CONFIGURATION_DELTA_MINOR'))->configureRequired(),
            (new Entity\IntegerField('FINAL_PRICE_MINOR'))->configureRequired(),
            (new Entity\TextField('PAYLOAD'))->configureRequired(),
            (new Entity\DatetimeField('CREATED_AT'))->configureRequired(),
        ];
    }
}
