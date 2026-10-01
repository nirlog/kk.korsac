<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
use RuntimeException;
final class CartException extends RuntimeException
{
    public function __construct(private readonly array $details) { parent::__construct((string)($details['code'] ?? 'internal_error')); }
    public function diagnostic(): array { return $this->details; }
}
