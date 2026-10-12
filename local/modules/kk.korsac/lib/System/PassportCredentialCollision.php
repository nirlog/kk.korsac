<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use RuntimeException;
/** Internal retry signal, with no driver message, token or hash attached. */
final class PassportCredentialCollision extends RuntimeException
{
    public function __construct() { parent::__construct('system_passport_token_collision'); }
}
