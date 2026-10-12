<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface OrderProjectionAdapterInterface { public function project(object $order): OrderProjection; }
