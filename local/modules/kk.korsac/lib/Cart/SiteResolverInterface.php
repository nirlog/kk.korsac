<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
interface SiteResolverInterface { public function currentSiteId(): string; }
