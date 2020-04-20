<?php

namespace W7\Tests;

use FastRoute\Dispatcher\GroupCountBased;
use Illuminate\Filesystem\Filesystem;
use W7\App;
use W7\App\Middleware\Dispatcher1Middleware;
use W7\App\Middleware\DispatcherMiddleware;
use W7\Core\Controller\ControllerAbstract;
use W7\Core\Dispatcher\RequestDispatcher;
use W7\Core\Middleware\ControllerMiddleware;
use W7\Core\Middleware\MiddlewareHandler;
use W7\Core\Route\RouteMapping;
use W7\Http\Message\Server\Request;
use W7\Http\Message\Server\Response;
use W7\Http\Server\Dispatcher;
use W7\Http\Server\Server;

class TestController extends ControllerAbstract {
	public function index(Request $request) {
		return $this->responseJson('test');
	}
}

class RequestDispatcherTest extends TestCase {
	public function testDispatcher() {
		$filesystem = new Filesystem();
		$filesystem->copyDirectory(__DIR__ . '/Util/Middlewares', APP_PATH . '/Middleware');

		App::$server = new Server();
		$dispatcher = new Dispatcher();
		irouter()->middleware([
			DispatcherMiddleware::class,
			Dispatcher1Middleware::class
		])->get('/test_dispatcher', function () {
			return 1;
		});

		$routeInfo = iloader()->get(RouteMapping::class)->getMapping();
		$router = new GroupCountBased($routeInfo);
		$dispatcher->setRouter($router);

		$request = new Request('GET', '/test_dispatcher');
		$response = new Response();
		icontext()->setResponse($response);

		$reflect = new \ReflectionClass($dispatcher);
		$method = $reflect->getMethod('getRoute');
		$method->setAccessible(true);
		$route = $method->invoke($dispatcher, $request);
		$request = $request->withAttribute('route', $route);

		$middleWares = $dispatcher->getMiddlewareMapping()->getRouteMiddleWares($route);
		$this->assertSame(DispatcherMiddleware::class, $middleWares[0][0]);
		$this->assertSame(Dispatcher1Middleware::class, $middleWares[1][0]);
		$this->assertSame(ControllerMiddleware::class, $middleWares[2][0]);
		$this->assertSame(\W7\Core\Middleware\LastMiddleware::class, $middleWares[3][0]);

		$middlewareHandler = new MiddlewareHandler($middleWares);
		$response = $middlewareHandler->handle($request);

		$this->assertSame('{"data":1}', $response->getBody()->getContents());

		$filesystem->delete([
			APP_PATH . '/Middleware/DispatcherMiddleware.php',
			APP_PATH . '/Middleware/Dispatcher1Middleware.php'
		]);
	}

	public function testResponseJson() {
		App::$server = new Server();
		$dispatcher = new Dispatcher();
		irouter()->get('/json-response', ['\W7\Tests\TestController', 'index']);

		$routeInfo = iloader()->get(RouteMapping::class)->getMapping();
		$router = new GroupCountBased($routeInfo);
		$dispatcher->setRouter($router);

		$request = new Request('GET', '/json-response');
		$response = new Response();
		$dispatcher = new RequestDispatcher();
		$dispatcher->setRouter($router);
		$response = $dispatcher->dispatch($request, $response);
		$this->assertSame('{"data":"test"}', $response->getBody()->getContents());
	}

	public function testIgnoreRoute() {
		$routeInfo = iloader()->get(RouteMapping::class)->getMapping();
		$router = new GroupCountBased($routeInfo);
		$dispatcher = new Dispatcher();
		$dispatcher->setRouter($router);

		App::$server = new Server();
		$request = new Request('GET', '/favicon.ico');
		$response = new Response();
		icontext()->setResponse($response);

		$reflect = new \ReflectionClass($dispatcher);
		$method = $reflect->getMethod('getRoute');
		$method->setAccessible(true);

		$route = $method->invoke($dispatcher, $request);
		$this->assertSame(true, $route['controller'] instanceof \Closure);
		$this->assertSame('system', $route['module']);
		$this->assertSame('', $route['controller']()->getBody()->getContents());
	}

	public function testUserIgnoreRoute() {
		irouter()->get('/favicon.ico', function () {
			return 'user favicon';
		});

		$routeInfo = iloader()->get(RouteMapping::class)->getMapping();
		$route = new GroupCountBased($routeInfo);
		$dispatcher = new Dispatcher();
		$dispatcher->setRouter($route);

		App::$server = new Server();
		$request = new Request('GET', '/favicon.ico');

		$reflect = new \ReflectionClass($dispatcher);
		$method = $reflect->getMethod('getRoute');
		$method->setAccessible(true);

		$route = $method->invoke($dispatcher, $request);
		$this->assertSame(true, $route['controller'] instanceof \Closure);
		$this->assertSame('system', $route['module']);
		$this->assertSame('user favicon', $route['controller']());
	}
}