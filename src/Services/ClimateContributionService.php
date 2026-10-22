<?php
namespace WaldorfshopErweiterungen\Services;

use IO\Services\BasketService;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Order\Shipping\Events\AfterShippingCostCalculated;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\ConfigRepository;

/** The fee participates in Plenty's shipping, VAT and payment calculation. */
class ClimateContributionService
{
    const AMOUNT = 0.50;
    // Retain existing basket consent when switching from the previous plugin.
    const CONSENT_KEY = 'WaldorfshopSeoTest.climateConsent.v1';

    private $session;
    private $baskets;
    private $checkout;
    private $config;

    public function __construct(
        SessionStorageRepositoryContract $session,
        BasketRepositoryContract $baskets,
        Checkout $checkout,
        ConfigRepository $config
    ) {
        $this->session = $session;
        $this->baskets = $baskets;
        $this->checkout = $checkout;
        $this->config = $config;
    }

    public function enabled(): bool
    {
        return $this->config->get('WaldorfshopErweiterungen.climate.enabled', 'false') === 'true';
    }

    public function eligible($basket): bool
    {
        return $this->enabled() && $basket && $basket->id > 0
            && $basket->currency === 'EUR' && !$basket->orderId
            && count($basket->basketItems) > 0;
    }

    public function selected($basket): bool
    {
        $consent = $this->session->getSessionValue(self::CONSENT_KEY);
        return $this->eligible($basket) && is_array($consent)
            && (int)$consent['basketId'] === (int)$basket->id
            && $consent['accepted'] === true;
    }

    public function apply(AfterShippingCostCalculated $event)
    {
        // This hook contributes a fee to the actual shipping-cost calculation.
        // Shipment/backend recalculations of existing orders must not use basket consent.
        if ($event->getOrderId() > 0) {
            return;
        }
        $basket = $this->baskets->load();
        if ($this->selected($basket)) {
            $fee = self::AMOUNT;
            $order = $this->session->getOrder();
            // IO displays net totals only for net orders without collected VAT.
            // The shipping event takes gross fees, which Plenty converts to net.
            // Never request total VAT while shipping costs are being calculated:
            // that service can calculate the basket (and this shipping hook) again.
            $consent = $this->session->getSessionValue(self::CONSENT_KEY);
            $netDisplay = isset($consent['netDisplay'])
                ? $consent['netDisplay'] : empty($order->orderAmountVats);
            if ($order && $order->isNet && $netDisplay) {
                $fee *= $this->netFeeFactor($basket, false);
            }
            $event->addAdditionalFee($fee);
        }
    }

    private function netFeeFactor($basket, bool $allowVatLookup = true): float
    {
        // Export item VAT can become zero after a reload although the shipping
        // calculator still removes the source VAT. Read its gross/net amounts.
        if ($basket->shippingAmount > 0 && $basket->shippingAmountNet > 0) {
            return round($basket->shippingAmount / $basket->shippingAmountNet, 3);
        }
        $consent = $this->session->getSessionValue(self::CONSENT_KEY);
        if (is_array($consent) && (int)$consent['basketId'] === (int)$basket->id
            && isset($consent['netFeeFactor'])) {
            return (float)$consent['netFeeFactor'];
        }
        // The initial request may query VAT; the shipping callback must not.
        return $allowVatLookup
            ? 1 + max(0, pluginApp(BasketService::class)->getMaxVatValue()) / 100
            : 1.0;
    }

    public function state(): array
    {
        $basket = $this->baskets->load();
        if (!$this->eligible($basket)) {
            $this->session->setSessionValue(self::CONSENT_KEY, null);
        }
        $templateBasket = pluginApp(BasketService::class)->getBasketForTemplate();
        $consent = $this->session->getSessionValue(self::CONSENT_KEY);
        $recalculated = false;
        if ($this->selected($basket)) {
            $order = $this->session->getOrder();
            $netDisplay = $order && $order->isNet
                && count($templateBasket['totalVats'] ?? []) === 0;
            $recalculated = isset($consent['netDisplay'])
                && $consent['netDisplay'] !== $netDisplay;
            $consent['netDisplay'] = $netDisplay;
            $this->session->setSessionValue(self::CONSENT_KEY, $consent);
            // Country changes may have used the previous display tax mode.
            // Correct once outside the shipping hook, then refresh checkout totals.
            if ($recalculated) {
                $this->checkout->setShippingProfileId((int)$basket->shippingProfileId, true);
                $templateBasket = pluginApp(BasketService::class)->getBasketForTemplate();
            }
        }
        return [
            'enabled' => $this->enabled(),
            'eligible' => $this->eligible($basket),
            'selected' => $this->selected($basket),
            'amount' => self::AMOUNT,
            'recalculated' => $recalculated,
            'basketId' => $basket ? (int)$basket->id : 0,
            'basket' => $templateBasket
        ];
    }

    public function setSelected(bool $selected, int $basketId): array
    {
        $basket = $this->baskets->load();
        if (!$basket || (int)$basket->id !== $basketId || !$this->eligible($basket)) {
            throw new \RuntimeException('Der Warenkorb hat sich geändert. Bitte lade die Kasse neu.');
        }
        if ($this->selected($basket) === $selected) {
            return $this->state();
        }

        $previous = $this->session->getSessionValue(self::CONSENT_KEY);
        $before = pluginApp(BasketService::class)->getBasketForTemplate();
        $netFeeFactor = $this->netFeeFactor($basket);
        $this->session->setSessionValue(self::CONSENT_KEY, [
            'basketId' => $basketId,
            'accepted' => $selected,
            'amount' => self::AMOUNT,
            'netFeeFactor' => $netFeeFactor,
            'netDisplay' => $this->session->getOrder() && $this->session->getOrder()->isNet
                && count($before['totalVats'] ?? []) === 0,
            'acceptedAt' => $selected ? time() : null,
            'textVersion' => '2026-10-12',
            'calculationVersion' => 2
        ]);

        try {
            $this->checkout->setShippingProfileId((int)$basket->shippingProfileId, true);
            $after = $this->state();
            $expectedCents = $selected ? 50 : -50;
            $difference = (int)round(($after['basket']['basketAmount'] - $before['basketAmount']) * 100);
            // Zero-cost shipping offers no initial gross/net ratio. Calibrate
            // once from Plenty's actual result, then keep the exact-delta guard.
            $order = $this->session->getOrder();
            if ($selected && $difference > 0 && $difference < 50 && $order && $order->isNet
                && count(pluginApp(BasketService::class)->getTotalVats()) === 0) {
                $factor = $netFeeFactor * 50 / $difference;
                if ($factor >= 1 && $factor <= 1.5) {
                    $consent = $this->session->getSessionValue(self::CONSENT_KEY);
                    $consent['netFeeFactor'] = $factor;
                    $this->session->setSessionValue(self::CONSENT_KEY, $consent);
                    $this->checkout->setShippingProfileId((int)$basket->shippingProfileId, true);
                    $after = $this->state();
                    $difference = (int)round(($after['basket']['basketAmount'] - $before['basketAmount']) * 100);
                }
            }
            // A consent saved by 0.5.7-0.5.9 may contain a 42-49-cent net
            // contribution. Removing it must remove the amount actually charged,
            // rather than restore a fee the customer has explicitly deselected.
            $legacyRemoval = !$selected && is_array($previous)
                && !isset($previous['calculationVersion']) && $order && $order->isNet
                && $difference >= -51 && $difference <= -40;
            if (($difference !== $expectedCents && !$legacyRemoval)
                || (int)round($after['basket']['itemSum'] * 100) !== (int)round($before['itemSum'] * 100)) {
                throw new \RuntimeException('Der Versandbeitrag konnte nicht eindeutig berechnet werden. Bitte lade die Kasse neu.');
            }
            return $after;
        } catch (\Throwable $error) {
            $this->session->setSessionValue(self::CONSENT_KEY, $previous);
            $this->checkout->setShippingProfileId((int)$basket->shippingProfileId, true);
            throw $error;
        }
    }
}
