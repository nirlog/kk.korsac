<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use KK\Korsac\Cart\ConfigurationSnapshot;
use KK\Korsac\Cart\ConfigurationSnapshotRepositoryInterface;
use Throwable;
final class OrderSystemSynchronizationService implements OrderSystemSynchronizationServiceInterface
{
    public const MAX_UNITS_PER_LINE=1000;
    public function __construct(
        private readonly SaleAutomationConfiguration $configuration,
        private readonly SystemRepositoryInterface $systems,
        private readonly SystemCreationService $creation,
        private readonly ConfigurationSnapshotRepositoryInterface $snapshots,
        private readonly ProductionStatusService $production,
    ) {}
    public function synchronize(OrderProjection $order): OrderSynchronizationResult
    {
        try {
            $this->validateOrder($order);
            $settings=$this->configuration->read();
            // Manual sync works while automatic execution is disabled, but never bypasses the trigger.
            if ($settings->triggerStatusId==='' || $order->statusId!==$settings->triggerStatusId) throw new SystemException('system_order_trigger_mismatch');
            $requests=$this->preflight($order);
            $ids=[]; $created=0;
            foreach ($requests as [$input,$quantity]) for ($unit=1;$unit<=$quantity;++$unit) {
                $outcome=$this->creation->createWithResult($input+['ORDER_UNIT_INDEX'=>$unit]);
                $this->production->ensureInitial($outcome->system->id);
                $ids[]=$outcome->system->systemId; $created+=(int)$outcome->created;
            }
            return new OrderSynchronizationResult($order->orderId,count($requests),count($ids),count($ids)-$created,$created,$ids);
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_order_sync_failed'); }
    }
    /** Read-only, no settings-trigger requirement: inspect the exact validation plan safely. */
    public function inspect(OrderProjection $order): array
    {
        try {
            $requests=$this->preflight($order); $lines=[];
            foreach ($requests as [$input,$quantity]) $lines[]=['basketId'=>$input['ORDER_BASKET_ID'],'productId'=>$input['PRODUCT_ID'],'quantity'=>$quantity];
            return ['orderId'=>$order->orderId,'siteId'=>$order->siteId,'statusId'=>$order->statusId,'eligibleLineCount'=>count($lines),'physicalUnitCount'=>array_sum(array_column($lines,'quantity')),'lines'=>$lines];
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_order_inspection_failed'); }
    }
    private function preflight(OrderProjection $order): array
    {
        $this->validateOrder($order);
        $requests=[]; $seen=[];
        foreach ($order->lines as $line) {
            if (!$line instanceof OrderBasketLine || $line->basketId<1 || $line->basketId>2147483647 || isset($seen[$line->basketId])) throw new SystemException('system_order_source_invalid');
            $seen[$line->basketId]=true;
            if (!$line->isConfiguredPc()) continue;
            if (($line->properties['KORSAC_CONFIGURED'] ?? [])!==['Y']) throw new SystemException('system_order_metadata_invalid');
            $quantity=self::quantity($line->quantity);
            $key=$this->property($line,'KORSAC_SNAPSHOT_KEY'); $hash=$this->property($line,'KORSAC_SNAPSHOT_HASH'); $version=$this->property($line,'KORSAC_SNAPSHOT_VERSION');
            if (!preg_match('/\A[a-f0-9]{64}\z/D',$key) || !preg_match('/\A[a-f0-9]{64}\z/D',$hash)) throw new SystemException('system_order_metadata_invalid');
            if ($version!==(string)ConfigurationSnapshot::SCHEMA_VERSION) throw new SystemException('system_order_snapshot_version_mismatch');
            $snapshot=$this->snapshots->findByKey($key);
            if ($snapshot===null) throw new SystemException('system_snapshot_not_found');
            if ($snapshot->schemaVersion!==ConfigurationSnapshot::SCHEMA_VERSION) throw new SystemException('system_order_snapshot_version_mismatch');
            if (!hash_equals($snapshot->hash,$hash)) throw new SystemException('system_order_snapshot_hash_mismatch');
            if ($snapshot->key!==$key || $snapshot->siteId!==$order->siteId || $snapshot->productId!==$line->productId) throw new SystemException('system_snapshot_mismatch');
            $input=['SITE_ID'=>$order->siteId,'ORDER_ID'=>$order->orderId,'ORDER_BASKET_ID'=>$line->basketId,'SYSTEM_TYPE'=>'MODEL','MODEL_NAME'=>$line->name,'IBLOCK_ID'=>$snapshot->iblockId,'PRODUCT_ID'=>$snapshot->productId,'CONFIG_SNAPSHOT_KEY'=>$key];
            SystemCreationRequest::fromArray($input+['ORDER_UNIT_INDEX'=>1]);
            $requests[$line->basketId]=[$input,$quantity];
        }
        foreach ($this->systems->findByOrder($order->orderId) as $system) {
            $source=$system->source; $request=$requests[$source->orderBasketId] ?? null;
            if ($request===null || $source->orderUnitIndex<1 || $source->orderUnitIndex>$request[1] || $source->siteId!==$order->siteId || $source->snapshotKey!==$request[0]['CONFIG_SNAPSHOT_KEY'] || $source->iblockId!==$request[0]['IBLOCK_ID'] || $source->productId!==$request[0]['PRODUCT_ID'] || $source->systemType!=='MODEL') throw new SystemException('system_order_unit_conflict');
        }
        return $requests;
    }
    private function validateOrder(OrderProjection $order): void
    {
        if ($order->orderId<1 || $order->orderId>2147483647 || !preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$order->statusId)) throw new SystemException('system_order_source_invalid');
        // Reuse source validation for bounded UTF-8 site and avoid duplicating its integer/text rules.
        SystemCreationRequest::fromArray(['SITE_ID'=>$order->siteId,'ORDER_ID'=>$order->orderId,'ORDER_BASKET_ID'=>1,'ORDER_UNIT_INDEX'=>1,'SYSTEM_TYPE'=>'MODEL','MODEL_NAME'=>'preflight']);
    }
    private function property(OrderBasketLine $line,string $code): string
    {
        $values=$line->properties[$code] ?? [];
        if (!is_array($values) || !array_is_list($values) || count($values)!==1 || (!is_string($values[0]) && !is_int($values[0]))) throw new SystemException('system_order_metadata_invalid');
        return (string)$values[0];
    }
    public static function quantity(mixed $value): int
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || (is_string($value) && !is_numeric($value))) throw new SystemException('system_order_quantity_invalid');
        $number=(float)$value;
        if (!is_finite($number) || $number<1 || $number>self::MAX_UNITS_PER_LINE || floor($number)!==$number) throw new SystemException('system_order_quantity_invalid');
        return (int)$number;
    }
}
