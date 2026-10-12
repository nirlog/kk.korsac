<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Throwable;
final class BitrixOrderProjectionAdapter implements OrderProjectionAdapterInterface
{
    public function project(object $order): OrderProjection
    {
        try {
            $lines=[];
            foreach ($order->getBasket() as $item) {
                $properties=[];
                foreach ($item->getPropertyCollection() as $property) $properties[(string)$property->getField('CODE')][]=$property->getField('VALUE');
                $lines[]=new OrderBasketLine((int)$item->getId(),(string)$item->getField('MODULE'),(int)$item->getProductId(),(string)$item->getField('NAME'),$item->getQuantity(),$properties);
            }
            return new OrderProjection((int)$order->getId(),(string)$order->getSiteId(),(string)$order->getField('STATUS_ID'),$lines);
        } catch (Throwable) { throw new SystemException('system_order_projection_failed'); }
    }
}
