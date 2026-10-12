<?php
namespace WaldorfshopSeoTest\Providers;

use Plenty\Modules\Webshop\Template\Providers\TemplateServiceProvider;

/** Template overrides and optional, plugin-set-scoped shipping contribution. */
class SeoTestServiceProvider extends TemplateServiceProvider
{
    public function register()
    {
        $this->getApplication()->register(ClimateRouteServiceProvider::class);
        $this->getApplication()->bind(
            \IO\Services\OrderService::class,
            \WaldorfshopSeoTest\Services\ClimateOrderService::class
        );
    }

    public function boot()
    {
        pluginApp(\Plenty\Plugin\Events\Dispatcher::class)->listen(
            \Plenty\Modules\Order\Shipping\Events\AfterShippingCostCalculated::class,
            function ($event) {
                pluginApp(\WaldorfshopSeoTest\Services\ClimateContributionService::class)->apply($event);
            }
        );
        $this->overrideTemplate(
            'Ceres::PageDesign.Partials.PageMetadata',
            'WaldorfshopSeoTest::PageDesign.Partials.PageMetadata'
        );
        $this->overrideTemplate(
            'Ceres::Homepage.Homepage',
            'WaldorfshopSeoTest::Homepage.Homepage'
        );
        $this->overrideTemplate(
            'Ceres::PageDesign.Partials.Head',
            'WaldorfshopSeoTest::PageDesign.Partials.Head'
        );
        $this->overrideTemplate(
            'Waldorfshop7::PageDesign.Partials.Head',
            'WaldorfshopSeoTest::PageDesign.Partials.Head'
        );
        $this->overrideTemplate(
            'Ceres::PageDesign.Partials.Footer',
            'WaldorfshopSeoTest::PageDesign.Partials.Footer'
        );
        $this->overrideTemplate(
            'Waldorfshop7::PageDesign.Partials.Footer',
            'WaldorfshopSeoTest::PageDesign.Partials.Footer'
        );
        $this->overrideTemplate(
            'Waldorfshop7::ItemList.Components.CategoryItem',
            'WaldorfshopSeoTest::ItemList.Components.CategoryItem'
        );
        $this->overrideTemplate(
            'Ceres::Widgets.Header.TopBarWidget',
            'WaldorfshopSeoTest::Widgets.Header.TopBarWidget'
        );
        $this->overrideTemplate(
            'Waldorfshop7::Widgets.Header.TopBarWidget',
            'WaldorfshopSeoTest::Widgets.Header.TopBarWidget'
        );
        $this->overrideTemplate(
            'Ceres::Item.SingleItemWrapper',
            'WaldorfshopSeoTest::Item.SingleItemWrapper'
        );
    }
}
