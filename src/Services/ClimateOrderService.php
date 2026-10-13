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

    public function handle(OrderCreated $event): void
    {
        $order = $event->getOrder();
        if (!$order || (int)$order->id <= 0 || (int)$order->typeId !== 1) {
            return;
        }
        $basketId = 0;
        try {
            $session = pluginApp(SessionStorageRepositoryContract::class);
            $consent = $session->getSessionValue(ClimateContributionService::CONSENT_KEY);
            if (!is_array($consent) || ($consent['accepted'] ?? false) !== true
                || !empty($consent['taggedOrderId'])
                || !pluginApp(ClimateContributionService::class)->enabled()) {
                return;
            }
            $basket = pluginApp(BasketRepositoryContract::class)->load();
            $basketId = $basket ? (int)$basket->id : 0;
            if ($basketId <= 0 || $basketId !== (int)($consent['basketId'] ?? 0)
                || $basket->currency !== 'EUR'
                || ((int)$basket->orderId > 0 && (int)$basket->orderId !== (int)$order->id)) {
                return;
            }
            // Match the actual merchandise, ignoring shipping/payment positions.
            // This prevents another order event in the same session being tagged.
            $basketItems = $this->items($basket->basketItems, 'variationId');
            $orderItems = $this->items($order->orderItems, 'itemVariationId');
            if (!$basketItems || $basketItems !== $orderItems) {
                return;
            }
            pluginApp(ClimateOrderTagService::class)->mark((int)$order->id);
            $consent['taggedOrderId'] = (int)$order->id;
            $session->setSessionValue(ClimateContributionService::CONSENT_KEY, $consent);
        } catch (\Throwable $error) {
            // An already-created order must remain successful if tagging fails.
            $this->getLogger(self::class)->error(
                'WaldorfshopSeoTest::Template.climateOrderTagFailed',
                ['orderId'=>(int)$order->id, 'basketId'=>$basketId, 'message'=>$error->getMessage()]
            );
        }
    }

    private function items($items, string $variationKey): array
    {
        $result = [];
        foreach ($items ?? [] as $item) {
            $id = (int)$item->$variationKey;
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
