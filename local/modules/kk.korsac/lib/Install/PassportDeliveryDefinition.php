<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

final class PassportDeliveryDefinition
{
    public const OWNER='kk.korsac:public-passport-delivery:v1';

    /** Destination relative to document root => canonical module source. */
    public static function files(): array
    {
        $component='install/components/kk/korsac.passport/';
        $destination='local/components/kk/korsac.passport/';
        return [
            'passport/index.php'=>'install/public/passport/index.php',
            $destination.'.description.php'=>$component.'.description.php',
            $destination.'component.php'=>$component.'component.php',
            $destination.'templates/.default/template.php'=>$component.'templates/.default/template.php',
            $destination.'templates/.default/style.css'=>$component.'templates/.default/style.css',
        ];
    }
}
