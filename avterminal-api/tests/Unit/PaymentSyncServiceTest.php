<?php

namespace Tests\Unit;

use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Models\CustomFieldsValues\CheckboxCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\SelectCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\CheckboxCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\SelectCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\CheckboxCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\SelectCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use App\Exceptions\AmoCRM\MultipleLeadsFoundForVinException;
use App\Exceptions\OneC\PaymentConflictException;
use App\Exceptions\OneC\PaymentLeadNotFoundException;
use App\Services\AmoCRM\CustomFieldService;
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
        config()->set('amocrm.fields.uss_invoice_issued.id', 990217);
        config()->set('amocrm.fields.uss_invoice_paid.id', 990219);
        config()->set('amocrm.fields.color_field_id.id', 974799);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_paid_enables_paid_checkbox_sets_green_and_keeps_stage(): void
    {
        $lead = $this->lead(101, 7523034, 64577706, true, false, 'Красный');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);

        $customFieldService->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with(101, [
                ['field_key' => 'uss_invoice_paid', 'value' => true, 'type' => 'checkbox'],
                ['field_key' => 'color_field_id', 'value' => 'Зеленый', 'type' => 'select'],
            ])
            ->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin(' jtdbr32e720123456 ', 'paid');

        $this->assertSame('paid', $result['status']);
        $this->assertSame('paid', $result['paymentStatus']);
        $this->assertSame('JTDBR32E720123456', $result['vin']);
        $this->assertSame(64577706, $result['currentStatusId']);
        $this->assertNull($result['targetStatusId']);
        $this->assertSame(['uss_invoice_paid', 'color_field_id'], $result['updatedFields']);
        $this->assertFalse($result['stageChanged']);
        $this->assertTrue($result['updated']);
    }

    public function test_paid_enables_issued_checkbox_when_it_was_missing(): void
    {
        $lead = $this->lead(101, 7523034, 64577706, null, false, null);
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);

        $customFieldService->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with(101, [
                ['field_key' => 'uss_invoice_issued', 'value' => true, 'type' => 'checkbox'],
                ['field_key' => 'uss_invoice_paid', 'value' => true, 'type' => 'checkbox'],
                ['field_key' => 'color_field_id', 'value' => 'Зеленый', 'type' => 'select'],
            ])
            ->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'paid');

        $this->assertSame(
            ['uss_invoice_issued', 'uss_invoice_paid', 'color_field_id'],
            $result['updatedFields']
        );
    }

    public function test_repeated_paid_notification_is_idempotent(): void
    {
        $lead = $this->lead(101, 7523034, 64577710, true, true, 'Зеленый');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);
        $customFieldService->shouldNotReceive('updateLeadCustomFields');
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'paid');

        $this->assertSame('already_synced', $result['status']);
        $this->assertFalse($result['stageChanged']);
        $this->assertFalse($result['updated']);
    }

    public function test_paid_corrects_color_even_when_checkboxes_are_already_enabled(): void
    {
        $lead = $this->lead(101, 7523034, 64577710, true, true, 'Красный');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);
        $customFieldService->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with(101, [
                ['field_key' => 'color_field_id', 'value' => 'Зеленый', 'type' => 'select'],
            ])
            ->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'paid');

        $this->assertSame(['color_field_id'], $result['updatedFields']);
        $this->assertTrue($result['updated']);
    }

    public function test_unpaid_disables_paid_checkbox_sets_red_and_keeps_stage(): void
    {
        $lead = $this->lead(101, 7523034, 64577710, true, true, 'Зеленый');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);

        $customFieldService->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with(101, [
                ['field_key' => 'uss_invoice_paid', 'value' => false, 'type' => 'checkbox'],
                ['field_key' => 'color_field_id', 'value' => 'Красный', 'type' => 'select'],
            ])
            ->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'unpaid');

        $this->assertSame('unpaid', $result['status']);
        $this->assertSame(64577710, $result['currentStatusId']);
        $this->assertNull($result['targetStatusId']);
        $this->assertFalse($result['stageChanged']);
        $this->assertTrue($result['updated']);
    }

    public function test_unpaid_enables_issued_checkbox_when_it_was_missing(): void
    {
        $lead = $this->lead(101, 7523034, 62360726, null, null, 'Красный');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);

        $customFieldService->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with(101, [
                ['field_key' => 'uss_invoice_issued', 'value' => true, 'type' => 'checkbox'],
            ])
            ->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'unpaid');

        $this->assertSame(['uss_invoice_issued'], $result['updatedFields']);
    }

    public function test_repeated_unpaid_notification_is_idempotent(): void
    {
        $lead = $this->lead(101, 7523034, 62360726, true, false, 'Красный');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);
        $customFieldService->shouldNotReceive('updateLeadCustomFields');
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'unpaid');

        $this->assertSame('already_synced', $result['status']);
        $this->assertFalse($result['updated']);
    }

    public function test_unpaid_corrects_color_even_when_checkboxes_are_already_correct(): void
    {
        $lead = $this->lead(101, 7523034, 62360726, true, false, 'Зеленый');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);
        $customFieldService->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with(101, [
                ['field_key' => 'color_field_id', 'value' => 'Красный', 'type' => 'select'],
            ])
            ->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'unpaid');

        $this->assertSame(['color_field_id'], $result['updatedFields']);
        $this->assertTrue($result['updated']);
    }

    public function test_paid_on_terminal_stage_updates_fields_and_keeps_stage(): void
    {
        $lead = $this->lead(101, 7523034, 142, true, false, 'Красный');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);
        $customFieldService->shouldReceive('updateLeadCustomFields')->once()->andReturn($lead);
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'paid');

        $this->assertSame('paid', $result['status']);
        $this->assertSame(142, $result['currentStatusId']);
        $this->assertFalse($result['stageChanged']);
        $this->assertTrue($result['updated']);
    }

    public function test_dry_run_reports_changes_without_updating_amo(): void
    {
        $lead = $this->lead(101, 7523034, 64577706, true, false, 'Красный');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);
        $customFieldService->shouldNotReceive('updateLeadCustomFields');
        $leadService->shouldNotReceive('updateLeadStatusById');

        $result = $service->syncPaymentStatusByVin('JTDBR32E720123456', 'paid', true);

        $this->assertSame('would_sync_paid', $result['status']);
        $this->assertSame(['uss_invoice_paid', 'color_field_id'], $result['updatedFields']);
        $this->assertFalse($result['stageChanged']);
        $this->assertFalse($result['updated']);
    }

    public function test_missing_deal_returns_domain_error(): void
    {
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')->once()->andReturnNull();
        $customFieldService = Mockery::mock(CustomFieldService::class);

        $this->expectException(PaymentLeadNotFoundException::class);

        (new PaymentSyncService($leadService, $customFieldService))
            ->syncPaymentStatusByVin('UNKNOWN', 'paid');
    }

    public function test_deal_from_another_pipeline_is_rejected_without_updates(): void
    {
        $lead = $this->lead(101, 10944982, 86060670, true, false, 'Красный');
        [$service, $leadService, $customFieldService] = $this->serviceForLead($lead);
        $customFieldService->shouldNotReceive('updateLeadCustomFields');
        $leadService->shouldNotReceive('updateLeadStatusById');

        $this->expectException(PaymentConflictException::class);

        $service->syncPaymentStatusByVin('JTDBR32E720123456', 'paid');
    }

    public function test_duplicate_vin_is_returned_as_conflict(): void
    {
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')
            ->once()
            ->andThrow(new MultipleLeadsFoundForVinException('JTDBR32E720123456', [101, 102]));
        $customFieldService = Mockery::mock(CustomFieldService::class);

        $this->expectException(PaymentConflictException::class);

        (new PaymentSyncService($leadService, $customFieldService))
            ->syncPaymentStatusByVin('JTDBR32E720123456', 'paid');
    }

    private function serviceForLead(LeadModel $lead): array
    {
        $leadService = Mockery::mock(LeadService::class);
        $leadService->shouldReceive('findLeadByVin')->once()->andReturn($lead);
        $customFieldService = Mockery::mock(CustomFieldService::class);

        return [new PaymentSyncService($leadService, $customFieldService), $leadService, $customFieldService];
    }

    private function lead(
        int $id,
        int $pipelineId,
        int $statusId,
        ?bool $issued,
        ?bool $paid,
        ?string $color
    ): LeadModel {
        $fields = new CustomFieldsValuesCollection;

        if ($issued !== null) {
            $fields->add($this->checkboxField(990217, $issued));
        }
        if ($paid !== null) {
            $fields->add($this->checkboxField(990219, $paid));
        }
        if ($color !== null) {
            $fields->add($this->selectField(974799, $color));
        }

        return (new LeadModel)
            ->setId($id)
            ->setPipelineId($pipelineId)
            ->setStatusId($statusId)
            ->setCustomFieldsValues($fields);
    }

    private function checkboxField(int $fieldId, bool $value): CheckboxCustomFieldValuesModel
    {
        $valueModel = (new CheckboxCustomFieldValueModel)->setValue($value);

        return (new CheckboxCustomFieldValuesModel)
            ->setFieldId($fieldId)
            ->setValues((new CheckboxCustomFieldValueCollection)->add($valueModel));
    }

    private function selectField(int $fieldId, string $value): SelectCustomFieldValuesModel
    {
        $valueModel = (new SelectCustomFieldValueModel)->setValue($value);

        return (new SelectCustomFieldValuesModel)
            ->setFieldId($fieldId)
            ->setValues((new SelectCustomFieldValueCollection)->add($valueModel));
    }
}
