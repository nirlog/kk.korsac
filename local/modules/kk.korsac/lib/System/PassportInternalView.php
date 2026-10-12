<?php

declare(strict_types=1);

namespace KK\Korsac\System;
/** Explicit safe operational view; no internal relation, hash, Order data or components. */
final class PassportInternalView
{
    public static function of(string $systemId,?PassportRecord $passport): array
    {
        return ['systemId'=>$systemId,'publicId'=>$passport?->publicId,'state'=>$passport?->state,'preparedAt'=>$passport?->preparedAt->format(DATE_ATOM),'transferredAt'=>$passport?->transferredAt?->format(DATE_ATOM),'warrantyStartedAt'=>$passport?->warrantyStartedAt?->format(DATE_ATOM)];
    }
}
