<?php

namespace Label84\TagManager\Tests;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Collection;
use Label84\TagManager\Http\Middleware\TagManagerMiddleware;
use Label84\TagManager\TagManager;
use Label84\TagManager\TagManagerServiceProvider;
use Label84\TagManager\View\Components\Head;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class TagManagerMiddlewareTest extends TestCase
{
    private ArraySessionHandler $handler;

    private Store $session;

    protected function getPackageProviders($app): array
    {
        return [TagManagerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tagmanager.id', 'GTM-TEST');
        config()->set('tagmanager.enabled', true);
        $this->handler = new ArraySessionHandler(120);
        $this->session = new Store('testing', $this->handler, str_repeat('a', 40));
        $this->session->start();
    }

    public function test_empty_tracking_data_does_not_accumulate_across_requests(): void
    {
        for ($request = 0; $request < 40; $request++) {
            $this->handleRequest();
        }

        $this->assertFalse($this->session->has('tagmanager'));
        $this->assertNotContains('tagmanager', $this->session->get('_flash.old', []));
    }

    public function test_pending_collection_events_are_read_without_internal_properties(): void
    {
        $events = [$this->purchaseEvent()];
        $this->session->flash('tagmanager', collect($events));
        $this->persistSession();

        $this->handleRequest(function (TagManager $manager) use ($events): Response {
            $this->assertSame($events, $manager->get()->all());

            return new RedirectResponse('/next');
        });

        $this->assertSame($events, $this->session->get('tagmanager'));
    }

    public function test_pending_array_events_are_preserved_across_redirects(): void
    {
        $events = [$this->purchaseEvent()];
        $this->session->flash('tagmanager', $events);
        $this->persistSession();

        for ($request = 0; $request < 3; $request++) {
            $this->handleRequest();
            $this->assertSame($events, $this->session->get('tagmanager'));
        }
    }

    public function test_new_events_are_stored_as_plain_arrays(): void
    {
        $event = $this->purchaseEvent();
        $this->handleRequest(function (TagManager $manager) use ($event): Response {
            $manager->push($event);

            return new RedirectResponse('/next');
        });

        $this->assertSame([$event], $this->session->get('tagmanager'));
    }

    public function test_empty_legacy_wrappers_are_removed_without_clearing_the_session(): void
    {
        $this->session->put('cart_token', 'synthetic-cart');
        $this->session->put('login_web', 123);
        $this->session->flash('tagmanager', $this->legacyCollection([]));
        $this->persistSession();

        $this->handleRequest();

        $this->assertFalse($this->session->has('tagmanager'));
        $this->assertSame('synthetic-cart', $this->session->get('cart_token'));
        $this->assertSame(123, $this->session->get('login_web'));
    }

    public function test_legacy_cleanup_preserves_events_inside_and_beside_wrappers(): void
    {
        $purchase = $this->purchaseEvent();
        $contact = ['event' => 'contact_email'];
        $legacy = $this->legacyCollection([$purchase]);
        $legacy->push($contact);
        $this->session->flash('tagmanager', $legacy);
        $this->persistSession();

        $this->handleRequest(function (TagManager $manager) use ($purchase, $contact): Response {
            $this->assertSame([$purchase, $contact], $manager->get()->all());

            return new RedirectResponse('/next');
        });

        $this->assertSame([$purchase, $contact], $this->session->get('tagmanager'));
    }

    public function test_legacy_wrappers_stored_as_arrays_are_repaired(): void
    {
        $event = $this->purchaseEvent();
        $this->session->flash('tagmanager', $this->legacyCollection([$event])->all());
        $this->persistSession();

        $this->handleRequest();

        $this->assertSame([$event], $this->session->get('tagmanager'));
    }

    public function test_associative_tracking_keys_are_preserved(): void
    {
        $data = ['currency' => 'USD', 'page_type' => 'product'];
        $this->session->flash('tagmanager', collect($data));
        $this->persistSession();

        $this->handleRequest();

        $this->assertSame($data, $this->session->get('tagmanager'));
    }

    public function test_custom_session_key_is_used(): void
    {
        config()->set('tagmanager.session_name', 'tracking');
        $event = $this->purchaseEvent();
        $this->session->flash('tracking', $this->legacyCollection([$event]));
        $this->persistSession();

        $this->handleRequest();

        $this->assertSame([$event], $this->session->get('tracking'));
        $this->assertFalse($this->session->has('tagmanager'));
    }

    public function test_events_are_delivered_once_when_the_head_renders_after_redirects(): void
    {
        $event = $this->purchaseEvent();
        $this->session->flash('tagmanager', $this->legacyCollection([$event]));
        $this->persistSession();
        $this->handleRequest();
        $this->handleRequest();

        $delivered = [];
        $renderHead = function (TagManager $manager) use (&$delivered): Response {
            $head = new Head($manager, $this->session);
            $delivered = array_merge($delivered, $head->render()->getData()['data']->all());

            return new Response('rendered');
        };
        $this->handleRequest($renderHead);
        $this->handleRequest($renderHead);

        $this->assertSame([$event], $delivered);
        $this->assertFalse($this->session->has('tagmanager'));
    }

    public function test_the_original_response_is_returned(): void
    {
        $response = new Response('unchanged', 201);

        $this->assertSame($response, $this->handleRequest(fn () => $response));
    }

    private function handleRequest(?Closure $action = null): Response
    {
        $manager = new TagManager;
        $middleware = new TagManagerMiddleware($manager, $this->session);
        $request = Request::create('/products/example');
        $request->setLaravelSession($this->session);
        $response = $middleware->handle(
            $request,
            fn () => $action ? $action($manager) : new RedirectResponse('/next'),
        );
        $this->persistSession();

        return $response;
    }

    private function persistSession(): void
    {
        $id = $this->session->getId();
        $this->session->save();
        $this->session = new Store('testing', $this->handler, $id);
        $this->session->start();
    }

    private function legacyCollection(array $events): Collection
    {
        $data = collect($events);

        for ($layer = 0; $layer < 18; $layer++) {
            $data = collect((array) $data);
        }

        return $data;
    }

    private function purchaseEvent(): array
    {
        return [
            'event' => 'purchase',
            'ecommerce' => ['items' => [['item_id' => 'synthetic-sku', 'quantity' => 2]]],
        ];
    }
}
