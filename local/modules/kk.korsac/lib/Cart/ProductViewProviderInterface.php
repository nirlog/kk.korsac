<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
interface ProductViewProviderInterface { public function name(int $iblockId, int $productId): string; }
