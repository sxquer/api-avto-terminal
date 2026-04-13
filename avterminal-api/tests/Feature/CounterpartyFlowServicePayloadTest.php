<?php

namespace Tests\Feature;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\EntitiesServices\Companies;
use AmoCRM\EntitiesServices\Contacts;
use AmoCRM\EntitiesServices\Leads;
use AmoCRM\Models\CompanyModel;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\LeadModel;
use App\Services\AmoCRM\AmoCRMService;
use App\Services\AmoCRM\CustomFieldService;
use App\Services\OneC\CounterpartyFlowService;
use Mockery;
use Tests\TestCase;

class CounterpartyFlowServicePayloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_individual_payload_contains_deal_fields_and_dealer_details(): void
    {
        $service = $this->makeService(
            leadId: 12345,
            lead: [
                'custom_fields_values' => [
                    $this->field(808681, 'VIN123'),
                    $this->field(917427, 'Физ Лицо'),
                    $this->field(969469, 'Авто-Терминал'),
                    $this->field(808679, 'Toyota'),
                    $this->field(808675, 'Camry'),
                ],
                'contacts' => [['id' => 501]],
                'companies' => [['id' => 701]],
            ],
            contact: [
                'custom_fields_values' => [
                    $this->field(974793, 'Иванов'),
                    $this->field(974795, 'Иван'),
                    $this->field(974797, 'Иванович'),
                    $this->field(947713, '2026-04-10'),
                ],
            ],
            company: [
                'name' => 'ООО Ромашка Авто',
                'custom_fields_values' => [
                    $this->field(897733, '1234567890'),
                ],
            ],
        );

        $payload = $this->invokeBuildPayloadFromLead($service, 12345);

        $this->assertSame('VIN123', $payload['vin']);
        $this->assertSame('individual', $payload['clientType']);
        $this->assertSame('12345', $payload['deal']['dealNumber']);
        $this->assertSame('12345', $payload['deal']['contractNumber']);
        $this->assertSame('2026-04-10', $payload['deal']['contractDate']);
        $this->assertSame('Авто-Терминал', $payload['deal']['warehouse']);
        $this->assertSame('Toyota', $payload['deal']['brand']);
        $this->assertSame('Camry', $payload['deal']['model']);
        $this->assertSame('ООО Ромашка Авто', $payload['client']['dealerName']);
        $this->assertSame('1234567890', $payload['client']['dealerInn']);
    }

    public function test_legal_payload_uses_company_contract_date_without_dealer_fields(): void
    {
        $service = $this->makeService(
            leadId: 777,
            lead: [
                'custom_fields_values' => [
                    $this->field(808681, 'VIN999'),
                    $this->field(917427, 'Юр. лицо'),
                    $this->field(969469, 'Логистический терминал'),
                    $this->field(808679, 'Lexus'),
                    $this->field(808675, 'RX'),
                ],
                'contacts' => [['id' => 502]],
                'companies' => [['id' => 702]],
            ],
            contact: [
                'custom_fields_values' => [],
            ],
            company: [
                'name' => 'ООО Импортер',
                'custom_fields_values' => [
                    $this->field(919495, '2026-03-15'),
                    $this->field(897733, '9876543210'),
                    $this->field(897735, '123456789'),
                ],
            ],
        );

        $payload = $this->invokeBuildPayloadFromLead($service, 777);

        $this->assertSame('legal', $payload['clientType']);
        $this->assertSame('2026-03-15', $payload['deal']['contractDate']);
        $this->assertSame('Логистический терминал', $payload['deal']['warehouse']);
        $this->assertSame('Lexus', $payload['deal']['brand']);
        $this->assertSame('RX', $payload['deal']['model']);
        $this->assertSame('ООО Импортер', $payload['client']['name']);
        $this->assertSame('9876543210', $payload['client']['inn']);
        $this->assertArrayNotHasKey('dealerName', $payload['client']);
        $this->assertArrayNotHasKey('dealerInn', $payload['client']);
    }

    private function makeService(int $leadId, array $lead, array $contact, array $company): CounterpartyFlowService
    {
        $leadModel = Mockery::mock(LeadModel::class);
        $leadModel->shouldReceive('toArray')->andReturn($lead);

        $contactModel = Mockery::mock(ContactModel::class);
        $contactModel->shouldReceive('toArray')->andReturn($contact);

        $companyModel = Mockery::mock(CompanyModel::class);
        $companyModel->shouldReceive('toArray')->andReturn($company);

        $leadsApi = Mockery::mock(Leads::class);
        $leadsApi->shouldReceive('getOne')
            ->with($leadId, ['contacts', 'companies'])
            ->andReturn($leadModel);

        $contactsApi = Mockery::mock(Contacts::class);
        $contactsApi->shouldReceive('getOne')
            ->with($lead['contacts'][0]['id'])
            ->andReturn($contactModel);

        $companiesApi = Mockery::mock(Companies::class);
        $companiesApi->shouldReceive('getOne')
            ->with($lead['companies'][0]['id'])
            ->andReturn($companyModel);

        $apiClient = Mockery::mock(AmoCRMApiClient::class);
        $apiClient->shouldReceive('leads')->andReturn($leadsApi);
        $apiClient->shouldReceive('contacts')->andReturn($contactsApi);
        $apiClient->shouldReceive('companies')->andReturn($companiesApi);

        $amoCRMService = Mockery::mock(AmoCRMService::class);
        $amoCRMService->shouldReceive('getClient')->andReturn($apiClient);

        $customFieldService = Mockery::mock(CustomFieldService::class);

        return new CounterpartyFlowService($amoCRMService, $customFieldService);
    }

    private function invokeBuildPayloadFromLead(CounterpartyFlowService $service, int $leadId): array
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildPayloadFromLead');
        $method->setAccessible(true);

        return $method->invoke($service, $leadId, []);
    }

    private function field(int $fieldId, mixed $value): array
    {
        return [
            'field_id' => $fieldId,
            'values' => [
                ['value' => $value],
            ],
        ];
    }
}
