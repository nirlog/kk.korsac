<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Cart\BasketPropertyProjector;
use KK\Korsac\Cart\BitrixBasketGateway;
use KK\Korsac\Cart\BitrixConfigurationSnapshotRepository;
use KK\Korsac\Cart\BitrixProductViewProvider;
use KK\Korsac\Cart\BitrixSiteResolver;
use KK\Korsac\Cart\ConfigurationSnapshotBuilder;
use KK\Korsac\Cart\ConfiguredProductCartService;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Configurator\BitrixCatalogPriceProvider;
use KK\Korsac\Configurator\ConfiguredProductPricingService;
use KK\Korsac\Configurator\HlOptionViewProvider;
use KK\Korsac\Pricing\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Repository\OptionRepository;

$args=getopt('',['iblock:','product:','selection-json::','confirm-write']);
if(!array_key_exists('confirm-write',$args)){fwrite(STDERR,"Refusing to mutate Basket without --confirm-write.\n");exit(2);}
$iblockId=filter_var($args['iblock']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$productId=filter_var($args['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
try{$selection=json_decode((string)($args['selection-json']??'{}'),true,512,JSON_THROW_ON_ERROR);if($iblockId===false||$productId===false||!is_array($selection)||(array_is_list($selection)&&$selection!==[]))throw new InvalidArgumentException('Invalid arguments');}catch(Throwable $e){fwrite(STDERR,"Usage: php cart_add_smoke.php --iblock=<ID> --product=<ID> --selection-json='<JSON>' --confirm-write\n");exit(2);}
$documentRoot=($_SERVER['DOCUMENT_ROOT']??'')?:dirname(__DIR__,5);$_SERVER['DOCUMENT_ROOT']=$documentRoot;require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
foreach(['iblock','highloadblock','catalog','sale','kk.korsac'] as $module)if(!Loader::includeModule($module)){fwrite(STDERR,"Required module {$module} is unavailable.\n");exit(2);}
try{$options=new OptionRepository();$pricing=new ConfiguredProductPricingService(new ProductConfigurationRepository(new BitrixCatalogPropertyGateway(),$options),new BitrixCatalogPriceProvider(),new HlOptionPriceProvider($options),new ConfiguredCatalogPriceTypeResolver(),new BitrixPricingPolicyProvider());$service=new ConfiguredProductCartService($pricing,new ConfigurationSnapshotBuilder(new HlOptionViewProvider($options)),new BitrixConfigurationSnapshotRepository(),new BitrixBasketGateway(),new BasketPropertyProjector(),new BitrixSiteResolver(),new BitrixProductViewProvider());$result=$service->add((int)$iblockId,(int)$productId,$selection);$stored=(new BitrixConfigurationSnapshotRepository())->findByKey($result['snapshot']['key']);if($stored===null)throw new RuntimeException('Stored snapshot was not found');$result['basketPropertyCodes']=array_column((new BasketPropertyProjector())->project($stored),'CODE');echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;}catch(Throwable $e){fwrite(STDERR,"Cart add smoke failed: {$e->getMessage()}\n");exit(1);}
