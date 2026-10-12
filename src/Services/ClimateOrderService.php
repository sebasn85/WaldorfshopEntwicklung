<?php
namespace WaldorfshopSeoTest\Services;

use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Middleware;

/** Observes normal webshop placement without inheriting another plugin class. */
class ClimateOrderService extends Middleware
{
    use Loggable;

    private $accepted = false;
    private $basketId = 0;

    private function isPlacement(Request $request): bool
    {
        return $request->getMethod() === 'POST'
            && rtrim(explode('?', $request->getRequestUri(), 2)[0], '/') === '/rest/io/order';
    }

    public function before(Request $request)
    {
        $this->accepted = false;
        $this->basketId = 0;
        if (!$this->isPlacement($request)) {
            return;
        }
        try {
            $basket = pluginApp(BasketRepositoryContract::class)->load();
            $this->accepted = pluginApp(ClimateContributionService::class)->selected($basket);
            $this->basketId = $basket ? (int)$basket->id : 0;
        } catch (\Throwable $error) {
            $this->getLogger(self::class)->error(
                'WaldorfshopSeoTest::Debug.climateOrderTagFailed',
                ['orderId' => 0, 'message' => $error->getMessage()]
            );
        }
    }

    public function after(Request $request, Response $response): Response
    {
        $accepted = $this->accepted;
        $this->accepted = false;
        if (!$accepted || !$this->isPlacement($request)
            || $response->status() < 200 || $response->status() >= 300) {
            return $response;
        }
        // IO ApiResponse serializes LocalizedOrder as data.order. Use only the
        // persisted ID returned by the server, never an ID supplied by a client.
        $data = json_decode($response->content(), true);
        $orderId = is_array($data) ? (int)($data['data']['order']['id'] ?? 0) : 0;
        if ($orderId <= 0) {
            return $response;
        }

        try {
            pluginApp(ClimateOrderTagService::class)->mark($orderId);
        } catch (\Throwable $error) {
            // Preserve the existing order response, including payment data.
            $this->getLogger(self::class)->error(
                'WaldorfshopSeoTest::Debug.climateOrderTagFailed',
                ['orderId' => $orderId, 'basketId' => $this->basketId, 'message' => $error->getMessage()]
            );
        }
        return $response;
    }
}
