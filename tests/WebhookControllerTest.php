<?php

namespace Spatie\WebhookClient\Tests;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\WebhookClient\Events\InvalidWebhookSignatureEvent;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\Tests\TestClasses\CustomRespondsToWebhook;
use Spatie\WebhookClient\Tests\TestClasses\EverythingIsValidSignatureValidator;
use Spatie\WebhookClient\Tests\TestClasses\NothingIsValidSignatureValidator;
use Spatie\WebhookClient\Tests\TestClasses\ProcessNothingWebhookProfile;
use Spatie\WebhookClient\Tests\TestClasses\ProcessWebhookJobTestClass;
use Spatie\WebhookClient\Tests\TestClasses\WebhookModelWithoutPayloadSaved;
use Spatie\WebhookClient\WebhookConfig;
use Spatie\WebhookClient\WebhookConfigRepository;

class WebhookControllerTest extends TestCase
{
    protected array $payload;

    protected array $headers;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('webhook-client.configs.0.signing_secret', 'abc123');
        config()->set('webhook-client.configs.0.process_webhook_job', ProcessWebhookJobTestClass::class);

        Route::webhooks('incoming-webhooks');

        Queue::fake();

        Event::fake();

        $this->payload = ['a' => 1];

        $this->headers = [
            config('webhook-client.configs.0.signature_header_name') => $this->determineSignature($this->payload),
        ];
    }

    #[Test]
    public function it_can_process_a_webhook_request()
    {
        $this->withoutExceptionHandling();

        $this
            ->postJson('incoming-webhooks', $this->payload, $this->headers)
            ->assertSuccessful();

        $this->assertCount(1, WebhookCall::get());
        $webhookCall = WebhookCall::first();
        $this->assertEquals('default', $webhookCall->name);
        $this->assertEquals(['a' => 1], $webhookCall->payload);

        Queue::assertPushed(ProcessWebhookJobTestClass::class, function (ProcessWebhookJobTestClass $job) {
            $this->assertEquals(1, $job->webhookCall->id);

            return true;
        });
    }

    #[Test]
    public function a_request_with_an_invalid_payload_will_not_get_processed()
    {
        $headers = $this->headers;
        $headers['Signature'] .= 'invalid';

        $this
            ->postJson('incoming-webhooks', $this->payload, $headers)
            ->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

        $this->assertCount(0, WebhookCall::get());
        Queue::assertNothingPushed();
        Event::assertDispatched(InvalidWebhookSignatureEvent::class);
    }

    #[Test]
    public function it_can_work_with_an_alternative_signature_validator()
    {
        config()->set('webhook-client.configs.0.signature_validator', EverythingIsValidSignatureValidator::class);
        $this->refreshWebhookConfigRepository();

        $this
            ->postJson('incoming-webhooks', $this->payload, [])
            ->assertStatus(200);

        config()->set('webhook-client.configs.0.signature_validator', NothingIsValidSignatureValidator::class);
        $this->refreshWebhookConfigRepository();

        $this
            ->postJson('incoming-webhooks', $this->payload, [])
            ->assertStatus(500);
    }

    #[Test]
    public function it_can_work_with_an_alternative_profile()
    {
        config()->set('webhook-client.configs.0.webhook_profile', ProcessNothingWebhookProfile::class);
        $this->refreshWebhookConfigRepository();

        $this
            ->postJson('incoming-webhooks', $this->payload, $this->headers)
            ->assertSuccessful();

        Queue::assertNothingPushed();
        Event::assertNotDispatched(InvalidWebhookSignatureEvent::class);
        $this->assertCount(0, WebhookCall::get());
    }

    #[Test]
    public function it_can_work_with_an_alternative_config()
    {
        Route::webhooks('incoming-webhooks-alternative-config', 'alternative-config');

        $this
            ->postJson('incoming-webhooks-alternative-config', $this->payload, $this->headers)
            ->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

        config()->set('webhook-client.configs.0.name', 'alternative-config');
        $this->refreshWebhookConfigRepository();

        $this
            ->postJson('incoming-webhooks-alternative-config', $this->payload, $this->headers)
            ->assertSuccessful();
    }

    #[Test]
    public function it_can_work_with_an_alternative_model()
    {
        $this->withoutExceptionHandling();

        config()->set('webhook-client.configs.0.webhook_model', WebhookModelWithoutPayloadSaved::class);
        $this->refreshWebhookConfigRepository();

        $this
            ->postJson('incoming-webhooks', $this->payload, $this->headers)
            ->assertSuccessful();

        $this->assertCount(1, WebhookCall::get());
        $this->assertEquals([], WebhookCall::first()->payload);
    }

    #[Test]
    public function it_can_respond_with_custom_response()
    {
        config()->set('webhook-client.configs.0.webhook_response', CustomRespondsToWebhook::class);

        $this
            ->postJson('incoming-webhooks', $this->payload, $this->headers)
            ->assertSuccessful()
            ->assertJson([
                'foo' => 'bar',
            ]);
    }

    #[Test]
    public function it_can_store_a_specific_header()
    {
        $this->withoutExceptionHandling();

        config()->set('webhook-client.configs.0.store_headers', ['Signature']);

        $this
            ->postJson('incoming-webhooks', $this->payload, $this->headers)
            ->assertSuccessful();

        $this->assertCount(1, WebhookCall::get());
        $this->assertCount(1, WebhookCall::first()->headers);
        $this->assertEquals($this->headers['Signature'], WebhookCall::first()->headerBag()->get('Signature'));
    }

    #[Test]
    public function it_can_store_all_headers()
    {
        $this->withoutExceptionHandling();

        config()->set('webhook-client.configs.0.store_headers', '*');

        $this
            ->postJson('incoming-webhooks', $this->payload, $this->headers)
            ->assertSuccessful();

        $this->assertCount(1, WebhookCall::get());
        $this->assertGreaterThan(1, count(WebhookCall::first()->headers));
    }

    #[Test]
    public function it_can_store_none_of_the_headers()
    {
        $this->withoutExceptionHandling();

        config()->set('webhook-client.configs.0.store_headers', []);

        $this
            ->postJson('incoming-webhooks', $this->payload, $this->headers)
            ->assertSuccessful();

        $this->assertCount(1, WebhookCall::get());
        $this->assertEquals(0, count(WebhookCall::first()->headers));
    }

    #[Test]
    public function multiple_routes_can_share_configuration()
    {
        config()->set('webhook-client.add_unique_token_to_route_name', true);

        Route::webhooks('incoming-webhooks-additional');

        $this->refreshWebhookConfigRepository();

        $routeNames = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName());

        $uniqueRouteNames = $routeNames->unique();

        $this->assertEquals($routeNames->count(), $uniqueRouteNames->count());
    }

    protected function determineSignature(array $payload): string
    {
        $secret = config('webhook-client.configs.0.signing_secret');

        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    protected function getValidPayloadAndHeaders(): array
    {
        $payload = ['a' => 1];

        $headers = [
            config('webhook-client.configs.0.signature_header_name') => $this->determineSignature($payload),
        ];

        return [$payload, $headers];
    }

    protected function refreshWebhookConfigRepository(): void
    {
        $webhookConfig = new WebhookConfig(config('webhook-client.configs.0'));

        app(WebhookConfigRepository::class)->addConfig($webhookConfig);
    }
}
