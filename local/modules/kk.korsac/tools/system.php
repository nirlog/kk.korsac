<?php

declare(strict_types=1);

// Operator commands, including private ownership reads, must never be HTTP APIs.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

use Bitrix\Main\Loader;
use Bitrix\Sale\Order;
use KK\Korsac\System\BitrixOrderProjectionAdapter;
use KK\Korsac\System\BitrixSystemRepository;
use KK\Korsac\System\BitrixProductionStatusGateway;
use KK\Korsac\System\BitrixSystemSettingsGateway;
use KK\Korsac\System\ProductionStatusLabels;
use KK\Korsac\System\SystemServiceFactory;
use KK\Korsac\System\SystemException;
use KK\Korsac\Install\PassportDeliveryFactory;

$documentRoot=($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__,4);
$_SERVER['DOCUMENT_ROOT']=$documentRoot;
if (!is_file($documentRoot.'/bitrix/modules/main/include/prolog_before.php')) { fwrite(STDERR,"{\"ok\":false,\"code\":\"bitrix_environment_required\"}\n"); exit(2); }
require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
try {
    $command=$argv[1] ?? '';
    $passportCommand=in_array($command,['passport-prepare','passport-show','passport-activate','passport-public','passport-delivery','ownership-show','ownership-history'],true);
    if (!Loader::includeModule('kk.korsac') || (!$passportCommand && !Loader::includeModule('sale'))) throw new SystemException('system_sale_required'); $action=$argv[2] ?? ''; $options=[];
    foreach (array_slice($argv,in_array($command,['sale-config','status-labels','status-label','passport-delivery'],true) ? 3 : 2) as $argument) {
        if (!preg_match('/\A--([a-z-]+)=(.*)\z/D',$argument,$match) || isset($options[$match[1]])) throw new SystemException('system_cli_arguments_invalid');
        $options[$match[1]]=$match[2];
    }
    $allowed=match ($command) {
        'sale-config'=>$action==='set' ? ['status'] : [], 'inspect-order','sync-order'=>['order'],
        'passport-prepare','passport-show'=>['system'], 'passport-activate'=>['system','transferred-at'], 'passport-public'=>['public-id'],
        'passport-delivery'=>['site'],
        'ownership-show','ownership-history'=>['system'],
        'status-set'=>['system','status'], 'status-show'=>['system'], 'status-label'=>['status','label'], 'status-labels'=>[],
        default=>throw new SystemException('system_cli_arguments_invalid'),
    };
    if (array_diff(array_keys($options),$allowed)) throw new SystemException('system_cli_arguments_invalid');
    $required=static function(string $key) use ($options):string { if (!isset($options[$key]) || $options[$key]==='') throw new SystemException('system_cli_arguments_invalid'); return $options[$key]; };
    $configuration=SystemServiceFactory::configuration();
    if (in_array($command,['ownership-show','ownership-history'],true)) {
        $humanId=$required('system');
        if (!preg_match('/\AKS-[0-9]{2}(?:0[1-9]|1[0-2])-[0-9]{4}\z/D',$humanId)) throw new SystemException('system_source_invalid');
        $system=(new BitrixSystemRepository())->findBySystemId($humanId);
        if ($system===null) throw new SystemException('system_not_found');
        $reads=SystemServiceFactory::ownershipReads();
        $result=$command==='ownership-show' ? $reads->current($system->id)->toArray() : ['systemId'=>$humanId,'history'=>array_map(static fn(\KK\Korsac\Ownership\OwnershipHistoryRecord $record):array=>$record->toArray(),$reads->history($system->id))];
    } elseif ($command==='passport-delivery') {
        if (!in_array($action,['install','check'],true)) throw new SystemException('system_cli_arguments_invalid');
        $delivery=PassportDeliveryFactory::create($options['site'] ?? null);
        $result=$action==='install' ? $delivery->install() : $delivery->check();
    } elseif ($command==='passport-public') {
        $result=SystemServiceFactory::publicPassports()->find($required('public-id'))->toArray();
    } elseif (in_array($command,['passport-prepare','passport-show','passport-activate'],true)) {
        $humanId=$required('system');
        if (!preg_match('/\AKS-[0-9]{2}(?:0[1-9]|1[0-2])-[0-9]{4}\z/D',$humanId)) throw new SystemException('system_source_invalid');
        $system=(new BitrixSystemRepository())->findBySystemId($humanId);
        if ($system===null) throw new SystemException('system_not_found');
        if ($command==='passport-prepare') {
            $preparation=SystemServiceFactory::passports()->preparePassport($system->id);
            // The explicit credential-issuing command is the only CLI path allowed to return this once.
            $result=['created'=>$preparation->created,'systemId'=>$humanId,'publicId'=>$preparation->passport->publicId,'state'=>$preparation->passport->state,'secretCode'=>$preparation->secretCode];
        } elseif ($command==='passport-activate') {
            $transfer=isset($options['transferred-at']) ? \KK\Korsac\System\PassportTimestamp::fromIso8601($options['transferred-at']) : null;
            $result=\KK\Korsac\System\PassportInternalView::of($humanId,SystemServiceFactory::passports()->activatePassport($system->id,$transfer));
        } else $result=\KK\Korsac\System\PassportInternalView::of($humanId,(new \KK\Korsac\System\BitrixPassportRepository())->findBySystem($system->id));
    } elseif ($command==='sale-config') {
        $settings=match ($action) { 'show'=>$configuration->read(), 'set'=>$configuration->configure($required('status')), 'disable'=>$configuration->disable(), default=>throw new SystemException('system_cli_arguments_invalid') };
        $result=['enabled'=>$settings->enabled,'triggerStatusId'=>$settings->triggerStatusId];
    } elseif (in_array($command,['inspect-order','sync-order'],true)) {
        $orderId=$required('order');
        if (!ctype_digit($orderId) || (int)$orderId<1 || (int)$orderId>2147483647) throw new SystemException('system_order_source_invalid');
        $order=Order::load((int)$orderId);
        if ($order===null) throw new SystemException('system_order_not_found');
        $projection=(new BitrixOrderProjectionAdapter())->project($order);
        $service=SystemServiceFactory::synchronizer();
        $result=$command==='inspect-order' ? $service->inspect($projection) : $service->synchronize($projection)->toArray();
    } elseif ($command==='status-labels' && $action==='show') {
        $result=(new ProductionStatusLabels(new BitrixSystemSettingsGateway()))->all();
    } elseif ($command==='status-label' && $action==='set') {
        $labels=new ProductionStatusLabels(new BitrixSystemSettingsGateway()); $labels->set($required('status'),$required('label')); $result=$labels->all();
    } elseif (in_array($command,['status-set','status-show'],true)) {
        $humanId=$required('system');
        if (!preg_match('/\AKS-[0-9]{2}(?:0[1-9]|1[0-2])-[0-9]{4}\z/D',$humanId)) throw new SystemException('system_source_invalid');
        $system=(new BitrixSystemRepository())->findBySystemId($humanId);
        if ($system===null) throw new SystemException('system_not_found');
        $state=$command==='status-set' ? SystemServiceFactory::production()->setStatus($system->id,$required('status'),'MANUAL') : (new BitrixProductionStatusGateway())->findCurrent($system->id);
        $result=['systemId'=>$humanId,'production'=>$state===null ? null : ['statusCode'=>$state->statusCode,'revision'=>$state->revision,'updatedAt'=>$state->updatedAt->format(DATE_ATOM)]];
    } else throw new SystemException('system_cli_arguments_invalid');
    $ok=$command!=='passport-delivery' || $result['ok'];
    echo json_encode(['ok'=>$ok,'result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
    if (!$ok) exit(1);
} catch (Throwable $error) {
    fwrite(STDERR,json_encode(['ok'=>false,'code'=>$error instanceof SystemException ? $error->machineCode : 'system_cli_failed']).PHP_EOL); exit(1);
}
