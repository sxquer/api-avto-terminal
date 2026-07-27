<?php

namespace Tests\Unit;

use AmoCRM\Models\LeadModel;
use App\Exceptions\AmoCRM\MultipleLeadsFoundForVinException;
use App\Exceptions\OneC\PaymentConflictException;
use App\Exceptions\OneC\PaymentLeadNotFoundException;
use App\Services\AmoCRM\LeadService;
use App\Services\OneC\PaymentSyncService;
use Mockery;
use Tests\TestCase;

class PaymentSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('amocrm.onec.payment_pipeline_id', 7523034);
        config()->set('amocrm.onec.paid_status_id', 64577710);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_moves_matching_deal_to_paid_stage(): void
    {
        $lead = $this->lead(101, 7523034, 64577706);
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')
            ->once()
            ->with('JTDBR32E720123456')
            ->andReturn($lead);
        $leadService->shouldReceive('updateLeadStatusById')
            ->once()
            ->with(101, 64577710)
            ->andReturn($lead);

        $result = (new PaymentSyncService($leadService))->markPaidByVin(' jtdbr32e720123456 ');

        $this->assertSame('paid', $result['status']);
        $this->assertSame('JTDBR32E720123456', $result['vin']);
        $this->assertSame(101, $result['dealId']);
        $this->assertSame(64577706, $result['previousStatusId']);
        $this->assertSame(64577710, $result['currentStatusId']);
        $this->assertTrue($result['updated']);
    }

    public function test_repeated_notification_is_idempotent(): void
    {
        $lead = $this->lead(101, 7523034, 64577710);
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')->once()->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = (new PaymentSyncService($leadService))->markPaidByVin('JTDBR32E720123456');

        $this->assertSame('already_paid', $result['status']);
        $this->assertFalse($result['updated']);
        $this->assertSame(64577710, $result['currentStatusId']);
    }

    public function test_dry_run_does_not_update_amo(): void
    {
        $lead = $this->lead(101, 7523034, 64577706);
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')->once()->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = (new PaymentSyncService($leadService))->markPaidByVin('JTDBR32E720123456', true);

        $this->assertSame('would_mark_paid', $result['status']);
        $this->assertFalse($result['updated']);
        $this->assertSame(64577706, $result['currentStatusId']);
        $this->assertSame(64577710, $result['targetStatusId']);
    }

    public function test_terminal_deal_is_not_reopened(): void
    {
        $lead = $this->lead(101, 7523034, 142);
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')->once()->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = (new PaymentSyncService($leadService))->markPaidByVin('JTDBR32E720123456');

        $this->assertSame('ignored_terminal_stage', $result['status']);
        $this->assertFalse($result['updated']);
        $this->assertSame(142, $result['currentStatusId']);
    }

    public function test_missing_deal_returns_domain_error(): void
    {
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')->once()->andReturnNull();

        $this->expectException(PaymentLeadNotFoundException::class);

        (new PaymentSyncService($leadService))->markPaidByVin('UNKNOWN');
    }

    public function test_deal_from_another_pipeline_is_rejected(): void
    {
        $lead = $this->lead(101, 10944982, 86060670);
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')->once()->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $this->expectException(PaymentConflictException::class);

        (new PaymentSyncService($leadService))->markPaidByVin('JTDBR32E720123456');
    }

    public function test_duplicate_vin_is_returned_as_conflict(): void
    {
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')
            ->once()
            ->andThrow(new MultipleLeadsFoundForVinException('JTDBR32E720123456', [101, 102]));

        $this->expectException(PaymentConflictException::class);

        (new PaymentSyncService($leadService))->markPaidByVin('JTDBR32E720123456');
    }

    private function lead(int $id, int $pipelineId, int $statusId): LeadModel
    {
        return (new LeadModel)
            ->setId($id)
            ->setPipelineId($pipelineId)
            ->setStatusId($statusId);
    }
}
