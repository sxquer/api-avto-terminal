<?php

namespace Tests\Unit;

use AmoCRM\Models\LeadModel;
use App\Services\AmoCRM\AmoCRMService;
use App\Services\AmoCRM\CustomFieldService;
use App\Services\AmoCRM\LeadService;
use Mockery;
use Tests\TestCase;

class LeadServiceTDCompletionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_transit_completion_updates_only_status_and_completion_date(): void
    {
        $lead = (new LeadModel)
            ->setId(42)
            ->setPipelineId(7523034)
            // Этот этап входит в td_statuses_to_change, но при завершении
            // транзита карточка основной воронки всё равно не должна двигаться.
            ->setStatusId(62360714);

        $customFieldService = Mockery::mock(CustomFieldService::class);
        $customFieldService
            ->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with(
                42,
                Mockery::on(function (array $fields): bool {
                    return $fields === [
                        ['field_key' => 'status_td', 'value' => 'Транзит завершен', 'type' => 'select'],
                        [
                            'field_key' => 'completion_date_td',
                            'value' => mktime(2, 24, 26, 7, 17, 2026),
                            'type' => 'datetime',
                        ],
                    ];
                }),
                false
            )
            ->andReturn($lead);

        $this->app->instance(CustomFieldService::class, $customFieldService);

        $service = $this->makeLeadService($lead);
        $result = $service->updateLeadFromTDStatus(
            'TESTVIN123',
            '10716060/170726/TD000001',
            '  тРаНзИт ЗаВеРшЕн  ',
            '2026-07-17 12:24:26'
        );

        $this->assertFalse($result['stage_changed']);
        $this->assertSame(62360714, $result['current_stage_id']);
        $this->assertNull($result['new_stage_id']);
    }

    public function test_registration_keeps_existing_fields_and_behavior(): void
    {
        $lead = (new LeadModel)
            ->setId(43)
            ->setPipelineId(7523034)
            ->setStatusId(99999999);

        $customFieldService = Mockery::mock(CustomFieldService::class);
        $customFieldService
            ->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with(
                43,
                Mockery::on(function (array $fields): bool {
                    return $fields === [
                        ['field_key' => 'nomer_td', 'value' => 'TD-REG-001', 'type' => 'text'],
                        ['field_key' => 'status_td', 'value' => 'ТД Зарегистрирована', 'type' => 'select'],
                        [
                            'field_key' => 'registration_date_td',
                            'value' => mktime(3, 30, 0, 7, 17, 2026),
                            'type' => 'datetime',
                        ],
                    ];
                }),
                false
            )
            ->andReturn($lead);

        $this->app->instance(CustomFieldService::class, $customFieldService);

        $service = $this->makeLeadService($lead);
        $result = $service->updateLeadFromTDStatus(
            'TESTVIN456',
            'TD-REG-001',
            'ТД Зарегистрирована',
            '2026-07-17 13:30:00'
        );

        $this->assertFalse($result['stage_changed']);
        $this->assertSame(99999999, $result['current_stage_id']);
        $this->assertNull($result['new_stage_id']);
    }

    private function makeLeadService(LeadModel $lead): LeadService
    {
        $amoCRMService = Mockery::mock(AmoCRMService::class);

        $service = Mockery::mock(LeadService::class, [$amoCRMService])
            ->makePartial();
        $service
            ->shouldReceive('findLeadByVin')
            ->once()
            ->andReturn($lead);

        return $service;
    }
}
