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
        const LATEST_ORDER_ID = 'latestOrderId';
        public $orderId = 90;
        public function getSessionValue($key) { return $this->orderId; }
    }
}
namespace Plenty\Plugin { class Middleware {} }
namespace Plenty\Plugin\Log {
    trait Loggable { public function getLogger($name) { return $GLOBALS['logger']; } }
}
namespace Plenty\Plugin\Http {
    class Request {
        public $method = 'POST'; public $uri = '/rest/io/order'; public $parameters = [];
        public function get($key, $default = null) { return $this->parameters[$key] ?? $default; }
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
        public $errors = []; public $infos = [];
        public function info($key, $context) { $this->infos[] = [$key, $context]; }
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
    $session = new \Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract();
    $GLOBALS['services'] = [
        \Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract::class => $session,
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
    // Regression: the real Waldorfshop checkout uses GET /place-order,
    // completes the order in the session, and returns an HTML redirect.
    $request->method = 'GET';
    $response->code = 302; $response->body = '<html>Redirecting</html>';
    foreach (['', '/de', '/en', '/fr', '/it', '/es', '/nl'] as $index => $language) {
        $request->uri = $language.'/place-order/?redirectParam=checkout&orderId=999999';
        $session->orderId = 200 + $index;
        $consent->accepted = true;
        $service->before($request);
        $consent->accepted = false;
        $session->orderId = 300 + $index;
        check($service->after($request, $response) === $response, 'Real redirect response is preserved');
        check($relations->findRelationship(88, 300 + $index, 'order') !== null,
            'Real placement tags only the newly completed server-side order in every language');
        check($relations->findRelationship(88, 999999, 'order') === null, 'Query order ID is never trusted');
    }
    $writes = $relations->writes;
    $consent->accepted = true;
    $service->before($request); $service->after($request, $response);
    check($relations->writes === $writes, 'Validation redirect cannot tag a previous session order');
    $consent->accepted = false;
    $service->before($request); $session->orderId = 401; $service->after($request, $response);
    check($relations->writes === $writes, 'GET placement without contribution creates no tag');
    $consent->accepted = true;
    $service->before($request); $session->orderId = 0; $service->after($request, $response);
    check($relations->writes === $writes, 'Missing server-side order ID creates no tag');
    $service->before($request); $session->orderId = 402; $response->code = 500; $service->after($request, $response);
    check($relations->writes === $writes, 'GET server error creates no tag');
    $response->code = 302;
    foreach (['/execute-payment/403', '/bestellbestaetigung', '/checkout', '/place-order/403'] as $uri) {
        $request->uri = $uri; $service->before($request); $session->orderId++;
        $service->after($request, $response);
        check($relations->writes === $writes, 'Other GET routes cannot tag orders');
    }
    $tags->tag = null;
    try { $tagService->mark(103); throw new \LogicException('Unexpected missing-tag success'); }
    catch (\RuntimeException $error) { check($relations->writes === $writes, 'Missing tag cannot cause unrelated writes'); }
    try { $tagService->mark(0); throw new \LogicException('Unexpected invalid-order success'); }
    catch (\InvalidArgumentException $error) { check($relations->writes === $writes, 'Invalid order cannot be tagged'); }
    check(!($GLOBALS['unguarded'] ?? false), 'Tag access restores the normal authorization context');
    $request->method = 'GET'; $request->uri = '/rest/waldorfshop/climate-contribution';
    $request->parameters = ['climateMiddlewareProbe'=>'1'];
    $writes = $relations->writes;
    $probeBefore = new ClimateOrderService();
    $probeBefore->before($request);
    $probeAfter = new ClimateOrderService();
    check($probeAfter->after($request, $response) === $response, 'Diagnostic probe preserves API response');
    check($GLOBALS['logger']->errors[1][1]['beforeSeenOnThisInstance'] === true
        && $GLOBALS['logger']->errors[2][1]['beforeSeenOnThisInstance'] === false,
        'Probe exposes separate middleware instances without placing or tagging an order');
    check($relations->writes === $writes, 'Diagnostic probe never writes tags');
    echo $GLOBALS['checks']." climate order tagging checks passed\n";
}
