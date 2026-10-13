<?php
namespace WaldorfshopSeoTest\Api;

use IO\Api\ApiResource;
use Plenty\Plugin\Http\Response;
use WaldorfshopSeoTest\Services\ClimateContributionService;

class ClimateContributionResource extends ApiResource
{
    public function index(): Response
    {
        $state = pluginApp(ClimateContributionService::class)->state();
        if ((string)$this->request->get('climateMiddlewareProbe', '') === '1') {
            $state['middlewareTrace'] = pluginApp(\Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract::class)
                ->getSessionValue(\WaldorfshopSeoTest\Services\ClimateOrderService::TRACE_KEY);
            $state['diagnosticVersion'] = '0.5.24';
        }
        return $this->response->create($state, 200);
    }

    public function store(): Response
    {
        $selected = $this->request->get('selected');
        if (!is_bool($selected)) {
            return $this->response->create(['error' => 'Bitte wähle den Versandbeitrag erneut.'], 422);
        }
        try {
            $state = pluginApp(ClimateContributionService::class)->setSelected(
                $selected,
                (int)$this->request->get('basketId', 0)
            );
            // ApiResponse enriches this event with authoritative basket and checkout data.
            $this->response->eventData['AfterBasketChanged'] = ['basket' => null];
            return $this->response->create($state, 200);
        } catch (\RuntimeException $error) {
            return $this->response->create(['error' => $error->getMessage()], 409);
        }
    }
}
