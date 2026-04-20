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
                    $this->field(0, 'ivan@example.com', 'EMAIL', 'Email'),
                    $this->field(808697, 'г. Владивосток, ул. Светланская, д. 1'),
                    $this->field(0, '690066', null, 'Индекс'),
                    $this->field(0, 'Приморский край', null, 'Субъект федерации'),
                    $this->field(0, 'Ленинский', null, 'Район'),
                    $this->field(0, 'Владивосток', null, 'Город'),
                    $this->field(0, 'пос. Трудовое', null, 'Населенный пункт'),
                    $this->field(0, 'Светланская', null, 'Улица'),
                    $this->field(0, '1', null, 'Дом'),
                    $this->field(0, '12', null, 'Квартира'),
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
        $this->assertSame('ivan@example.com', $payload['client']['email']);
        $this->assertSame('г. Владивосток, ул. Светланская, д. 1', $payload['client']['registrationAddress']);
        $this->assertSame(
            '690066, Приморский край, Ленинский, Владивосток, пос. Трудовое, Светланская, 1, 12',
            $payload['client']['address']
        );
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
                    $this->field(897747, 'г. Москва, ул. Тверская, д. 10'),
                    $this->field(0, 'corp@example.com', 'EMAIL', 'Рабочий email'),
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
        $this->assertSame('corp@example.com', $payload['client']['email']);
        $this->assertSame('9876543210', $payload['client']['inn']);
        $this->assertSame('г. Москва, ул. Тверская, д. 10', $payload['client']['legalAddress']);
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

    private function field(int $fieldId, mixed $value, ?string $fieldCode = null, ?string $fieldName = null): array
    {
        $field = [
            'field_id' => $fieldId,
            'values' => [
                ['value' => $value],
            ],
        ];

        if ($fieldCode !== null) {
            $field['field_code'] = $fieldCode;
        }

        if ($fieldName !== null) {
            $field['field_name'] = $fieldName;
        }

        return $field;
    }
}
