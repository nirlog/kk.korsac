<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

final class ProductPresentationSchema
{
    public const DEFAULT_MODE = 'select';
    public const LABELS = [
        'select' => 'Выпадающий список',
        'text_buttons' => 'Кнопки с текстом',
        'image_buttons' => 'Кнопки с изображением',
        'checkboxes' => 'Чекбоксы',
    ];

    public static function allowedModes(string $group): array
    {
        $definition = CatalogPropertySchema::groups()[$group] ?? null;
        if ($definition === null) { return []; }
        $modes = ['select', 'text_buttons', 'image_buttons'];
        if ($definition['mode'] === CatalogPropertySchema::MODE_MULTIPLE) { $modes[] = 'checkboxes'; }
        return $modes;
    }

    public static function propertyCode(string $group): string { return "KK_{$group}_VIEW"; }

    public static function properties(): array
    {
        $result = [];
        foreach (CatalogPropertySchema::groups() as $group => $_) {
            $values = [];
            foreach (self::allowedModes($group) as $sort => $mode) {
                $values[] = ['XML_ID'=>$mode, 'VALUE'=>self::LABELS[$mode], 'DEF'=>$mode === self::DEFAULT_MODE ? 'Y' : 'N', 'SORT'=>($sort + 1) * 100];
            }
            $code = self::propertyCode($group);
            $result[$code] = ['CODE'=>$code, 'NAME'=>"KORSAC {$group} VIEW", 'PROPERTY_TYPE'=>'L', 'USER_TYPE'=>'', 'MULTIPLE'=>'N', 'IS_REQUIRED'=>'N', 'SORT'=>600, 'VALUES'=>$values];
        }
        return $result;
    }
}
