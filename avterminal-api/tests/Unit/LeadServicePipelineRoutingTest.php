<?php

namespace Tests\Unit;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\Leads\LeadsCollection;
use AmoCRM\EntitiesServices\Leads;
use AmoCRM\Filters\LeadsFilter;
use AmoCRM\Models\LeadModel;
use App\Services\AmoCRM\AmoCRMService;
use App\Services\AmoCRM\CustomFieldService;
use App\Services\AmoCRM\LeadService;
use Mockery;
use Tests\TestCase;

class LeadServicePipelineRoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_td_registration_opens_transit_russia_deal(): void
    {
        $lead = $this->lead(101, 10944982, 70000001);

        $this->expectFieldsUpdate($lead, [
            ['field_key' => 'nomer_td', 'value' => 'TD-101', 'type' => 'text'],
            ['field_key' => 'status_td', 'value' => 'ТД Зарегистрирована', 'type' => 'select'],
            ['field_key' => 'registration_date_td', 'value' => mktime(3, 30, 0, 7, 17, 2026), 'type' => 'datetime'],
        ]);

        $service = $this->makeServiceForLead($lead);
        $service
            ->shouldReceive('updateLeadStatusById')
            ->once()
            ->with(101, 86060670)
            ->andReturn($lead);

        $result = $service->updateLeadFromTDStatus(
            'VIN-101',
            'TD-101',
            'ТД Зарегистрирована',
            '2026-07-17 13:30:00'
        );

        $this->assertSame('transit_russia', $result['pipeline']);
        $this->assertTrue($result['stage_changed']);
        $this->assertSame(86060670, $result['new_stage_id']);
    }

    public function test_td_completion_closes_transit_russia_deal(): void
    {
        $lead = $this->lead(102, 10944982, 86060670);

        $this->expectFieldsUpdate($lead, [
            ['field_key' => 'status_td', 'value' => 'Транзит завершен', 'type' => 'select'],
            ['field_key' => 'completion_date_td', 'value' => mktime(2, 24, 26, 7, 17, 2026), 'type' => 'datetime'],
        ]);

        $service = $this->makeServiceForLead($lead);
        $service
            ->shouldReceive('updateLeadStatusById')
            ->once()
            ->with(102, 86372326)
            ->andReturn($lead);

        $result = $service->updateLeadFromTDStatus(
            'VIN-102',
            'TD-102',
            'Транзит завершен',
            '2026-07-17 12:24:26'
        );

        $this->assertSame('transit_russia', $result['pipeline']);
        $this->assertTrue($result['stage_changed']);
        $this->assertSame(86372326, $result['new_stage_id']);
    }

    public function test_repeated_td_registration_does_not_update_stage_twice(): void
    {
        $lead = $this->lead(108, 10944982, 86060670);

        $this->expectFieldsUpdate($lead, [
            ['field_key' => 'nomer_td', 'value' => 'TD-108', 'type' => 'text'],
            ['field_key' => 'status_td', 'value' => 'ТД Зарегистрирована', 'type' => 'select'],
            ['field_key' => 'registration_date_td', 'value' => mktime(3, 30, 0, 7, 17, 2026), 'type' => 'datetime'],
        ]);

        $service = $this->makeServiceForLead($lead);
        $service->shouldNotReceive('updateLeadStatusById');

        $result = $service->updateLeadFromTDStatus(
            'VIN-108',
            'TD-108',
            'ТД Зарегистрирована',
            '2026-07-17 13:30:00'
        );

        $this->assertFalse($result['stage_changed']);
        $this->assertNull($result['new_stage_id']);
    }

    public function test_td_registration_does_not_reopen_closed_transit(): void
    {
        $lead = $this->lead(103, 10944982, 86372326);

        $this->app->instance(CustomFieldService::class, $this->unusedCustomFieldService());

        $service = $this->makeServiceForLead($lead);
        $service->shouldNotReceive('updateLeadStatusById');

        $result = $service->updateLeadFromTDStatus(
            'VIN-103',
            'TD-103',
            'ТД Зарегистрирована',
            '2026-07-17 13:30:00'
        );

        $this->assertTrue($result['ignored']);
        $this->assertFalse($result['stage_changed']);
    }

    public function test_td_status_is_ignored_in_office_moscow_pipeline(): void
    {
        $lead = $this->lead(104, 11036918, 86718954);

        $this->app->instance(CustomFieldService::class, $this->unusedCustomFieldService());

        $service = $this->makeServiceForLead($lead);
        $service->shouldNotReceive('updateLeadStatusById');

        $result = $service->updateLeadFromTDStatus(
            'VIN-104',
            'TD-104',
            'Транзит завершен',
            '2026-07-17 12:24:26'
        );

        $this->assertSame('office_moscow', $result['pipeline']);
        $this->assertTrue($result['ignored']);
    }

    public function test_dt_registration_moves_office_moscow_deal_to_ptd_td(): void
    {
        $lead = $this->lead(105, 11036918, 70000002);

        $this->expectFieldsUpdate($lead, [
            ['field_key' => 'nomer_dt', 'value' => 'PD-105', 'type' => 'text'],
            ['field_key' => 'status_dt', 'value' => 'регистрация ПТД', 'type' => 'select'],
            ['field_key' => 'registration_date', 'value' => mktime(3, 30, 0, 7, 17, 2026), 'type' => 'datetime'],
        ]);

        $service = $this->makeServiceForLead($lead);
        $service
            ->shouldReceive('updateLeadStatusById')
            ->once()
            ->with(105, 86718954)
            ->andReturn($lead);

        $result = $service->updateLeadFromDtStatus(
            'VIN-105',
            'PD-105',
            'регистрация ПТД',
            '2026-07-17 13:30:00'
        );

        $this->assertSame('office_moscow', $result['pipeline']);
        $this->assertSame('dt_registration', $result['stage']);
        $this->assertTrue($result['stage_changed']);
    }

    public function test_dt_release_moves_office_moscow_deal_to_release(): void
    {
        $lead = $this->lead(106, 11036918, 86718954);

        $this->expectFieldsUpdate($lead, [
            ['field_key' => 'nomer_dt', 'value' => 'PD-106', 'type' => 'text'],
            ['field_key' => 'status_dt', 'value' => 'выпуск с уплатой (32)', 'type' => 'select'],
            ['field_key' => 'vipusk_date', 'value' => mktime(4, 30, 0, 7, 17, 2026), 'type' => 'datetime'],
        ]);

        $service = $this->makeServiceForLead($lead);
        $service
            ->shouldReceive('updateLeadStatusById')
            ->once()
            ->with(106, 86718958)
            ->andReturn($lead);

        $result = $service->updateLeadFromDtStatus(
            'VIN-106',
            'PD-106',
            'выпуск с уплатой',
            '2026-07-17 14:30:00'
        );

        $this->assertSame('office_moscow', $result['pipeline']);
        $this->assertSame('dt_release', $result['stage']);
        $this->assertTrue($result['stage_changed']);
    }

    public function test_dt_registration_does_not_roll_office_deal_back_from_release(): void
    {
        $lead = $this->lead(109, 11036918, 86718958);

        $this->app->instance(CustomFieldService::class, $this->unusedCustomFieldService());

        $service = $this->makeServiceForLead($lead);
        $service->shouldNotReceive('updateLeadStatusById');

        $result = $service->updateLeadFromDtStatus(
            'VIN-109',
            'PD-109',
            'регистрация ПТД',
            '2026-07-17 13:30:00'
        );

        $this->assertTrue($result['ignored']);
        $this->assertFalse($result['stage_changed']);
    }

    public function test_dt_release_without_payment_is_ignored_in_office_moscow_pipeline(): void
    {
        $lead = $this->lead(110, 11036918, 86718954);

        $this->app->instance(CustomFieldService::class, $this->unusedCustomFieldService());

        $service = $this->makeServiceForLead($lead);
        $service->shouldNotReceive('updateLeadStatusById');

        $result = $service->updateLeadFromDtStatus(
            'VIN-110',
            'PD-110',
            'выпуск без уплаты',
            '2026-07-17 14:30:00'
        );

        $this->assertTrue($result['ignored']);
        $this->assertFalse($result['stage_changed']);
    }

    public function test_dt_status_is_ignored_in_transit_russia_pipeline(): void
    {
        $lead = $this->lead(107, 10944982, 86060670);

        $this->app->instance(CustomFieldService::class, $this->unusedCustomFieldService());

        $service = $this->makeServiceForLead($lead);
        $service->shouldNotReceive('updateLeadStatusById');

        $result = $service->updateLeadFromDtStatus(
            'VIN-107',
            'PD-107',
            'регистрация ПТД',
            '2026-07-17 13:30:00'
        );

        $this->assertSame('transit_russia', $result['pipeline']);
        $this->assertTrue($result['ignored']);
    }

    public function test_main_pipeline_keeps_existing_dt_registration_route(): void
    {
        $lead = $this->lead(111, 7523034, 62360714);

        $this->expectFieldsUpdate($lead, [
            ['field_key' => 'nomer_dt', 'value' => 'PD-111', 'type' => 'text'],
            ['field_key' => 'status_dt', 'value' => 'регистрация ПТД', 'type' => 'select'],
            ['field_key' => 'registration_date', 'value' => mktime(3, 30, 0, 7, 17, 2026), 'type' => 'datetime'],
        ]);

        $service = $this->makeServiceForLead($lead);
        $service
            ->shouldReceive('updateLeadStatusById')
            ->once()
            ->with(111, 62360974)
            ->andReturn($lead);

        $result = $service->updateLeadFromDtStatus(
            'VIN-111',
            'PD-111',
            'регистрация ПТД',
            '2026-07-17 13:30:00'
        );

        $this->assertSame('main', $result['pipeline']);
        $this->assertTrue($result['stage_changed']);
    }

    public function test_vin_search_fails_when_more_than_one_connected_deal_is_found(): void
    {
        $leadOne = $this->lead(201, 10944982, 86060670);
        $leadTwo = $this->lead(202, 11036918, 86718954);
        $leadsCollection = LeadsCollection::make([$leadOne, $leadTwo]);

        $leadsApi = Mockery::mock(Leads::class);
        $leadsApi
            ->shouldReceive('get')
            ->once()
            ->with(Mockery::on(function (LeadsFilter $filter): bool {
                return $filter->getPipelineIds() === [7523034, 10944982, 11036918]
                    && $filter->getCustomFieldsValues() === [808681 => ['DUPLICATE-VIN']];
            }))
            ->andReturn($leadsCollection);

        $client = Mockery::mock(AmoCRMApiClient::class);
        $client->shouldReceive('leads')->once()->andReturn($leadsApi);

        $amoCRMService = Mockery::mock(AmoCRMService::class);
        $amoCRMService->shouldReceive('getClient')->once()->andReturn($client);

        $service = new LeadService($amoCRMService);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Найдено несколько сделок с VIN DUPLICATE-VIN в подключенных воронках: 201, 202');

        $service->findLeadByVin('DUPLICATE-VIN');
    }

    private function lead(int $id, int $pipelineId, int $statusId): LeadModel
    {
        return (new LeadModel)
            ->setId($id)
            ->setPipelineId($pipelineId)
            ->setStatusId($statusId);
    }

    private function makeServiceForLead(LeadModel $lead): LeadService
    {
        $amoCRMService = Mockery::mock(AmoCRMService::class);

        $service = Mockery::mock(LeadService::class, [$amoCRMService])->makePartial();
        $service
            ->shouldReceive('findLeadByVin')
            ->once()
            ->andReturn($lead);

        return $service;
    }

    private function expectFieldsUpdate(LeadModel $lead, array $expectedFields): void
    {
        $customFieldService = Mockery::mock(CustomFieldService::class);
        $customFieldService
            ->shouldReceive('updateLeadCustomFields')
            ->once()
            ->with($lead->getId(), $expectedFields, false)
            ->andReturn($lead);

        $this->app->instance(CustomFieldService::class, $customFieldService);
    }

    private function unusedCustomFieldService(): CustomFieldService
    {
        $customFieldService = Mockery::mock(CustomFieldService::class);
        $customFieldService->shouldNotReceive('updateLeadCustomFields');

        return $customFieldService;
    }
}
