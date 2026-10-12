<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface SaleSynchronizationLoggerInterface { public function log(int $orderId,string $code): void; }
