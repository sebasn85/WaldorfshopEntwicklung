<?php
namespace WaldorfshopSeoTest\Services;

use IO\Models\LocalizedOrder;
use IO\Services\OrderService;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;

/** Extends only the normal webshop placement, not manual/marketplace orders. */
class ClimateOrderService extends OrderService
{
    public function placeOrder(): LocalizedOrder
    {
        // The checkout can consume/reset its basket and session when placing an
        // order. Snapshot consent beforehand, but write no tag until it succeeds.
        $basket = pluginApp(BasketRepositoryContract::class)->load();
        $accepted = pluginApp(ClimateContributionService::class)->selected($basket);
        $basketId = $basket ? (int)$basket->id : 0;
        $result = parent::placeOrder();

        if ($accepted) {
            $orderId = $result->order ? (int)$result->order->id : 0;
            try {
                pluginApp(ClimateOrderTagService::class)->mark($orderId);
            } catch (\Throwable $error) {
                // The order already exists. Never cause a second order/payment
                // attempt because the analytics tag could not be written.
                $this->getLogger(self::class)->error(
                    'WaldorfshopSeoTest::Debug.climateOrderTagFailed',
                    ['orderId' => $orderId, 'basketId' => $basketId, 'message' => $error->getMessage()]
                );
            }
        }
        return $result;
    }
}
