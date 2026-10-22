<?php
namespace WaldorfshopErweiterungen\Providers;

use Plenty\Modules\Webshop\Template\Providers\TemplateServiceProvider;

/** Template overrides and optional, plugin-set-scoped shipping contribution. */
class SeoTestServiceProvider extends TemplateServiceProvider
{
    public function register()
    {
        $this->getApplication()->register(ClimateRouteServiceProvider::class);

    }

    public function boot()
    {
        pluginApp(\Plenty\Plugin\Events\Dispatcher::class)->listen(
            \Plenty\Modules\Order\Events\OrderCreated::class,
            function ($event) {
                pluginApp(\WaldorfshopErweiterungen\Services\ClimateOrderService::class)->handle($event);
            }
        );
        pluginApp(\Plenty\Plugin\Events\Dispatcher::class)->listen(
            \Plenty\Modules\Order\Shipping\Events\AfterShippingCostCalculated::class,
            function ($event) {
                pluginApp(\WaldorfshopErweiterungen\Services\ClimateContributionService::class)->apply($event);
            }
        );
        $this->overrideTemplate(
            'Ceres::PageDesign.Partials.PageMetadata',
            'WaldorfshopErweiterungen::PageDesign.Partials.PageMetadata'
        );
        $this->overrideTemplate(
            'Ceres::Homepage.Homepage',
            'WaldorfshopErweiterungen::Homepage.Homepage'
        );
        $this->overrideTemplate(
            'Ceres::PageDesign.Partials.Head',
            'WaldorfshopErweiterungen::PageDesign.Partials.Head'
        );
        $this->overrideTemplate(
            'Waldorfshop7::PageDesign.Partials.Head',
            'WaldorfshopErweiterungen::PageDesign.Partials.Head'
        );
        $this->overrideTemplate(
            'Ceres::PageDesign.Partials.Footer',
            'WaldorfshopErweiterungen::PageDesign.Partials.Footer'
        );
        $this->overrideTemplate(
            'Waldorfshop7::PageDesign.Partials.Footer',
            'WaldorfshopErweiterungen::PageDesign.Partials.Footer'
        );
        $this->overrideTemplate(
            'Waldorfshop7::ItemList.Components.CategoryItem',
            'WaldorfshopErweiterungen::ItemList.Components.CategoryItem'
        );
        $this->overrideTemplate(
            'Ceres::Widgets.Header.TopBarWidget',
            'WaldorfshopErweiterungen::Widgets.Header.TopBarWidget'
        );
        $this->overrideTemplate(
            'Waldorfshop7::Widgets.Header.TopBarWidget',
            'WaldorfshopErweiterungen::Widgets.Header.TopBarWidget'
        );
        $this->overrideTemplate(
            'Ceres::Item.SingleItemWrapper',
            'WaldorfshopErweiterungen::Item.SingleItemWrapper'
        );
    }
}
