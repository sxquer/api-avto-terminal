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

    public function test_paid_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/amocrm/integrations/1c/payments/paid', [
            'vin' => 'JTDBR32E720123456',
        ]);

        $response->assertUnauthorized();
    }

    public function test_paid_endpoint_marks_deal_as_paid(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldReceive('markPaidByVin')
            ->once()
            ->with('JTDBR32E720123456', false)
            ->andReturn([
                'status' => 'paid',
                'vin' => 'JTDBR32E720123456',
                'dealId' => 123456,
                'currentStatusId' => 64577710,
                'updated' => true,
            ]);

        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/paid', [
            'vin' => 'JTDBR32E720123456',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'OK',
            ])
            ->assertJsonMissing(['status' => 'paid'])
            ->assertJsonMissing(['dealId' => 123456])
            ->assertJsonMissing(['environment' => 'test']);
    }

    public function test_test_endpoint_performs_dry_run(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldReceive('markPaidByVin')
            ->once()
            ->with('JTDBR32E720123456', true)
            ->andReturn([
                'status' => 'would_mark_paid',
                'vin' => 'JTDBR32E720123456',
                'dealId' => 123456,
                'currentStatusId' => 64577706,
                'targetStatusId' => 64577710,
                'updated' => false,
            ]);

        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c-test/payments/paid', [
            'vin' => 'JTDBR32E720123456',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'would_mark_paid',
                'environment' => 'test',
                'updated' => false,
            ]);
    }

    public function test_paid_endpoint_validates_vin(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldNotReceive('markPaidByVin');

        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/paid', []);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'Validation failed')
            ->assertJsonPath('details.vin.0', 'The vin field is required.');
    }

    public function test_paid_endpoint_returns_not_found_for_unknown_vin(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldReceive('markPaidByVin')
            ->once()
            ->andThrow(new PaymentLeadNotFoundException('Сделка с VIN UNKNOWN не найдена'));

        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/paid', [
            'vin' => 'UNKNOWN',
        ]);

        $response->assertNotFound()
            ->assertJsonPath('error', 'Сделка с VIN UNKNOWN не найдена');
    }

    public function test_paid_endpoint_returns_conflict_for_ambiguous_vin(): void
    {
        $service = Mockery::mock(PaymentSyncService::class);
        $service->shouldReceive('markPaidByVin')
            ->once()
            ->andThrow(new PaymentConflictException('Найдено несколько сделок'));

        $this->app->instance(PaymentSyncService::class, $service);
        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/amocrm/integrations/1c/payments/paid', [
            'vin' => 'JTDBR32E720123456',
        ]);

        $response->assertConflict()
            ->assertJsonPath('error', 'Найдено несколько сделок');
    }
}
