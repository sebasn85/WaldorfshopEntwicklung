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
namespace Plenty\Plugin { class Middleware {} }
namespace Plenty\Plugin\Log {
    trait Loggable { public function getLogger($name) { return $GLOBALS['logger']; } }
}
namespace Plenty\Plugin\Http {
    class Request {
        public $method = 'POST'; public $uri = '/rest/io/order';
        public function getMethod() { return $this->method; }
        public function getRequestUri() { return $this->uri; }
    }
    class Response {
        public $code = 200; public $body;
        public function status() { return $this->code; }
        public function content() { return $this->body; }
    }
}
namespace WaldorfshopSeoTest\Services {
    class ClimateContributionService {
        public $accepted = false;
        public function selected($basket): bool { return $this->accepted; }
    }
}
namespace Plenty\Modules\Webshop\Template\Providers {
    class TemplateServiceProvider { public function getApplication() { return $GLOBALS['application']; } public function addGlobalMiddleware($class) { $GLOBALS['middleware'] = $class; } }
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
    check(!class_exists('IO\\Services\\OrderService', false), 'Bootstrap succeeds with no IO classes loaded');
    check($GLOBALS['application']->bindings === [], 'Bootstrap never replaces or eagerly loads the IO order service');
    check($GLOBALS['middleware'] === ClimateOrderService::class, 'SDK middleware observes webshop placement');
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
    $request = new \Plenty\Plugin\Http\Request();
    $response = new \Plenty\Plugin\Http\Response();
    $response->body = json_encode(['data'=>['order'=>['id'=>101]], 'events'=>[], 'notifications'=>[]]);
    $service = new ClimateOrderService();
    $service->before($request);
    check($service->after($request, $response) === $response && $relations->writes === 0,
        'No contribution preserves the response and creates no tag');
    $consent->accepted = true;
    $service->before($request);
    $consent->accepted = false; // Checkout consumes consent before returning.
    check($service->after($request, $response) === $response, 'Successful checkout response remains unchanged');
    check($relations->findRelationship(88, 101, 'order') !== null, 'Snapshot survives checkout consuming consent');
    check(isset($relations->relations['order'][101][99]), 'Unrelated order tags are preserved');
    $tagService->mark(101);
    check($relations->writes === 1, 'Repeated tagging is idempotent');
    $writes = $relations->writes;
    $response->body = json_encode(['data'=>['order'=>['id'=>102]]]);
    $service->after($request, $response);
    check($relations->writes === $writes, 'A consumed snapshot cannot tag a later response');
    $consent->accepted = true;
    foreach ([['GET','/rest/io/order'], ['POST','/rest/orders'], ['POST','/rest/io/order/102'],
              ['POST','/rest/waldorfshop/climate-contribution'], ['POST','/checkout']] as $route) {
        $request->method = $route[0]; $request->uri = $route[1];
        $service->before($request); $service->after($request, $response);
        check($relations->writes === $writes, 'Non-placement route must never receive a tag');
    }
    $request->method = 'POST'; $request->uri = '/rest/io/order';
    foreach ([400, 422, 500, 302] as $status) {
        $response->code = $status;
        $service->before($request);
        check($service->after($request, $response) === $response && $relations->writes === $writes,
            'Failed or redirected checkout never receives a tag');
    }
    $response->code = 200;
    foreach (['not json', '{}', '{"data":{"order":{"id":0}}}', '{"data":null}'] as $body) {
        $response->body = $body;
        $service->before($request); $service->after($request, $response);
        check($relations->writes === $writes, 'Response without a persisted order ID cannot be tagged');
    }
    $request->uri = '/rest/io/order/?initialRestCall=true';
    $response->body = json_encode(['data'=>['order'=>['id'=>102]]]);
    $relations->fail = true;
    $service->before($request);
    check($service->after($request, $response) === $response, 'Tag error cannot change an existing order response');
    check(count($GLOBALS['logger']->errors) === 1 && $GLOBALS['logger']->errors[0][1]['orderId'] === 102,
        'Storage failure logs the correct order for recovery');
    $relations->fail = false;
    $service->before($request); $service->after($request, $response);
    check($relations->findRelationship(88, 102, 'order') !== null, 'Placement with query string receives its tag');
    $writes = $relations->writes;
    $tags->tag = null;
    try { $tagService->mark(103); throw new \LogicException('Unexpected missing-tag success'); }
    catch (\RuntimeException $error) { check($relations->writes === $writes, 'Missing tag cannot cause unrelated writes'); }
    try { $tagService->mark(0); throw new \LogicException('Unexpected invalid-order success'); }
    catch (\InvalidArgumentException $error) { check($relations->writes === $writes, 'Invalid order cannot be tagged'); }
    check(!($GLOBALS['unguarded'] ?? false), 'Tag access restores the normal authorization context');
    echo $GLOBALS['checks']." climate order tagging checks passed\n";
}
