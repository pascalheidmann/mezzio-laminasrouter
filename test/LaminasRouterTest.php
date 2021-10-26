<?php

declare(strict_types=1);

namespace MezzioTest\Router;

use Closure;
use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use Laminas\Diactoros\ServerRequest;
use Laminas\Http\Request as LaminasRequest;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\Psr7Bridge\Psr7ServerRequest;
use Laminas\Router\Http\TreeRouteStack;
use Laminas\Router\RouteMatch;
use Mezzio\Router\Exception\RuntimeException;
use Mezzio\Router\LaminasRouter;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;

class LaminasRouterTest extends TestCase
{
    use ProphecyTrait;

    /** @var TreeRouteStack|ObjectProphecy */
    private $laminasRouter;
    /** @var Route[] */
    private $routesToInject;

    protected function setUp(): void
    {
        $this->laminasRouter = $this->prophesize(TreeRouteStack::class);
    }

    private function getRouter(): LaminasRouter
    {
        return new LaminasRouter($this->laminasRouter->reveal());
    }

    private function getMiddleware(): MiddlewareInterface
    {
        return $this->prophesize(MiddlewareInterface::class)->reveal();
    }

    public function testWillLazyInstantiateALaminasTreeRouteStackIfNoneIsProvidedToConstructor(): void
    {
        $router        = new LaminasRouter();
        $laminasRouter = Closure::bind(function () {
            return $this->laminasRouter;
        }, $router, LaminasRouter::class)();
        $this->assertInstanceOf(TreeRouteStack::class, $laminasRouter);
    }

    /**
     * @return ObjectProphecy<ServerRequestInterface>
     **/
    public function createRequestProphecy(string $requestMethod = RequestMethod::METHOD_GET): ObjectProphecy
    {
        $request = $this->prophesize(ServerRequestInterface::class);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');
        $uri->__toString()->willReturn('http://www.example.com/foo');

        $request->getMethod()->willReturn($requestMethod);
        $request->getUri()->will([$uri, 'reveal']);
        $request->getHeaders()->willReturn([]);
        $request->getCookieParams()->willReturn([]);
        $request->getQueryParams()->willReturn([]);
        $request->getServerParams()->willReturn([]);

        return $request;
    }

    public function testAddingRouteAggregatesInRouter(): void
    {
        $route  = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);
        $router = $this->getRouter();
        $router->addRoute($route);

        $routesToInject = Closure::bind(function () {
            return $this->routesToInject;
        }, $router, LaminasRouter::class)();
        $this->assertContains($route, $routesToInject);
    }

    /**
     * @depends testAddingRouteAggregatesInRouter
     */
    public function testMatchingInjectsRoutesInRouter(): void
    {
        $middleware = $this->getMiddleware();
        $route      = new Route('/foo', $middleware, [RequestMethod::METHOD_GET]);

        $this->laminasRouter->addRoute('/foo^GET', [
            'type'          => 'segment',
            'options'       => [
                'route' => '/foo',
            ],
            'may_terminate' => false,
            'child_routes'  => [
                RequestMethod::METHOD_GET               => [
                    'type'    => 'method',
                    'options' => [
                        'verb'     => RequestMethod::METHOD_GET,
                        'defaults' => [
                            'middleware' => $middleware,
                        ],
                    ],
                ],
                LaminasRouter::METHOD_NOT_ALLOWED_ROUTE => [
                    'type'     => 'regex',
                    'priority' => -1,
                    'options'  => [
                        'regex'    => '',
                        'defaults' => [
                            LaminasRouter::METHOD_NOT_ALLOWED_ROUTE => '/foo',
                        ],
                        'spec'     => '',
                    ],
                ],
            ],
        ])->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        /** @var ServerRequestInterface $request */
        $request = $this->createRequestProphecy()->reveal();
        $this->laminasRouter->match(Argument::type(LaminasRequest::class))->willReturn(null);

        $router->match($request);
    }

    /**
     * @depends testAddingRouteAggregatesInRouter
     */
    public function testGeneratingUriInjectsRoutesInRouter(): void
    {
        $middleware = $this->getMiddleware();
        $route      = new Route('/foo', $middleware, [RequestMethod::METHOD_GET]);

        $this->laminasRouter->addRoute('/foo^GET', [
            'type'          => 'segment',
            'options'       => [
                'route' => '/foo',
            ],
            'may_terminate' => false,
            'child_routes'  => [
                RequestMethod::METHOD_GET               => [
                    'type'    => 'method',
                    'options' => [
                        'verb'     => RequestMethod::METHOD_GET,
                        'defaults' => [
                            'middleware' => $middleware,
                        ],
                    ],
                ],
                LaminasRouter::METHOD_NOT_ALLOWED_ROUTE => [
                    'type'     => 'regex',
                    'priority' => -1,
                    'options'  => [
                        'regex'    => '',
                        'defaults' => [
                            LaminasRouter::METHOD_NOT_ALLOWED_ROUTE => '/foo',
                        ],
                        'spec'     => '',
                    ],
                ],
            ],
        ])->shouldBeCalled();
        $this->laminasRouter->hasRoute('foo')->willReturn(true);
        $this->laminasRouter->assemble(
            [],
            [
                'name'             => 'foo',
                'only_return_path' => true,
            ]
        )->willReturn('/foo');

        $router = $this->getRouter();
        $router->addRoute($route);

        $this->assertEquals('/foo', $router->generateUri('foo'));
    }

    public function testCanSpecifyRouteOptions(): void
    {
        $middleware = $this->getMiddleware();
        $route      = new Route('/foo/:id', $middleware, [RequestMethod::METHOD_GET]);
        $route->setOptions([
            'constraints' => [
                'id' => '\d+',
            ],
            'defaults'    => [
                'bar' => 'baz',
            ],
        ]);

        $this->laminasRouter->addRoute('/foo/:id^GET', [
            'type'          => 'segment',
            'options'       => [
                'route'       => '/foo/:id',
                'constraints' => [
                    'id' => '\d+',
                ],
                'defaults'    => [
                    'bar' => 'baz',
                ],
            ],
            'may_terminate' => false,
            'child_routes'  => [
                RequestMethod::METHOD_GET               => [
                    'type'    => 'method',
                    'options' => [
                        'verb'     => RequestMethod::METHOD_GET,
                        'defaults' => [
                            'middleware' => $middleware,
                        ],
                    ],
                ],
                LaminasRouter::METHOD_NOT_ALLOWED_ROUTE => [
                    'type'     => 'regex',
                    'priority' => -1,
                    'options'  => [
                        'regex'    => '',
                        'defaults' => [
                            LaminasRouter::METHOD_NOT_ALLOWED_ROUTE => '/foo/:id',
                        ],
                        'spec'     => '',
                    ],
                ],
            ],
        ])->shouldBeCalled();

        $this->laminasRouter->hasRoute('foo')->willReturn(true);
        $this->laminasRouter->assemble(
            [],
            [
                'name'             => 'foo',
                'only_return_path' => true,
            ]
        )->willReturn('/foo');

        $router = $this->getRouter();
        $router->addRoute($route);
        $router->generateUri('foo');
    }

    public function routeResults(): array
    {
        /** @var MiddlewareInterface $middleware */
        $middleware = $this->prophesize(MiddlewareInterface::class)->reveal();
        return [
            'success' => [
                new Route('/foo', $middleware),
                RouteResult::fromRouteMatch('/foo', 'bar'),
            ],
            'failure' => [
                new Route('/foo', $middleware),
                RouteResult::fromRouteFailure(),
            ],
        ];
    }

    public function testMatch(): void
    {
        $middleware    = $this->getMiddleware();
        $route         = new Route('/foo', $middleware, [RequestMethod::METHOD_GET]);
        $laminasRouter = new LaminasRouter();
        $laminasRouter->addRoute($route);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );

        $result = $laminasRouter->match($request);
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertEquals('/foo^GET', $result->getMatchedRouteName());
        $this->assertEquals($middleware, $result->getMatchedRoute()->getMiddleware());
    }

    public function testReturnsRouteFailureForRouteInjectedManuallyIntoBaseRouterButNotRouterBridge(): void
    {
        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request        = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );
        $laminasRequest = Psr7ServerRequest::toLaminas($request);

        $routeMatch = new \Laminas\Router\Http\RouteMatch([], 4);
        $routeMatch->setMatchedRouteName('/foo');

        $this->laminasRouter->match($laminasRequest)->willReturn($routeMatch);

        $router = $this->getRouter();
        $result = $router->match($request);

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
    }

    public function testMatchedRouteNameWhenGetMethodAllowed(): void
    {
        $middleware = $this->getMiddleware();

        $laminasRouter = new LaminasRouter();
        $laminasRouter->addRoute(new Route('/foo', $middleware, [RequestMethod::METHOD_GET], '/foo'));

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );
        $result  = $laminasRouter->match($request);
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('/foo', $result->getMatchedRouteName());
        $this->assertSame($middleware, $result->getMatchedRoute()->getMiddleware());
    }

    /**
     * @group match
     */
    public function testSuccessfulMatchIsPossible(): void
    {
        $routeMatch = $this->prophesize(RouteMatch::class);
        $routeMatch->getMatchedRouteName()->willReturn('/foo');
        $routeMatch->getParams()->willReturn([
            'middleware' => 'bar',
        ]);

        $this->laminasRouter
            ->match(Argument::type(LaminasRequest::class))
            ->willReturn($routeMatch->reveal());
        $this->laminasRouter
            ->addRoute('/foo', Argument::type('array'))
            ->shouldBeCalled();

        $request = $this->createRequestProphecy();

        $middleware = $this->getMiddleware();
        $router     = $this->getRouter();
        $router->addRoute(new Route('/foo', $middleware, [RequestMethod::METHOD_GET], '/foo'));
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('/foo', $result->getMatchedRouteName());
        $this->assertSame($middleware, $result->getMatchedRoute()->getMiddleware());
    }

    /**
     * @group match
     */
    public function testNonSuccessfulMatchNotDueToHttpMethodsIsPossible(): void
    {
        $this->laminasRouter
            ->match(Argument::type(LaminasRequest::class))
            ->willReturn(null);

        $request = $this->createRequestProphecy();

        $router = $this->getRouter();
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
    }

    /**
     * @group match
     */
    public function testMatchFailureDueToHttpMethodReturnsRouteResultWithAllowedMethods(): void
    {
        $router = new LaminasRouter();
        $router->addRoute(new Route(
            '/foo',
            $this->getMiddleware(),
            [RequestMethod::METHOD_POST, RequestMethod::METHOD_DELETE]
        ));
        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );
        $result  = $router->match($request);

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isMethodFailure());
        $this->assertEquals([RequestMethod::METHOD_POST, RequestMethod::METHOD_DELETE], $result->getAllowedMethods());
    }

    /**
     * @group match
     */
    public function testMatchFailureDueToMethodNotAllowedWithParamsInTheRoute(): void
    {
        $router = new LaminasRouter();
        $router->addRoute(new Route(
            '/foo[/:id]',
            $this->getMiddleware(),
            [RequestMethod::METHOD_POST, RequestMethod::METHOD_DELETE]
        ));
        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo/1',
            RequestMethod::METHOD_GET
        );
        $result  = $router->match($request);

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isMethodFailure());
        $this->assertEquals([RequestMethod::METHOD_POST, RequestMethod::METHOD_DELETE], $result->getAllowedMethods());
    }

    /**
     * @group 53
     */
    public function testCanGenerateUriFromRoutes(): void
    {
        $router = new LaminasRouter();
        $route1 = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST], 'foo-create');
        $route2 = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo-list');
        $route3 = new Route('/foo/:id', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $route4 = new Route('/bar/:baz', $this->getMiddleware(), Route::HTTP_METHOD_ANY, 'bar');

        $router->addRoute($route1);
        $router->addRoute($route2);
        $router->addRoute($route3);
        $router->addRoute($route4);

        $this->assertEquals('/foo', $router->generateUri('foo-create'));
        $this->assertEquals('/foo', $router->generateUri('foo-list'));
        $this->assertEquals('/foo/bar', $router->generateUri('foo', ['id' => 'bar']));
        $this->assertEquals('/bar/BAZ', $router->generateUri('bar', ['baz' => 'BAZ']));
    }

    /**
     * @group 3
     */
    public function testPassingTrailingSlashToRouteNotExpectingItResultsIn404FailureRouteResult(): void
    {
        $router = new LaminasRouter();
        $route  = new Route('/api/ping', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'ping');

        $router->addRoute($route);
        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/api/ping/',
            RequestMethod::METHOD_GET
        );
        $result  = $router->match($request);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
    }

    public function testSuccessfulMatchingComposesRouteInRouteResult(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);

        $routeMatch = $this->prophesize(RouteMatch::class);
        $routeMatch->getMatchedRouteName()->willReturn($route->getName());
        $routeMatch->getParams()->willReturn([
            'middleware' => $route->getMiddleware(),
        ]);

        $this->laminasRouter
            ->match(Argument::type(LaminasRequest::class))
            ->willReturn($routeMatch->reveal());
        $this->laminasRouter
            ->addRoute('/foo^GET', Argument::type('array'))
            ->shouldBeCalled();

        $request = $this->createRequestProphecy();

        $router = $this->getRouter();
        $router->addRoute($route);

        $result = $router->match($request->reveal());

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame($route, $result->getMatchedRoute());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function implicitMethods(): array
    {
        return [
            'head'    => [RequestMethod::METHOD_HEAD],
            'options' => [RequestMethod::METHOD_OPTIONS],
        ];
    }

    /**
     * @dataProvider implicitMethods
     */
    public function testRoutesCanMatchImplicitHeadAndOptionsRequests(string $method): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_PUT]);

        $router = new LaminasRouter();
        $router->addRoute($route);

        $request = $this->createRequestProphecy($method);
        $result  = $router->match($request->reveal());

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame([RequestMethod::METHOD_PUT], $result->getAllowedMethods());
    }

    public function testUriGenerationMayUseOptions(): void
    {
        $route = new Route('/de/{lang}', $this->getMiddleware(), [RequestMethod::METHOD_PUT], 'test');

        $router = new LaminasRouter();
        $router->addRoute($route);

        $translator = $this->prophesize(TranslatorInterface::class);
        $translator->translate('lang', 'uri', 'de')->willReturn('found');

        $uri = $router->generateUri('test', [], [
            'translator'  => $translator->reveal(),
            'locale'      => 'de',
            'text_domain' => 'uri',
        ]);

        $this->assertEquals('/de/found', $uri);
    }

    public function testGenerateUriRaisesExceptionForNotFoundRoute(): void
    {
        $router = new LaminasRouter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('route not found');
        $router->generateUri('foo');
    }
}
