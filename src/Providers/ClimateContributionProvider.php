<?php
namespace WaldorfshopErweiterungen\Providers;

use Plenty\Plugin\Templates\Twig;
use WaldorfshopErweiterungen\Services\ClimateContributionService;

class ClimateContributionProvider
{
    public function call(Twig $twig)
    {
        if (!pluginApp(ClimateContributionService::class)->enabled()) {
            return '';
        }
        return $twig->render('WaldorfshopErweiterungen::Checkout.ClimateContribution');
    }
}
