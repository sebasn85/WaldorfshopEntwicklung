<?php
namespace WaldorfshopSeoTest\Services;

use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Middleware;

/** Observes normal webshop placement without inheriting another plugin class. */
class ClimateOrderService extends Middleware
{
    use Loggable;

    const TRACE_KEY = 'WaldorfshopSeoTest.middlewareTrace.v1';
    private $probeSeen = false;
    private $accepted = false;
    private $basketId = 0;
    private $previousOrderId = 0;

    private function isPlacement(Request $request): bool
    {
        return ($request->getMethod() === 'POST' && $this->path($request) === '/rest/io/order')
            || ($request->getMethod() === 'GET'
                && preg_match('#^/(?:(?:de|en|fr|it|es|nl)/)?place-order$#', $this->path($request)) === 1);
    }

    private function path(Request $request): string
    {
        return rtrim(explode('?', $request->getRequestUri(), 2)[0], '/');
    }

    private function isProbe(Request $request): bool
    {
        // Temporary diagnostics confined to the unlinked test plugin set.
        // Log every callback so a route/request-parameter mismatch cannot hide it.
        return true;
    }

    public function before(Request $request)
    {
        $this->probeSeen = true;
        $session = pluginApp(SessionStorageRepositoryContract::class);
        $trace = $session->getSessionValue(self::TRACE_KEY);
        $trace = is_array($trace) ? $trace : [];
        $trace['before'] = ['version'=>'0.5.24', 'path'=>$this->path($request),
            'method'=>$request->getMethod(), 'beforeSeenOnThisInstance'=>$this->probeSeen];
        $session->setSessionValue(self::TRACE_KEY, $trace);
        $this->accepted = false;
        $this->basketId = 0;
        $this->previousOrderId = 0;
        if (!$this->isPlacement($request)) {
            return;
        }
        try {
            $this->previousOrderId = (int)pluginApp(SessionStorageRepositoryContract::class)
                ->getSessionValue(SessionStorageRepositoryContract::LATEST_ORDER_ID);
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
        $session = pluginApp(SessionStorageRepositoryContract::class);
        $trace = $session->getSessionValue(self::TRACE_KEY);
        $trace = is_array($trace) ? $trace : [];
        $trace['after'] = ['version'=>'0.5.24', 'path'=>$this->path($request),
            'method'=>$request->getMethod(), 'status'=>$response->status(),
            'beforeSeenOnThisInstance'=>$this->probeSeen];
        $session->setSessionValue(self::TRACE_KEY, $trace);
        $accepted = $this->accepted;
        $this->accepted = false;
        if (!$accepted || !$this->isPlacement($request)) {
            return $response;
        }
        $orderId = 0;
        try {
            if ($request->getMethod() === 'GET') {
                // PlaceOrderController.complete stores the persisted order ID
                // before executing payment/redirecting. Failed validation leaves
                // LATEST_ORDER_ID unchanged. Never use a request/query order ID.
                if ($response->status() < 200 || $response->status() >= 400) {
                    return $response;
                }
                $orderId = (int)pluginApp(SessionStorageRepositoryContract::class)
                    ->getSessionValue(SessionStorageRepositoryContract::LATEST_ORDER_ID);
                if ($orderId <= 0 || $orderId === $this->previousOrderId) {
                    return $response;
                }
            } else {
                if ($response->status() < 200 || $response->status() >= 300) {
                    return $response;
                }
                $data = json_decode($response->content(), true);
                $orderId = is_array($data) ? (int)($data['data']['order']['id'] ?? 0) : 0;
                if ($orderId <= 0) {
                    return $response;
                }
            }
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
