<?php

namespace Tests\Feature;

use App\Exceptions\OneC\PaymentConflictException;
use App\Exceptions\OneC\PaymentLeadNotFoundException;
use App\Models\User;
use App\Services\OneC\PaymentSyncService;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OneCPaymentControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_status_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/amocrm/integrations/1c/payments/status', [
            'vin' => 'JTDBR32E720123456',
            'status' => 'paid',
        ])->assertUnauthorized();
    }

    public function test_status_endpoint_processes_paid(): void
    {
        $service = $this->mockSuccessfulSync('paid');
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/status', [
            'vin' => 'JTDBR32E720123456',
            'status' => 'paid',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'OK']);
    }

    public function test_status_endpoint_processes_unpaid(): void
    {
        $service = $this->mockSuccessfulSync('unpaid');
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/status', [
            'vin' => 'JTDBR32E720123456',
            'status' => 'unpaid',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'OK']);
    }

    public function test_new_status_endpoint_requires_status(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldNotReceive('syncPaymentStatusByVin');
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/status', [
            'vin' => 'JTDBR32E720123456',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'Validation failed')
            ->assertJsonPath('details.status.0', 'The status field is required.');
    }

    public function test_status_endpoint_rejects_unknown_status(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldNotReceive('syncPaymentStatusByVin');
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/status', [
            'vin' => 'JTDBR32E720123456',
            'status' => 'partial',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'Validation failed');
    }

    public function test_legacy_paid_endpoint_defaults_missing_status_to_paid(): void
    {
        $service = $this->mockSuccessfulSync('paid');
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/paid', [
            'vin' => 'JTDBR32E720123456',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'OK']);
    }

    public function test_legacy_paid_endpoint_accepts_explicit_unpaid(): void
    {
        $service = $this->mockSuccessfulSync('unpaid');
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/paid', [
            'vin' => 'JTDBR32E720123456',
            'status' => 'unpaid',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'OK']);
    }

    public function test_test_endpoint_performs_paid_dry_run(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldReceive('syncPaymentStatusByVin')
            ->once()
            ->with('JTDBR32E720123456', 'paid', true)
            ->andReturn([
                'status' => 'would_sync_paid',
                'paymentStatus' => 'paid',
                'vin' => 'JTDBR32E720123456',
                'dealId' => 123456,
                'updated' => false,
            ]);
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c-test/payments/paid', [
            'vin' => 'JTDBR32E720123456',
        ]);

        $response->assertOk()->assertJson([
            'status' => 'would_sync_paid',
            'paymentStatus' => 'paid',
            'environment' => 'test',
            'updated' => false,
        ]);
    }

    public function test_status_endpoint_returns_not_found_for_unknown_vin(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldReceive('syncPaymentStatusByVin')
            ->once()
            ->andThrow(new PaymentLeadNotFoundException('Сделка с VIN UNKNOWN не найдена'));
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/status', [
            'vin' => 'UNKNOWN',
            'status' => 'paid',
        ]);

        $response->assertNotFound()
            ->assertJsonPath('error', 'Сделка с VIN UNKNOWN не найдена');
    }

    public function test_status_endpoint_returns_conflict_for_unsupported_pipeline(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldReceive('syncPaymentStatusByVin')
            ->once()
            ->andThrow(new PaymentConflictException('Обработка разрешена только для основной воронки'));
        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/status', [
            'vin' => 'JTDBR32E720123456',
            'status' => 'paid',
        ]);

        $response->assertConflict();
    }

    private function mockSuccessfulSync(string $paymentStatus): PaymentSyncService
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldReceive('syncPaymentStatusByVin')
            ->once()
            ->with('JTDBR32E720123456', $paymentStatus, false)
            ->andReturn([
                'status' => $paymentStatus,
                'paymentStatus' => $paymentStatus,
                'vin' => 'JTDBR32E720123456',
                'dealId' => 123456,
                'updated' => true,
            ]);

        return $service;
    }
}
