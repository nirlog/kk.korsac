<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

final class ConfiguratorErrorMapper
{
    /** @return array{code:string,message:string,customData:array<string,scalar>} */
    public function map(array $diagnostic): array
    {
        $messages = [
            'product_not_found' => 'Product not found', 'catalog_base_price_not_found' => 'Catalog base price not found',
            'unsupported_catalog_currency' => 'Catalog currency is not supported', 'invalid_request' => 'Invalid request',
            'option_not_allowed' => 'Selected option is not allowed', 'unknown_configuration_group' => 'Unknown configuration group',
            'invalid_single_selection' => 'Invalid single selection', 'invalid_multiple_selection' => 'Invalid multiple selection',
            'duplicate_selected_option' => 'Duplicate selected option', 'null_not_allowed' => 'Null selection is not allowed',
            'price_option_not_found' => 'Option price not found', 'invalid_option_price' => 'Invalid option price',
            'negative_option_price' => 'Negative option price', 'price_overflow' => 'Price overflow',
            'negative_final_price' => 'Negative final price', 'internal_error' => 'Internal error',
        ];
        $code = (string)($diagnostic['code'] ?? 'internal_error');
        if (!isset($messages[$code])) { $code = 'internal_error'; }
        $customData = [];
        foreach (['group', 'xmlId', 'currency'] as $key) {
            if (isset($diagnostic[$key]) && is_scalar($diagnostic[$key])) { $customData[$key] = $diagnostic[$key]; }
        }
        return ['code' => $code, 'message' => $messages[$code], 'customData' => $customData];
    }
}
