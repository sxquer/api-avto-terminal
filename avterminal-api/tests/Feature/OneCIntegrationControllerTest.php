<?php

namespace Tests\Feature;

use App\Services\OneC\CounterpartyFlowService;
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
            ->with(123456, Mockery::type('array'))
            ->andReturn([
                'requestId' => 'req_manual',
                'status' => 'queued',
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
}
