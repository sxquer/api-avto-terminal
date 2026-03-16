<?php

namespace App\Services\OneC;

use AmoCRM\Collections\NotesCollection;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Models\NoteType\ServiceMessageNote;
use App\Models\OneCCounterpartyBuffer;
use App\Models\OneCCounterpartySyncEvent;
use App\Services\AmoCRM\AmoCRMService;
use App\Services\AmoCRM\CustomFieldService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CounterpartyFlowService
{
    public function __construct(
        private AmoCRMService $amoCRMService,
        private CustomFieldService $customFieldService
    ) {}

    /**
     * Собирает данные по сделке и ставит контрагента в буфер на отдачу в 1С.
     */
    public function enqueueFromLead(int $leadId): array
    {
        $payload = $this->buildPayloadFromLead($leadId);
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));

        // Схлопываем повторные события по одному lead, пока запись не обработана 1С.
        $active = OneCCounterpartyBuffer::query()
            ->where('lead_id', $leadId)
            ->whereIn('status', ['pending', 'pulled'])
            ->latest('id')
            ->first();

        if ($active) {
            return [
                'requestId' => $active->request_id,
                'status' => 'already_buffered',
            ];
        }

        // Если ранее такой же payload уже успешно обработан, повторно не ставим в буфер.
        $existingProcessed = OneCCounterpartyBuffer::query()
            ->where('lead_id', $leadId)
            ->where('payload_hash', $payloadHash)
            ->where('status', 'processed')
            ->latest('id')
            ->first();

        if ($existingProcessed) {
            return [
                'requestId' => $existingProcessed->request_id,
                'status' => 'already_buffered',
            ];
        }

        $requestId = 'req_' . Str::lower(Str::random(12));
        $payload['requestId'] = $requestId;

        $buffer = OneCCounterpartyBuffer::query()->create([
            'request_id' => $requestId,
            'lead_id' => $leadId,
            'contact_id' => $payload['_meta']['contactId'] ?? null,
            'company_id' => $payload['_meta']['companyId'] ?? null,
            'client_type' => $payload['clientType'],
            'vin' => $payload['vin'] ?? null,
            'payload_hash' => $payloadHash,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
        ]);

        OneCCounterpartySyncEvent::query()->create([
            'buffer_id' => $buffer->id,
            'event_type' => 'enqueued',
            'attempt_no' => 1,
            'request_id' => $requestId,
            'request_payload' => $buffer->payload_json,
            'result' => 'success',
        ]);

        $this->addLeadComment($leadId, sprintf(
            "[1C] Контрагент поставлен в буфер. requestId=%s, vin=%s, clientType=%s",
            $requestId,
            $payload['vin'] ?? '-',
            $payload['clientType']
        ));

        return [
            'requestId' => $requestId,
            'status' => 'queued',
        ];
    }

    /**
     * Возвращает пакет pending-записей для забора 1С.
     */
    public function getPending(int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        $rows = OneCCounterpartyBuffer::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = json_decode($row->payload_json, true);

            $row->update([
                'status' => 'pulled',
                'pull_attempts' => $row->pull_attempts + 1,
                'pulled_at' => now(),
            ]);

            OneCCounterpartySyncEvent::query()->create([
                'buffer_id' => $row->id,
                'event_type' => 'pulled',
                'attempt_no' => $row->pull_attempts + 1,
                'request_id' => $row->request_id,
                'response_payload' => $row->payload_json,
                'result' => 'success',
            ]);
        }

        return $result;
    }

    /**
     * Принимает callback от 1С с результатом обработки контрагента.
     */
    public function processResult(array $data): array
    {
        $buffer = OneCCounterpartyBuffer::query()
            ->where('request_id', $data['requestId'])
            ->first();

        if (!$buffer) {
            throw new \RuntimeException('requestId not found');
        }

        $status = $data['status'];
        $isSuccess = in_array($status, ['created', 'found'], true);

        $buffer->update([
            'status' => $isSuccess ? 'processed' : 'error',
            'onec_counterparty_id' => $data['1cId'] ?? null,
            'onec_processing_status' => $status,
            'last_error' => $data['error'] ?? null,
            'processed_at' => isset($data['processedAt']) ? Carbon::parse($data['processedAt']) : now(),
        ]);

        OneCCounterpartySyncEvent::query()->create([
            'buffer_id' => $buffer->id,
            'event_type' => 'callback',
            'attempt_no' => 1,
            'request_id' => $buffer->request_id,
            'request_payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'result' => $isSuccess ? 'success' : 'error',
            'error_message' => $data['error'] ?? null,
        ]);

        if ($isSuccess && !empty($data['1cId'])) {
            $this->customFieldService->updateLeadCustomFields((int) $buffer->lead_id, [
                [
                    'field_key' => 'onec_counterparty_id',
                    'value' => (string) $data['1cId'],
                    'type' => 'text',
                ],
            ]);
        }

        $this->addLeadComment((int) $buffer->lead_id, $this->formatCallbackComment($data));

        return [
            'requestId' => $buffer->request_id,
            'leadId' => (int) $buffer->lead_id,
            'status' => $buffer->status,
        ];
    }

    private function buildPayloadFromLead(int $leadId): array
    {
        $api = $this->amoCRMService->getClient();
        $lead = $api->leads()->getOne($leadId, ['contacts', 'companies']);
        $leadArr = $lead->toArray();

        $contact = null;
        $company = null;

        $contactId = $leadArr['contacts'][0]['id'] ?? null;
        if ($contactId) {
            $contact = $api->contacts()->getOne((int) $contactId)->toArray();
        }

        $companyId = $leadArr['companies'][0]['id'] ?? null;
        if ($companyId) {
            $company = $api->companies()->getOne((int) $companyId)->toArray();
        }

        $leadFields = $leadArr['custom_fields_values'] ?? [];
        $contactFields = $contact['custom_fields_values'] ?? [];
        $companyFields = $company['custom_fields_values'] ?? [];

        $vin = $this->getCustomFieldValueById($leadFields, 808681);
        $clientTypeRaw = (string) ($this->getCustomFieldValueById($leadFields, 917427) ?? '');
        $clientType = mb_stripos($clientTypeRaw, 'юр') !== false ? 'legal' : 'individual';

        $contactFullName = trim(implode(' ', array_filter([
            $this->getCustomFieldValueById($contactFields, 974793),
            $this->getCustomFieldValueById($contactFields, 974795),
            $this->getCustomFieldValueById($contactFields, 974797),
        ])));

        $client = $clientType === 'legal'
            ? [
                'name' => $company['name'] ?? $this->getCustomFieldValueById($companyFields, 897711),
                'phone' => $this->extractPhone($companyFields),
                'inn' => (string) ($this->getCustomFieldValueById($companyFields, 897733) ?? ''),
                'kpp' => (string) ($this->getCustomFieldValueById($companyFields, 897735) ?? ''),
                'legalAddress' => $this->getCustomFieldValueById($companyFields, 897747),
                'rs' => (string) ($this->getCustomFieldValueById($companyFields, 897751) ?? ''),
                'bik' => (string) ($this->getCustomFieldValueById($companyFields, 897757) ?? ''),
            ]
            : [
                'fullName' => $contactFullName,
                'phone' => $this->extractPhone($contactFields),
                'passportSeries' => $this->getCustomFieldValueById($contactFields, 895289),
                'passportNumber' => $this->getCustomFieldValueById($contactFields, 945334),
                'passportIssuedBy' => $this->getCustomFieldValueById($contactFields, 808693),
                'passportIssueDate' => $this->getCustomFieldValueById($contactFields, 808757),
                'passportDepartmentCode' => $this->getCustomFieldValueById($contactFields, 808695),
                'registrationAddress' => $this->getCustomFieldValueById($contactFields, 808697),
                'inn' => $this->getCustomFieldValueById($contactFields, 955217),
                'snils' => $this->getCustomFieldValueById($contactFields, 945706),
            ];

        return [
            'vin' => $vin,
            'dealId' => $leadId,
            'clientType' => $clientType,
            'clientTypeRaw' => $clientTypeRaw,
            'client' => $client,
            'deal' => [
                'dealNumber' => (string) $leadId,
            ],
            '_meta' => [
                'contactId' => $contactId,
                'companyId' => $companyId,
            ],
        ];
    }

    private function getCustomFieldValueById(array $fields, int $fieldId): mixed
    {
        foreach ($fields as $field) {
            if ((int) ($field['field_id'] ?? 0) !== $fieldId) {
                continue;
            }

            $values = $field['values'] ?? [];
            $first = $values[0] ?? null;
            if (!$first) {
                return null;
            }

            return $first['value'] ?? ($first['enum'] ?? null);
        }

        return null;
    }

    private function extractPhone(array $fields): ?string
    {
        foreach ($fields as $field) {
            if (($field['field_code'] ?? null) !== 'PHONE') {
                continue;
            }

            $value = $field['values'][0]['value'] ?? null;
            if (!$value) {
                return null;
            }

            return preg_replace('/[^\d+]/', '', (string) $value);
        }

        return null;
    }

    private function addLeadComment(int $leadId, string $text): void
    {
        $api = $this->amoCRMService->getClient();

        $note = new ServiceMessageNote();
        $note->setEntityId($leadId)
            ->setText($text)
            ->setService('1C Integration')
            ->setCreatedBy(0);

        $notes = new NotesCollection();
        $notes->add($note);

        $api->notes(EntityTypesInterface::LEADS)->add($notes);
    }

    private function formatCallbackComment(array $data): string
    {
        $parts = [
            "[1C] Callback по контрагенту",
            "requestId={$data['requestId']}",
            "status={$data['status']}",
        ];

        if (!empty($data['1cId'])) {
            $parts[] = "1cId={$data['1cId']}";
        }
        if (!empty($data['processedAt'])) {
            $parts[] = "processedAt={$data['processedAt']}";
        }
        if (!empty($data['error'])) {
            $parts[] = "error={$data['error']}";
        }

        return implode('; ', $parts);
    }
}
