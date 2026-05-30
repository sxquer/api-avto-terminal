<?php

namespace Tests\Feature;

use App\Models\OneCCounterpartyBuffer;
use App\Services\AmoCRM\AmoCRMService;
use App\Services\AmoCRM\CustomFieldService;
use App\Services\OneC\CounterpartyFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CounterpartyFlowServiceEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_pending_pull_is_filtered_by_environment(): void
    {
        $production = $this->createBuffer('production', 'req_production');
        $test = $this->createBuffer('test', 'req_test');

        $service = $this->makeService();

        $items = $service->getPending(50, CounterpartyFlowService::ENV_TEST);

        $this->assertCount(1, $items);
        $this->assertSame('req_test', $items[0]['requestId']);
        $this->assertSame('pending', $production->fresh()->status);
        $this->assertSame('pulled', $test->fresh()->status);
    }

    public function test_test_callback_does_not_write_back_to_amocrm(): void
    {
        $buffer = $this->createBuffer('test', 'req_test');

        $customFieldService = Mockery::mock(CustomFieldService::class);
        $customFieldService->shouldNotReceive('updateLeadCustomFields');

        $amoCRMService = Mockery::mock(AmoCRMService::class);
        $amoCRMService->shouldNotReceive('getClient');

        $service = new CounterpartyFlowService($amoCRMService, $customFieldService);

        $result = $service->processResult([
            'requestId' => 'req_test',
            'status' => 'created',
            '1cId' => 'test-1c-id',
        ], CounterpartyFlowService::ENV_TEST);

        $buffer->refresh();

        $this->assertSame('processed', $buffer->status);
        $this->assertSame('test-1c-id', $buffer->onec_counterparty_id);
        $this->assertSame('test', $result['environment']);
    }

    private function createBuffer(string $environment, string $requestId): OneCCounterpartyBuffer
    {
        return OneCCounterpartyBuffer::query()->create([
            'request_id' => $requestId,
            'environment' => $environment,
            'lead_id' => 123456,
            'contact_id' => 501,
            'company_id' => 701,
            'client_type' => 'individual',
            'vin' => 'VIN123',
            'payload_hash' => hash('sha256', $requestId),
            'payload_json' => json_encode([
                'requestId' => $requestId,
                'vin' => 'VIN123',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
        ]);
    }

    private function makeService(): CounterpartyFlowService
    {
        return new CounterpartyFlowService(
            Mockery::mock(AmoCRMService::class),
            Mockery::mock(CustomFieldService::class),
        );
    }
}
