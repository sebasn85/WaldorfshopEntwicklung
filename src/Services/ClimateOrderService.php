<?php
namespace WaldorfshopSeoTest\Services;

use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\Log\Loggable;

/** Tags the persisted sales order from the opted-in current storefront basket. */
class ClimateOrderService
{
    use Loggable;

    const DIAGNOSTIC_VERSION = '0.5.27';
    const DIAGNOSTIC_KEY = 'WaldorfshopSeoTest.climateOrderTrace.v1';

    public function handle(OrderCreated $event): void
    {
        $order = $event->getOrder();
        $orderId = $order ? (int)$order->id : 0;
        $this->trace('entered', ['orderId'=>$orderId, 'typeId'=>$order ? (int)$order->typeId : 0]);
        if (!$order || $orderId <= 0 || (int)$order->typeId !== 1) {
            $this->trace('invalid_order', ['orderId'=>$orderId]);
            return;
        }
        $basketId = 0;
        try {
            $session = pluginApp(SessionStorageRepositoryContract::class);
            $consent = $session->getSessionValue(ClimateContributionService::CONSENT_KEY);
            if (!is_array($consent)) {
                $this->trace('consent_missing', ['orderId'=>$orderId]);
                return;
            }
            if (($consent['accepted'] ?? false) !== true) {
                $this->trace('consent_not_accepted', ['orderId'=>$orderId]);
                return;
            }
            if (!empty($consent['taggedOrderId'])) {
                $this->trace('consent_consumed', ['orderId'=>$orderId]);
                return;
            }
            if (!pluginApp(ClimateContributionService::class)->enabled()) {
                $this->trace('disabled', ['orderId'=>$orderId]);
                return;
            }
            $basket = pluginApp(BasketRepositoryContract::class)->load();
            $basketId = $basket ? (int)$basket->id : 0;
            $context = ['orderId'=>$orderId, 'basketId'=>$basketId,
                'consentBasketId'=>(int)($consent['basketId'] ?? 0)];
            if ($basketId <= 0) {
                $this->trace('basket_missing', $context);
                return;
            }
            if ($basketId !== (int)($consent['basketId'] ?? 0)) {
                $this->trace('basket_mismatch', $context);
                return;
            }
            if ($basket->currency !== 'EUR') {
                $this->trace('currency_not_eur', $context);
                return;
            }
            if ((int)$basket->orderId > 0 && (int)$basket->orderId !== $orderId) {
                $this->trace('basket_other_order', $context);
                return;
            }
            // Match the actual merchandise, ignoring shipping/payment positions.
            // This prevents another order event in the same session being tagged.
            $basketItems = $this->items($basket->basketItems, 'variationId');
            $orderItems = $this->items($order->orderItems, 'itemVariationId');
            $context['basketItemCount'] = count($basketItems);
            $context['orderItemCount'] = count($orderItems);
            if (!$basketItems || $basketItems !== $orderItems) {
                $this->trace(!$basketItems ? 'basket_items_missing' : (!$orderItems ? 'order_items_missing' : 'items_mismatch'), $context);
                return;
            }
            $this->trace('tag_requested', $context);
            pluginApp(ClimateOrderTagService::class)->mark((int)$order->id);
            $consent['taggedOrderId'] = (int)$order->id;
            $session->setSessionValue(ClimateContributionService::CONSENT_KEY, $consent);
            $this->trace('tagged', $context);
        } catch (\Throwable $error) {
            $this->trace('exception', ['orderId'=>$orderId, 'basketId'=>$basketId]);
            // An already-created order must remain successful if tagging fails.
            $this->getLogger(self::class)->error(
                'WaldorfshopSeoTest::Template.climateOrderTagFailed',
                ['orderId'=>(int)$order->id, 'basketId'=>$basketId, 'message'=>$error->getMessage()]
            );
        }
    }

    /** Temporary test-build diagnostics; never contain addresses or contact data. */
    private function trace(string $stage, array $context = []): void
    {
        $record = array_merge(['version'=>self::DIAGNOSTIC_VERSION, 'stage'=>$stage], $context);
        try {
            // Error level makes the diagnostic visible without enabling INFO logs.
            $this->getLogger(self::class)->error('WaldorfshopSeoTest::Template.climateOrderDiagnostic', $record);
        } catch (\Throwable $ignored) {
            // A diagnostic failure must not alter ordering or tagging.
        }
        try {
            $session = pluginApp(SessionStorageRepositoryContract::class);
            $records = $session->getSessionValue(self::DIAGNOSTIC_KEY);
            $records = is_array($records) ? $records : [];
            $records[] = $record;
            $session->setSessionValue(self::DIAGNOSTIC_KEY, array_slice($records, -12));
        } catch (\Throwable $ignored) {
        }
    }

    public function diagnostics(): array
    {
        $this->trace('probe');
        $records = pluginApp(SessionStorageRepositoryContract::class)->getSessionValue(self::DIAGNOSTIC_KEY);
        return ['version'=>self::DIAGNOSTIC_VERSION, 'events'=>is_array($records) ? $records : []];
    }

    private function items($items, string $variationKey): array
    {
        $result = [];
        foreach ($items ?? [] as $item) {
            $id = $variationKey === 'variationId'
                ? (int)$item->variationId : (int)$item->itemVariationId;
            if ($id <= 0) {
                continue;
            }
            $quantity = (float)$item->quantity;
            if ($quantity <= 0) {
                return [];
            }
            $result[$id] = round(($result[$id] ?? 0) + $quantity, 6);
        }
        ksort($result);
        return $result;
    }
}
