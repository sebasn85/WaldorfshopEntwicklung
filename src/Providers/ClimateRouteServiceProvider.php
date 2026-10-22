<?php
namespace WaldorfshopErweiterungen\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\ApiRouter;

class ClimateRouteServiceProvider extends RouteServiceProvider
{
    public function map(ApiRouter $api)
    {
        $api->version(['v1'], ['namespace' => 'WaldorfshopErweiterungen\Api'], function (ApiRouter $api) {
            $api->get('waldorfshop/climate-contribution', 'ClimateContributionResource@index');
            $api->post('waldorfshop/climate-contribution', 'ClimateContributionResource@store');
        });
    }
}
