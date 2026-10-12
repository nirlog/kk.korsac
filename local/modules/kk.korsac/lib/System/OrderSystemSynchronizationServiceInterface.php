<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface OrderSystemSynchronizationServiceInterface { public function synchronize(OrderProjection $order): OrderSynchronizationResult; }
