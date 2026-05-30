<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OneC\CounterpartyFlowService;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OneCIntegrationControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_manual_card_trigger_is_not_blocked_by_non_contract_stage(): void
    {
        config()->set('amocrm.onec.webhook_secret', 'test-secret');

        $flowService = Mockery::mock(CounterpartyFlowService::class);
        $flowService->shouldReceive('enqueueFromLead')
            ->once()
            ->with(123456, Mockery::type('array'), CounterpartyFlowService::ENV_PRODUCTION)
            ->andReturn([
                'requestId' => 'req_manual',
                'status' => 'queued',
                'environment' => 'production',
            ]);

        $this->app->instance(CounterpartyFlowService::class, $flowService);

        $response = $this->postJson('/api/amocrm/deals/contract-ready?secret=test-secret', [
            'lead' => [
                'id' => 123456,
                'pipeline_id' => 7523034,
                'status_id' => 64976646,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'requestId' => 'req_manual',
                'status' => 'queued',
                'source' => 'amo_button',
            ]);
    }

    public function test_test_manual_card_trigger_is_queued_in_test_environment(): void
    {
        config()->set('amocrm.onec.test_webhook_secret', 'test-secret');

        $flowService = Mockery::mock(CounterpartyFlowService::class);
        $flowService->shouldReceive('enqueueFromLead')
            ->once()
            ->with(123456, Mockery::type('array'), CounterpartyFlowService::ENV_TEST)
            ->andReturn([
                'requestId' => 'req_test',
                'status' => 'queued',
                'environment' => 'test',
            ]);

        $this->app->instance(CounterpartyFlowService::class, $flowService);

        $response = $this->postJson('/api/amocrm/deals/contract-ready-test?secret=test-secret', [
            'lead' => [
                'id' => 123456,
                'pipeline_id' => 7523034,
                'status_id' => 64976646,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'requestId' => 'req_test',
                'status' => 'queued',
                'environment' => 'test',
                'source' => 'amo_button',
            ]);
    }

    public function test_stage_webhook_is_still_ignored_outside_contract_stage(): void
    {
        config()->set('amocrm.onec.webhook_secret', 'test-secret');

        $flowService = Mockery::mock(CounterpartyFlowService::class);
        $flowService->shouldNotReceive('enqueueFromLead');

        $this->app->instance(CounterpartyFlowService::class, $flowService);

        $response = $this->postJson('/api/amocrm/deals/contract-ready?secret=test-secret', [
            'leads' => [
                'status' => [
                    [
                        'id' => 123456,
                        'pipeline_id' => 7523034,
                        'status_id' => 64976646,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ignored',
                'reason' => 'event is not for contract stage',
                'dealId' => 123456,
                'source' => 'amo_status_webhook',
            ]);
    }

    public function test_test_pending_endpoint_uses_test_environment(): void
    {
        $flowService = Mockery::mock(CounterpartyFlowService::class);
        $flowService->shouldReceive('getPending')
            ->once()
            ->with(25, CounterpartyFlowService::ENV_TEST)
            ->andReturn([
                ['requestId' => 'req_test'],
            ]);

        $this->app->instance(CounterpartyFlowService::class, $flowService);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->getJson('/api/amocrm/integrations/1c-test/contacts/pending?limit=25');

        $response->assertOk()
            ->assertJson([
                'count' => 1,
                'environment' => 'test',
                'items' => [
                    ['requestId' => 'req_test'],
                ],
            ]);
    }

    public function test_test_result_endpoint_uses_test_environment(): void
    {
        $flowService = Mockery::mock(CounterpartyFlowService::class);
        $flowService->shouldReceive('processResult')
            ->once()
            ->with(Mockery::on(fn (array $payload) => $payload['requestId'] === 'req_test'), CounterpartyFlowService::ENV_TEST)
            ->andReturn([
                'requestId' => 'req_test',
                'leadId' => 123456,
                'status' => 'processed',
                'environment' => 'test',
            ]);

        $this->app->instance(CounterpartyFlowService::class, $flowService);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c-test/contacts/result', [
            'requestId' => 'req_test',
            'status' => 'created',
            '1cId' => 'test-1c-id',
        ]);

        $response->assertOk()
            ->assertJson([
                'requestId' => 'req_test',
                'status' => 'processed',
                'environment' => 'test',
            ]);
    }
}
