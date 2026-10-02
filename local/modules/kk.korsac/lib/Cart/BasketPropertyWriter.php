<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

/** Isolates Bitrix's void-returning BasketPropertiesCollectionBase::setProperty(). */
final class BasketPropertyWriter
{
    public function write(object $collection, array $properties): void
    {
        $collection->setProperty($properties);
    }
}
