<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
final class CartErrorMapper
{
    private const MESSAGES = [
        'invalid_request'=>'Invalid request','product_not_found'=>'Product was not found','sale_module_not_available'=>'Sale module is not available',
        'site_not_available'=>'Site is not available','basket_unavailable'=>'Basket is not available','snapshot_persist_failed'=>'Configuration snapshot could not be stored',
        'basket_save_failed'=>'Basket item could not be stored','unsupported_currency'=>'Currency is not supported','option_not_allowed'=>'Selected option is not allowed',
        'unknown_configuration_group'=>'Unknown configuration group','invalid_single_selection'=>'Invalid single selection','invalid_multiple_selection'=>'Invalid multiple selection',
        'duplicate_selected_option'=>'Duplicate selected option','null_not_allowed'=>'Empty selection is not allowed','catalog_price_type_not_configured'=>'Catalog price type is not configured',
        'pricing_policy_not_configured'=>'Pricing policy is not configured','catalog_price_not_found'=>'Catalog price was not found','internal_error'=>'Internal error',
    ];
    public function map(array $diagnostic): array
    {
        $code = (string)($diagnostic['code'] ?? 'internal_error');
        if (!isset(self::MESSAGES[$code])) $code = 'internal_error';
        $custom = [];
        if (in_array($code, ['option_not_allowed','unknown_configuration_group','invalid_single_selection','invalid_multiple_selection','duplicate_selected_option','null_not_allowed'], true)) {
            foreach (['group','xmlId'] as $key) if (isset($diagnostic[$key]) && is_string($diagnostic[$key])) $custom[$key]=$diagnostic[$key];
        }
        return ['code'=>$code,'message'=>self::MESSAGES[$code],'customData'=>$custom];
    }
}
