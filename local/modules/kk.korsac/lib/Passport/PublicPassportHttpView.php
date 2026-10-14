<?php

declare(strict_types=1);

namespace KK\Korsac\Passport;

use KK\Korsac\System\PublicPassportDto;

final readonly class PublicPassportHttpView
{
    private function __construct(public int $status, public string $state, public ?PublicPassportDto $passport) {}

    public static function active(PublicPassportDto $passport): self { return new self(200, 'ACTIVE', $passport); }
    public static function notFound(): self { return new self(404, 'NOT_FOUND', null); }
    public static function error(): self { return new self(500, 'ERROR', null); }

    /** The same policy applies to every public page state. */
    public static function headers(): array
    {
        return ['X-Robots-Tag'=>'noindex, nofollow, noarchive', 'Referrer-Policy'=>'no-referrer', 'Cache-Control'=>'no-store'];
    }
}
