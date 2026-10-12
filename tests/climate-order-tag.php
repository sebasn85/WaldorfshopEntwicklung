<?php
namespace Plenty\Modules\Basket\Contracts {
    class BasketRepositoryContract { public $basket; public function load() { return $this->basket; } }
}
namespace Plenty\Modules\Authorization\Services {
    class AuthHelper {
        public function processUnguarded($callback) {
            $previous = $GLOBALS['unguarded'] ?? false;
            $GLOBALS['unguarded'] = true;
            try { return $callback(); }
            finally { $GLOBALS['unguarded'] = $previous; }
        }
    }
}
namespace Plenty\Modules\Tag\Contracts {
    class TagRepositoryContract {
        public $tag;
        public function getTagByName($name) {
            if ($name !== 'Klimaauftrag') throw new \RuntimeException('Unexpected tag name');
            return $this->tag;
        }
    }
    class TagRelationshipRepositoryContract {
        public $relations = [];
        public $writes = 0;
        public $fail = false;
        public function findRelationship($tagId, $orderId, $type) { return $this->relations[$type][$orderId][$tagId] ?? null; }
        public function create($data) {
            if ($this->fail) throw new \RuntimeException('Tag storage unavailable');
            $this->writes++;
            $this->relations[$data['tagType']][$data['relationshipValue']][$data['tagId']] = $data;
            return (object)$data;
        }
    }
}
namespace IO\Models { class LocalizedOrder { public $order; } }
namespace IO\Services {
    class OrderService {
        public static $place;
        public function placeOrder(): \IO\Models\LocalizedOrder { return (self::$place)(); }
        public function getLogger($name) { return $GLOBALS['logger']; }
    }
}
namespace WaldorfshopSeoTest\Services {
    class ClimateContributionService {
        public $accepted = false;
        public function selected($basket): bool { return $this->accepted; }
    }
}
namespace Plenty\Modules\Webshop\Template\Providers {
    class TemplateServiceProvider { public function getApplication() { return $GLOBALS['application']; } }
}
namespace {
    use WaldorfshopSeoTest\Services\ClimateOrderService;
    use WaldorfshopSeoTest\Services\ClimateOrderTagService;
    function pluginApp($class) { return $GLOBALS['services'][$class]; }
    function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); $GLOBALS['checks']++; }
    require __DIR__.'/../src/Services/ClimateOrderTagService.php';
    require __DIR__.'/../src/Services/ClimateOrderService.php';
    require __DIR__.'/../src/Providers/SeoTestServiceProvider.php';
    $GLOBALS['checks'] = 0;
    $GLOBALS['logger'] = new class {
        public $errors = [];
        public function error($key, $context) { $this->errors[] = [$key, $context]; }
    };
    $GLOBALS['application'] = new class {
        public $bindings = [];
        public function register($class) {}
        public function bind($from, $to) { $this->bindings[$from] = $to; }
    };
    (new \WaldorfshopSeoTest\Providers\SeoTestServiceProvider())->register();
    check($GLOBALS['application']->bindings[\IO\Services\OrderService::class] === ClimateOrderService::class,
        'Shop order placement must resolve to the climate-aware service');
    $repo = new \Plenty\Modules\Basket\Contracts\BasketRepositoryContract();
    $repo->basket = (object)['id'=>10];
    $consent = new \WaldorfshopSeoTest\Services\ClimateContributionService();
    $tags = new \Plenty\Modules\Tag\Contracts\TagRepositoryContract();
    $tags->tag = (object)['id'=>88];
    $relations = new \Plenty\Modules\Tag\Contracts\TagRelationshipRepositoryContract();
    // An existing, unrelated tag must survive every operation.
    $relations->relations['order'][101][99] = ['tagId'=>99];
    $tagService = new ClimateOrderTagService($tags, $relations);
    $GLOBALS['services'] = [
        \Plenty\Modules\Basket\Contracts\BasketRepositoryContract::class => $repo,
        \WaldorfshopSeoTest\Services\ClimateContributionService::class => $consent,
        \Plenty\Modules\Authorization\Services\AuthHelper::class => new \Plenty\Modules\Authorization\Services\AuthHelper(),
        ClimateOrderTagService::class => $tagService
    ];
    $placed = 0;
    $result = new \IO\Models\LocalizedOrder();
    $result->order = (object)['id'=>101];
    \IO\Services\OrderService::$place = function () use (&$placed, $result, $consent) {
        $placed++;
        $consent->accepted = false; // Checkout consumes its consent/basket.
        return $result;
    };
    $service = new ClimateOrderService();
    check($service->placeOrder() === $result, 'No-contribution order returns the normal checkout result');
    check($placed === 1 && $relations->writes === 0, 'No-contribution checkout places once and has no climate tag');
    $consent->accepted = true;
    check($service->placeOrder() === $result, 'Contribution checkout still returns the normal result');
    check($placed === 2 && $relations->findRelationship(88, 101, 'order') !== null,
        'Successful order receives the tag even after checkout consumes its session');
    check(isset($relations->relations['order'][101][99]), 'Existing order tags are preserved');
    $tagService->mark(101);
    check($relations->writes === 1, 'Repeated tagging does not duplicate an existing relation');
    $writes = $relations->writes;
    $consent->accepted = true;
    \IO\Services\OrderService::$place = function () { throw new \RuntimeException('Checkout failed'); };
    try { $service->placeOrder(); throw new \LogicException('Unexpected checkout success'); }
    catch (\RuntimeException $error) { check($relations->writes === $writes, 'Failed or abandoned checkout creates no tag'); }
    $result->order->id = 102;
    \IO\Services\OrderService::$place = function () use ($result) { return $result; };
    $relations->fail = true;
    check($service->placeOrder() === $result, 'Tag storage failure cannot turn an existing order into a failed checkout');
    check(count($GLOBALS['logger']->errors) === 1 && $GLOBALS['logger']->errors[0][1]['orderId'] === 102,
        'Tag failure is logged against the specific created order for recovery');
    $relations->fail = false;
    $tags->tag = null;
    try { $tagService->mark(102); throw new \LogicException('Unexpected missing-tag success'); }
    catch (\RuntimeException $error) { check($relations->writes === $writes, 'A missing configured tag causes no unrelated writes'); }
    try { $tagService->mark(0); throw new \LogicException('Unexpected invalid-order success'); }
    catch (\InvalidArgumentException $error) { check($relations->writes === $writes, 'A non-persisted order cannot be tagged'); }
    check(!($GLOBALS['unguarded'] ?? false), 'Internal tag access restores the normal authorization context');
    echo $GLOBALS['checks']." climate order tagging checks passed\n";
}
