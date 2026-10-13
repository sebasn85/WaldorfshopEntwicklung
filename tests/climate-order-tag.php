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
            if (!($GLOBALS['unguarded'] ?? false)) throw new \RuntimeException('Guest has no tag permissions');
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
namespace Plenty\Modules\Webshop\Contracts {
    class SessionStorageRepositoryContract {
        public $consent;
        public function getSessionValue($key) { return $this->consent; }
        public function setSessionValue($key, $value) { $this->consent = $value; }
    }
}
namespace Plenty\Modules\Order\Events {
    class OrderCreated {
        private $order;
        public function __construct($order) { $this->order = $order; }
        public function getOrder() { return $this->order; }
    }
}
namespace Plenty\Plugin\Log {
    trait Loggable { public function getLogger($name) { return $GLOBALS['logger']; } }
}
namespace WaldorfshopSeoTest\Services {
    class ClimateContributionService {
        const CONSENT_KEY = 'consent';
        public $active = true;
        public function enabled(): bool { return $this->active; }
    }
}
namespace Plenty\Modules\Webshop\Template\Providers {
    class TemplateServiceProvider {
        public function getApplication() { return $GLOBALS['application']; }
        public function overrideTemplate($from, $to) {}
    }
}
namespace Plenty\Plugin\Events {
    class Dispatcher {
        public $listeners = [];
        public function listen($name, $callback) { $this->listeners[$name] = $callback; }
    }
}
namespace {
    use WaldorfshopSeoTest\Services\ClimateOrderService;
    use WaldorfshopSeoTest\Services\ClimateOrderTagService;
    use Plenty\Modules\Order\Events\OrderCreated;
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
    };
    $repo = new \Plenty\Modules\Basket\Contracts\BasketRepositoryContract();
    $repo->basket = (object)['id'=>10, 'currency'=>'EUR', 'orderId'=>null,
        'basketItems'=>[(object)['variationId'=>26432, 'quantity'=>1]]];
    $session = new \Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract();
    $consent = new \WaldorfshopSeoTest\Services\ClimateContributionService();
    $tags = new \Plenty\Modules\Tag\Contracts\TagRepositoryContract();
    $tags->tag = (object)['id'=>88];
    $relations = new \Plenty\Modules\Tag\Contracts\TagRelationshipRepositoryContract();
    $relations->relations['order'][101][99] = ['tagId'=>99];
    $tagService = new ClimateOrderTagService($tags, $relations);
    $dispatcher = new \Plenty\Plugin\Events\Dispatcher();
    $service = new ClimateOrderService();
    $GLOBALS['services'] = [
        \Plenty\Modules\Basket\Contracts\BasketRepositoryContract::class=>$repo,
        \Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract::class=>$session,
        \WaldorfshopSeoTest\Services\ClimateContributionService::class=>$consent,
        \Plenty\Modules\Authorization\Services\AuthHelper::class=>new \Plenty\Modules\Authorization\Services\AuthHelper(),
        \Plenty\Plugin\Events\Dispatcher::class=>$dispatcher,
        ClimateOrderService::class=>$service, ClimateOrderTagService::class=>$tagService
    ];
    $provider = new \WaldorfshopSeoTest\Providers\SeoTestServiceProvider();
    $provider->register(); $provider->boot();
    check(!class_exists('IO\\Services\\OrderService', false), 'Bootstrap does not depend on IO inheritance');
    check(isset($dispatcher->listeners[OrderCreated::class]), 'Native order-created listener is registered');
    $order = (object)['id'=>101, 'typeId'=>1, 'orderItems'=>[
        (object)['itemVariationId'=>26432, 'quantity'=>1],
        (object)['itemVariationId'=>0, 'quantity'=>1]]];
    $event = new OrderCreated($order);
    $handler = $dispatcher->listeners[OrderCreated::class];
    $handler($event);
    check($relations->writes === 0, 'No consent creates no tag');
    $session->consent = ['basketId'=>10, 'accepted'=>true];
    $handler($event);
    check($relations->findRelationship(88,101,'order') !== null, 'Matching opted-in basket tags the persisted native-event order');
    check(isset($relations->relations['order'][101][99]), 'Other tags survive');
    check($session->consent['taggedOrderId'] === 101, 'Successful consent is bound to this one order');
    (new ClimateOrderService())->handle($event);
    check($relations->writes === 1, 'Repeated event/new listener instance cannot duplicate the tag');
    $order->id = 102;
    $writes = $relations->writes;
    foreach ([null, ['basketId'=>10,'accepted'=>false], ['basketId'=>9,'accepted'=>true],
        ['basketId'=>10,'accepted'=>'true'], ['basketId'=>10,'accepted'=>true,'taggedOrderId'=>101]] as $choice) {
        $session->consent = $choice; $handler(new OrderCreated($order));
        check($relations->writes === $writes, 'Missing/foreign/consumed consent cannot tag an order');
    }
    $session->consent = ['basketId'=>10,'accepted'=>true];
    $order->typeId = 2; $handler(new OrderCreated($order));
    check($relations->writes === $writes, 'Delivery/other order types are ignored');
    $order->typeId = 1; $order->id = 0; $handler(new OrderCreated($order));
    check($relations->writes === $writes, 'Unpersisted order cannot be tagged');
    $order->id = 102; $order->orderItems[0]->quantity = 2; $handler(new OrderCreated($order));
    check($relations->writes === $writes, 'Different merchandise quantity is ignored');
    $order->orderItems[0]->quantity = 1; $order->orderItems[0]->itemVariationId = 42; $handler(new OrderCreated($order));
    check($relations->writes === $writes, 'Another order with different merchandise is ignored');
    $order->orderItems[0]->itemVariationId = 26432;
    $repo->basket->orderId = 103; $handler(new OrderCreated($order));
    check($relations->writes === $writes, 'Basket bound to another order is ignored');
    $repo->basket->orderId = 102; $repo->basket->currency = 'CHF'; $handler(new OrderCreated($order));
    check($relations->writes === $writes, 'Non-EUR basket is ignored');
    $repo->basket->currency = 'EUR'; $consent->active = false; $handler(new OrderCreated($order));
    check($relations->writes === $writes, 'Disabled contribution is ignored');
    $consent->active = true; $relations->fail = true; $handler(new OrderCreated($order));
    check(count($GLOBALS['logger']->errors) === 1 && $GLOBALS['logger']->errors[0][1]['orderId'] === 102,
        'Storage error identifies the persisted order without failing the event');
    check(empty($session->consent['taggedOrderId']), 'Failed tagging does not consume consent');
    $relations->fail = false; $handler(new OrderCreated($order));
    check($relations->findRelationship(88,102,'order') !== null, 'Current basket already bound to this created order is supported');
    $tagService->mark(102);
    check($relations->writes === $writes + 1, 'Tag relationship writes are idempotent');
    check(!($GLOBALS['unguarded'] ?? false), 'Tag authorization restores guest context');
    echo $GLOBALS['checks']." native climate order tagging checks passed\n";
}
