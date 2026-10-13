# WaldorfshopSeoTest

Isolated test plugin containing a copy of the canonical/head template from
[waldorfweb/pp-waldorfshop-07](https://github.com/waldorfweb/pp-waldorfshop-07),
branch `mobileChanges`, commit `5dc0deda2a6911f465ded4d5eec2d1f1302bc99b`.
Original plugin metadata identifies Universnatur GmbH as author and AGPL-3.0 as license.

## Scope

Version 0.2.1 additionally aligns the German order-help canonical, hreflang and footer link with the existing sitemap URL (without trailing slash). Product Offer URLs use the current variant canonical. Category item links include the variant ID only when the existing settings request it or the result reports exactly one saleable variant and no child variants; choose-variant entry links stay available.

The product wrapper is copied from Ceres 5.0.84, commit `5d5783f608fe41a5eb6abb3718c642502699fccf` (PlentyONE GmbH, AGPL-3.0). Its only behavior change is the Offer URL. Dependency versions are pinned for this test.

Only an unfiltered category canonical query equal to `?page=1` is removed.
Page 2+, filters and explicitly forced canonical URLs retain their original handling.
The existing Waldorfshop7 theme, assets, settings and widgets remain in use.
The climate branch adds opt-in API routes and a checkout script; it adds no database migrations or global settings.

## Installation boundaries

- This is a separate plugin and repository, not an update to Waldorfshop7.
- For the climate branch, install and deploy only in the unlinked test set 428.
- Never change the repository connection for plugin 92.
- Never assign this plugin or test set to the live shop without separate approval.
- Start with priority 981, above the existing theme (980), below IO (999).
- Verify in preview: base category, page=1, page=2, filters, product, homepage.
- Verify exactly one canonical and unchanged layout/asset paths.
- Rollback: disable this test plugin and rebuild only the test set.

## Status

Prepared for preview validation. No live rollout or completed T08 audit is implied.
Scope is the theme-rendered category links, Offer URLs and German order-help URL. Variant-selection links are intentional exceptions. Dynamic components outside these templates need separate checks before a complete site-wide claim.

Version 0.2.2 also normalizes the exact Bestellhilfe alias in the copied TopBar language picker, retaining the existing query string. Regenerate ShopBuilder contents in test set 426 after deployment.


## Voluntary shipping contribution (0.5.3, climate branch)

Branch `feature/klimaversand-50-cent` is intended for unlinked plugin set **428**, copied from live set 427. Do not merge into main or link set 428 to the live shop during validation. `climate.enabled` defaults to false.

Enable only in the test set and apply the two default container links. The checkbox is initially unchecked and explicit acceptance is stored for the current basket only. The EUR 0.50 amount is fixed on the server. The contribution is added through Plenty's `AfterShippingCostCalculated::addAdditionalFee` and therefore is included in shipping costs, VAT and payment total; it does not add a product or increase `itemSum`. In the order/invoice it is included in the shipping-cost position, **not a separate contribution position**. A separate invoice line requires a further accounting integration. No DHL/GoGreen contract or label settings are changed by this plugin.

Scope: baskets in EUR with gross or net-price display, including VAT-free export destinations. CHF baskets remain ineligible. Changes trigger full checkout reload so payment components receive the updated total. Every toggle verifies an exact 50-cent change and unchanged merchandise value; otherwise it restores the old consent and recalculates. The fee is recalculated from base shipping costs, not accumulated from the old total.

### Required preview checks before release

- Initial checkout unchecked; total unchanged. Select once: gross total +0.50, merchandise value unchanged. Deselect: original total restored.
- Repeated toggles and reload: no duplicate fee. Quantity, address, shipping and payment changes retain a single fee.
- Merchandise just below/above every shipping threshold: contribution does not change shipping eligibility.
- Shipping discount/coupon, mixed VAT and VAT-free export: exact delta and VAT must be checked in the real backend. Any unsupported case must fail closed.
- Empty basket/new basket/new order: old consent does not carry over. Gross/net changes retain availability for EUR; CHF changes disable the option.
- Mobile and desktop: checkbox readable and reachable by keyboard; no order submission while updating.
- Sandbox order/payment and invoice: identical confirmed amount, contribution included in shipping position. This final check requires a sandbox payment method; no real purchase is authorized.

The development checks do not substitute for these preview and accounting checks. Rollback: disable climate.enabled in set 428 and rebuild that set, or restore plugin branch main in set 428.

Checkout contribution copy, accessibility labels, and status/recovery messages use the active shop language through Plenty translations. Version 0.5.2 includes German, English, French, Italian, Spanish and Dutch. Server failures show a translated recovery instruction instead of exposing German API errors.

Version 0.5.3 registers the translation section and includes server-rendered defaults for translation caches that return untranslated keys.


### 0.5.4
Updates the authoritative checkout and basket through Ceres AJAX response events after changing the voluntary contribution, avoiding a full page reload. The checkout notice is available in DE, EN, FR, IT, ES and NL, with German fallback. A scoped UI adapter corrects remaining checkout control translations without changing customer or product data.

### 0.5.7
Allows the voluntary EUR 0.50 contribution for net-price baskets, including Switzerland and other VAT-free export destinations. The exact 50-cent total-change and unchanged merchandise-value checks remain enforced. Verify country and language changes in unlinked test set 429 before any live rollout.

### Climate order tagging (0.5.25)
Tagging listens to the native OrderCreated event, using its persisted sales-order ID. No HTTP method/path or middleware object state is used. It requires enabled EUR contribution consent for the current basket and matching variation quantities in the created order. Shipping/payment positions are ignored. Other order types, mismatching baskets/items and already consumed consent are skipped. The existing Klimaauftrag tag (ID353) is added idempotently, preserving all other tags. A successful write records taggedOrderId on this basket consent; tag storage errors are logged without failing the created order.

Only test set429 is authorized. CI contracts are not proof of real event timing: verify a Vorkasse order with and without consent before any release. If basket items are cleared before OrderCreated is dispatched, the handler skips rather than tagging an unrelated order; this timing must be confirmed in the backend test.
