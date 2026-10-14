<?php

declare(strict_types=1);

namespace KK\Korsac\Passport;

use Closure;
use KK\Korsac\System\SystemException;
use Throwable;

final class PublicPassportHttpPresenter
{
    /** @param Closure(string): \KK\Korsac\System\PublicPassportDto $lookup Existing public query boundary only. */
    public function __construct(private readonly Closure $lookup) {}

    public function present(mixed $publicId): PublicPassportHttpView
    {
        try {
            return PublicPassportHttpView::active(($this->lookup)(is_string($publicId) ? $publicId : ''));
        } catch (SystemException $error) {
            return $error->machineCode==='system_passport_not_found' ? PublicPassportHttpView::notFound() : PublicPassportHttpView::error();
        } catch (Throwable) {
            return PublicPassportHttpView::error();
        }
    }
}
