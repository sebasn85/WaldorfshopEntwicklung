<?php
// Small contract fakes; real Plenty integration must additionally pass preview checks.
namespace Plenty\Plugin {
    class ConfigRepository {
        public $enabled = 'true';
        public function get($key, $default) { return $this->enabled; }
    }
}
namespace Plenty\Modules\Webshop\Contracts {
    class SessionStorageRepositoryContract {
        public $values = [];
        public $order;
        public function getSessionValue($key) { return $this->values[$key] ?? null; }
        public function setSessionValue($key, $value) { $this->values[$key] = $value; }
        public function getOrder() { return $this->order; }
    }
}
namespace Plenty\Modules\Frontend\Contracts {
    class Checkout {
        public $recalculate;
        public function setShippingProfileId($id, $force) { ($this->recalculate)(); }
    }
}
namespace Plenty\Modules\Basket\Contracts {
    class BasketRepositoryContract {
        public $basket;
        public function load() { return $this->basket; }
    }
}
namespace Plenty\Modules\Order\Shipping\Events {
    class AfterShippingCostCalculated {
        private $orderId;
        private $fee = 0.0;
        public function __construct($provider, $orderId, $profileId) { $this->orderId = $orderId; }
        public function getOrderId() { return $this->orderId; }
        public function getAdditonalFee() { return $this->fee; }
        public function addAdditionalFee($fee) { $this->fee += $fee; }
    }
}
namespace IO\Services {
    class BasketService {
        public $repo;
        public $maxVat = 19.0;
        public $calculationVat = null;
        public $totalVats = [];
        public $session;
        public $inShippingCallback = false;
        public function getMaxVatValue() { if ($this->inShippingCallback) throw new \RuntimeException("VAT lookup in shipping callback"); return $this->maxVat; }
        public function getTotalVats(): array { if ($this->inShippingCallback) throw new \RuntimeException("Total VAT lookup in shipping callback"); return $this->totalVats; }
        public function getBasketForTemplate() {
            $basket = (array)$this->repo->load();
            $basket['totalVats'] = $this->totalVats;
            if ($this->session->order && $this->session->order->isNet && count($this->totalVats) === 0) {
                $basket['shippingAmount'] = $basket['shippingAmountNet'];
            }
            return $basket;
        }
    }
}
namespace {
    use WaldorfshopErweiterungen\Services\ClimateContributionService;
    use Plenty\Modules\Order\Shipping\Events\AfterShippingCostCalculated;
    function pluginApp($class) { return $GLOBALS['services'][$class]; }
    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
        $GLOBALS['checks']++;
    }
    require __DIR__.'/../src/Services/ClimateContributionService.php';
    $GLOBALS['checks'] = 0;
    $session = new \Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract();
    $repo = new \Plenty\Modules\Basket\Contracts\BasketRepositoryContract();
    $checkout = new \Plenty\Modules\Frontend\Contracts\Checkout();
    $config = new \Plenty\Plugin\ConfigRepository();
    $basketService = new \IO\Services\BasketService();
    $basketService->repo = $repo;
    $basketService->session = $session;
    $GLOBALS['services'][\IO\Services\BasketService::class] = $basketService;
    $repo->basket = (object)['id'=>10,'currency'=>'EUR','orderId'=>0,'basketItems'=>[1],
        'shippingProfileId'=>7,'itemSum'=>78.99,'shippingAmount'=>4.90,'shippingAmountNet'=>4.90,'basketAmount'=>83.89];
    $service = new ClimateContributionService($session, $repo, $checkout, $config);
    $breakCalculation = false;
    $recalculations = 0;
    $checkout->recalculate = function () use ($repo, $service, $session, $basketService, &$breakCalculation, &$recalculations) {
        $recalculations++;
        $basket = $repo->basket;
        $event = new AfterShippingCostCalculated('DHL', 0, $basket->shippingProfileId);
        $basketService->inShippingCallback = true;
        try { $service->apply($event); }
        finally { $basketService->inShippingCallback = false; }
        $fee = $event->getAdditonalFee();
        $base = ($basket->itemSum >= 79 ? 0 : 4.90);
        if ($session->order && $session->order->isNet && count($basketService->getTotalVats()) === 0) {
            $factor = 1 + ($basketService->calculationVat ?? $basketService->maxVat) / 100;
            $basket->shippingAmount = $base * $factor + $fee;
            $basket->shippingAmountNet = round($basket->shippingAmount / $factor, 2);
            $displayShipping = $basket->shippingAmountNet;
        } else {
            $basket->shippingAmount = round($base + $fee, 2);
            $basket->shippingAmountNet = $basket->shippingAmount;
            $displayShipping = $basket->shippingAmount;
        }
        $basket->basketAmount = round($basket->itemSum + $displayShipping, 2)
            + ($breakCalculation ? 1 : 0);
    };
    check(!$service->selected($repo->basket), 'Initial consent must be false');
    $result = $service->setSelected(true, 10);
    check($result['selected'], 'Explicit opt-in must be selected');
    check(round($result['basket']['basketAmount'], 2) === 84.39, 'Exact 50 cents added');
    check($result['basket']['itemSum'] === 78.99, 'Contribution must not cross 79 EUR merchandise threshold');
    $service->setSelected(true, 10);
    check(round($repo->basket->basketAmount, 2) === 84.39, 'Repeated request is idempotent');
    ($checkout->recalculate)();
    ($checkout->recalculate)();
    check(round($repo->basket->basketAmount, 2) === 84.39, 'Recalculation does not accumulate fees');
    $service->setSelected(false, 10);
    check(round($repo->basket->basketAmount, 2) === 83.89, 'Deselection restores total');
    $repo->basket->itemSum = 79.0;
    ($checkout->recalculate)();
    $result = $service->setSelected(true, 10);
    check($result['basket']['basketAmount'] === 79.5, 'Fee remains with free base shipping');
    $service->setSelected(false, 10);
    check($repo->basket->basketAmount === 79.0, 'Free-shipping deselection restores total');
    $breakCalculation = true;
    try { $service->setSelected(true, 10); throw new \Exception('Unexpected success'); }
    catch (\RuntimeException $error) { check(!$service->selected($repo->basket), 'Bad delta rolls back consent'); }
    $breakCalculation = false;
    ($checkout->recalculate)();
    try { $service->setSelected(true, 99); throw new \Exception('Unexpected stale basket success'); }
    catch (\RuntimeException $error) { check(!$service->selected($repo->basket), 'Stale basket cannot opt in'); }
    $service->setSelected(true, 10);
    $existingOrderEvent = new AfterShippingCostCalculated('DHL', 123, 7);
    $service->apply($existingOrderEvent);
    check($existingOrderEvent->getAdditonalFee() === 0.0, 'Existing-order shipping calculation ignores basket consent');
    $repo->basket->id = 11;
    check(!$service->selected($repo->basket), 'New basket requires new consent');
    $repo->basket->currency = 'CHF';
    check(!$service->eligible($repo->basket), 'CHF ineligible');
    $repo->basket->currency = 'EUR';
    $session->order = (object)['isNet'=>true, 'orderAmountVats'=>[19]];
    // Legacy order metadata can retain source VAT while IO displays VAT-free net totals.
    check($service->eligible($repo->basket), 'EUR net basket remains eligible for export shipping');
    // A newly loaded basket starts with its own freshly calculated totals.
    ($checkout->recalculate)();
    $result = $service->setSelected(true, 11);
    check($result['selected'], 'Export basket accepts explicit contribution');
    check(round($result['basket']['basketAmount'], 2) === 79.5, 'Export contribution adds exactly 50 cents');
    $repo->basket->shippingProfileId = 44;
    ($checkout->recalculate)();
    check($service->selected($repo->basket), 'Export shipping-profile change preserves consent');
    check(round($repo->basket->basketAmount, 2) === 79.5, 'Export profile recalculation does not duplicate fee');
    $basketService->calculationVat = 19.0;
    $basketService->maxVat = 0.0;
    ($checkout->recalculate)();
    check(round($repo->basket->basketAmount, 2) === 79.5, 'Export reload keeps 50 cents when item VAT becomes zero');
    $service->setSelected(false, 11);
    check(round($repo->basket->basketAmount, 2) === 79.0, 'Export deselection restores the original total');
    $session->setSessionValue(ClimateContributionService::CONSENT_KEY, null);
    $result = $service->setSelected(true, 11);
    check(round($result['basket']['basketAmount'], 2) === 79.5, 'Zero base shipping calibrates exact net contribution');
    $service->setSelected(false, 11);
    $session->setSessionValue(ClimateContributionService::CONSENT_KEY, null);
    $basketService->calculationVat = null;
    $basketService->maxVat = 7.0;
    $result = $service->setSelected(true, 11);
    check(round($result['basket']['basketAmount'], 2) === 79.5, 'Reduced VAT export adds exactly 50 net cents');
    $service->setSelected(false, 11);
    // Older export sessions may have charged only 42 net cents. A customer
    // must be able to revoke that fee without a rollback and second calculation.
    $repo->basket->shippingAmount = 0.50;
    $repo->basket->shippingAmountNet = 0.42;
    $repo->basket->basketAmount = 79.42;
    $session->setSessionValue(ClimateContributionService::CONSENT_KEY, [
        'basketId'=>11, 'accepted'=>true, 'netFeeFactor'=>1.0
    ]);
    $callsBeforeRemoval = $recalculations;
    $result = $service->setSelected(false, 11);
    check(!$result['selected'], 'Legacy export consent can be revoked');
    check($result['basket']['basketAmount'] === 79.0, 'Legacy removal removes the amount actually charged');
    check($recalculations === $callsBeforeRemoval + 1, 'Legacy removal needs only one shipping calculation');
    $basketService->totalVats = [19];
    $session->order->orderAmountVats = [19];
    $result = $service->setSelected(true, 11);
    check(round($result['basket']['basketAmount'], 2) === 79.5, 'Net customer with collected VAT keeps the gross display contribution');
    $basketService->totalVats = [];
    $basketService->maxVat = 19;
    ($checkout->recalculate)();
    $result = $service->state();
    check($result['recalculated'], 'Country switch corrects cached gross mode outside shipping hook');
    check($result['basket']['basketAmount'] === 79.5, 'Country switch preserves exactly 50 net cents');
    check(!$service->state()['recalculated'], 'Corrected country mode does not repeat recalculation');
    $service->setSelected(false, 11);
    check($repo->basket->basketAmount === 79.0, 'Removal after country switch restores base total');
    $session->order = null;
    $repo->basket->basketItems = [];
    check(!$service->eligible($repo->basket), 'Empty basket ineligible');
    $service->state();
    check($session->getSessionValue(ClimateContributionService::CONSENT_KEY) === null, 'Empty basket clears consent');
    $repo->basket->basketItems = [1];
    $config->enabled = 'false';
    check(!$service->eligible($repo->basket), 'Disabled plugin never accepts contribution');
    echo $GLOBALS['checks']." contribution checks passed\n";
}
