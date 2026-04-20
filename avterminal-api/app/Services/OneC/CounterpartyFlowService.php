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
    public function enqueueFromLead(int $leadId, array $incomingPayload = []): array
    {
        $payload = $this->buildPayloadFromLead($leadId, $incomingPayload);
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));

        // Дедупликация только по payload_hash: если такой payload уже есть по сделке,
        // повторно в буфер не ставим вне зависимости от статуса существующей записи.
        $existingSamePayload = OneCCounterpartyBuffer::query()
            ->where('lead_id', $leadId)
            ->where('payload_hash', $payloadHash)
            ->latest('id')
            ->first();

        if ($existingSamePayload) {
            $this->addLeadComment($leadId, sprintf(
                "[1C] Повторный запрос: идентичные данные уже есть в буфере/истории. requestId=%s, status=%s",
                $existingSamePayload->request_id,
                $existingSamePayload->status
            ));

            return [
                'requestId' => $existingSamePayload->request_id,
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
     * Возвращает последние записи буфера (для debug-диагностики).
     */
    public function getLatestBufferStatuses(int $limit = 5): array
    {
        $limit = max(1, min($limit, 50));

        return OneCCounterpartyBuffer::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'request_id',
                'lead_id',
                'contact_id',
                'company_id',
                'vin',
                'status',
                'pull_attempts',
                'onec_counterparty_id',
                'onec_processing_status',
                'last_error',
                'pulled_at',
                'processed_at',
                'created_at',
                'updated_at',
            ])
            ->map(fn (OneCCounterpartyBuffer $row) => [
                'id' => $row->id,
                'requestId' => $row->request_id,
                'leadId' => $row->lead_id,
                'contactId' => $row->contact_id,
                'companyId' => $row->company_id,
                'vin' => $row->vin,
                'status' => $row->status,
                'pullAttempts' => $row->pull_attempts,
                'onecCounterpartyId' => $row->onec_counterparty_id,
                'onecProcessingStatus' => $row->onec_processing_status,
                'lastError' => $row->last_error,
                'pulledAt' => $row->pulled_at?->toIso8601String(),
                'processedAt' => $row->processed_at?->toIso8601String(),
                'createdAt' => $row->created_at?->toIso8601String(),
                'updatedAt' => $row->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Debug-процедура: отдать payload по requestId так, как его отдает pending pull.
     * Если запись в pending — переводим в pulled и создаем pulled-event.
     */
    public function debugPullByRequestId(string $requestId): array
    {
        $buffer = OneCCounterpartyBuffer::query()
            ->where('request_id', $requestId)
            ->first();

        if (!$buffer) {
            throw new \RuntimeException('requestId not found');
        }

        $payload = json_decode((string) $buffer->payload_json, true);
        $payload = is_array($payload) ? $payload : [];

        $previousStatus = $buffer->status;
        $wasPulledNow = false;

        if ($buffer->status === 'pending') {
            $newAttemptNo = $buffer->pull_attempts + 1;

            $buffer->update([
                'status' => 'pulled',
                'pull_attempts' => $newAttemptNo,
                'pulled_at' => now(),
            ]);

            OneCCounterpartySyncEvent::query()->create([
                'buffer_id' => $buffer->id,
                'event_type' => 'pulled',
                'attempt_no' => $newAttemptNo,
                'request_id' => $buffer->request_id,
                'response_payload' => $buffer->payload_json,
                'result' => 'success',
            ]);

            $buffer->refresh();
            $wasPulledNow = true;
        }

        return [
            'requestId' => $buffer->request_id,
            'leadId' => (int) $buffer->lead_id,
            'previousStatus' => $previousStatus,
            'currentStatus' => $buffer->status,
            'wasPulledNow' => $wasPulledNow,
            'pullAttempts' => (int) $buffer->pull_attempts,
            'pulledAt' => $buffer->pulled_at?->toIso8601String(),
            'payload' => $payload,
        ];
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

    private function buildPayloadFromLead(int $leadId, array $incomingPayload = []): array
    {
        $api = $this->amoCRMService->getClient();
        $lead = $api->leads()->getOne($leadId, ['contacts', 'companies']);
        $leadArr = $lead->toArray();

        $incomingLead = is_array($incomingPayload['lead'] ?? null) ? $incomingPayload['lead'] : [];
        $incomingContact = is_array($incomingPayload['contact'] ?? null) ? $incomingPayload['contact'] : [];
        $incomingCompany = is_array($incomingPayload['company'] ?? null) ? $incomingPayload['company'] : [];

        $contact = null;
        $company = null;

        $contactId = $this->toNullableInt($leadArr['contacts'][0]['id'] ?? null)
            ?? $this->toNullableInt($incomingLead['main_contact_id'] ?? null)
            ?? $this->toNullableInt($incomingContact['id'] ?? null);
        if ($contactId) {
            try {
                $contact = $api->contacts()->getOne((int) $contactId)->toArray();
            } catch (\Throwable) {
                // Fallback ниже: используем contact из webhook payload, если есть.
            }
        }

        $companyId = $this->toNullableInt($leadArr['companies'][0]['id'] ?? null)
            ?? $this->toNullableInt($incomingLead['linked_company_id'] ?? null)
            ?? $this->toNullableInt($incomingCompany['id'] ?? null);
        if ($companyId) {
            try {
                $company = $api->companies()->getOne((int) $companyId)->toArray();
            } catch (\Throwable) {
                // Fallback ниже: используем company из webhook payload, если есть.
            }
        }

        if (!$contact && !empty($incomingContact)) {
            $contact = $this->normalizeIncomingEntity($incomingContact);
        }

        if (!$company && !empty($incomingCompany)) {
            $company = $this->normalizeIncomingEntity($incomingCompany);
        }

        $leadFields = $this->extractEntityCustomFields($leadArr);
        if (empty($leadFields) && !empty($incomingLead)) {
            $leadFields = $this->extractEntityCustomFields($incomingLead);
        }

        $contactFields = $contact ? $this->extractEntityCustomFields($contact) : [];
        if (empty($contactFields) && !empty($incomingContact)) {
            $contactFields = $this->extractEntityCustomFields($incomingContact);
        }

        $companyFields = $company ? $this->extractEntityCustomFields($company) : [];
        if (empty($companyFields) && !empty($incomingCompany)) {
            $companyFields = $this->extractEntityCustomFields($incomingCompany);
        }

        $vin = $this->getCustomFieldValueByConfig($leadFields, 'vin_field_id', 808681);
        $clientTypeRaw = (string) ($this->getCustomFieldValueById($leadFields, 917427) ?? '');
        $clientType = mb_stripos($clientTypeRaw, 'юр') !== false ? 'legal' : 'individual';
        $brand = $this->getCustomFieldValueByConfig($leadFields, 'car_brand', 808679);
        $model = $this->getCustomFieldValueByConfig($leadFields, 'car_model', 808675);
        $warehouse = $this->getCustomFieldValueByConfig($leadFields, 'warehouse', 969469);
        $companyName = ($company['name'] ?? null) ?: $this->getCustomFieldValueById($companyFields, 897711);
        $companyInn = $this->normalizeOptionalString($this->getCustomFieldValueById($companyFields, 897733));
        $contractDate = $clientType === 'legal'
            ? $this->getCustomFieldValueByConfig($companyFields, 'contract_date_company', 919495)
            : $this->getCustomFieldValueByConfig($contactFields, 'contract_date_contact', 947713);

        $contactFullName = trim(implode(' ', array_filter([
            $this->getCustomFieldValueById($contactFields, 974793),
            $this->getCustomFieldValueById($contactFields, 974795),
            $this->getCustomFieldValueById($contactFields, 974797),
        ])));

        $client = $clientType === 'legal'
            ? [
                'name' => $companyName,
                'phone' => $this->extractPhone($companyFields),
                'email' => $this->extractEmail($companyFields) ?? $this->extractEmail($contactFields),
                'inn' => (string) ($companyInn ?? ''),
                'kpp' => (string) ($this->getCustomFieldValueById($companyFields, 897735) ?? ''),
                'legalAddress' => $this->extractAddress($companyFields, [897747], [
                    'юридический адрес',
                    'юр адрес',
                    'адрес',
                ]),
                'rs' => (string) ($this->getCustomFieldValueById($companyFields, 897751) ?? ''),
                'bik' => (string) ($this->getCustomFieldValueById($companyFields, 897757) ?? ''),
            ]
            : [
                'fullName' => $contactFullName,
                'phone' => $this->extractPhone($contactFields),
                'email' => $this->extractEmail($contactFields),
                'passportSeries' => $this->getCustomFieldValueById($contactFields, 895289),
                'passportNumber' => $this->getCustomFieldValueById($contactFields, 945334),
                'passportIssuedBy' => $this->getCustomFieldValueById($contactFields, 808693),
                'passportIssueDate' => $this->getCustomFieldValueById($contactFields, 808757),
                'passportDepartmentCode' => $this->getCustomFieldValueById($contactFields, 808695),
                'registrationAddress' => $this->extractAddress($contactFields, [808697], [
                    'адрес регистрации',
                    'регистрац',
                    'пропис',
                    'адрес',
                ]),
                'Address' => $this->buildContactAddress($contactFields),
                'inn' => $this->getCustomFieldValueById($contactFields, 955217),
                'snils' => $this->getCustomFieldValueById($contactFields, 945706),
                'dealerName' => $companyName,
                'dealerInn' => $companyInn,
            ];

        return [
            'vin' => $vin,
            'dealId' => $leadId,
            'clientType' => $clientType,
            'clientTypeRaw' => $clientTypeRaw,
            'client' => $client,
            'deal' => [
                'dealNumber' => (string) $leadId,
                'contractNumber' => (string) $leadId,
                'contractDate' => $contractDate,
                'warehouse' => $warehouse,
                'brand' => $brand,
                'model' => $model,
            ],
            '_meta' => [
                'contactId' => $contactId,
                'companyId' => $companyId,
            ],
        ];
    }

    private function getCustomFieldValueByConfig(array $fields, string $configKey, int $fallbackFieldId): mixed
    {
        return $this->getCustomFieldValueById(
            $fields,
            $this->getConfiguredFieldId($configKey, $fallbackFieldId)
        );
    }

    private function getConfiguredFieldId(string $configKey, int $default): int
    {
        return (int) (config("amocrm.fields.{$configKey}.id") ?: $default);
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

    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    private function extractPhone(array $fields): ?string
    {
        foreach ($fields as $field) {
            $fieldCode = (string) ($field['field_code'] ?? '');
            $fieldName = mb_strtolower((string) ($field['field_name'] ?? ''), 'UTF-8');
            $looksLikePhoneField = str_contains($fieldName, 'телефон') || str_contains($fieldName, 'phone');

            if ($fieldCode !== 'PHONE' && !$looksLikePhoneField) {
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

    private function extractEmail(array $fields): ?string
    {
        return $this->extractFieldValueByCodeOrName(
            $fields,
            ['EMAIL'],
            ['email', 'e-mail', 'электрон', 'эл. почт', 'почта']
        );
    }

    private function extractAddress(array $fields, array $fieldIds, array $nameNeedles): ?string
    {
        foreach ($fieldIds as $fieldId) {
            $value = $this->normalizeOptionalString($this->getCustomFieldValueById($fields, $fieldId));
            if ($value !== null) {
                return $value;
            }
        }

        return $this->extractFieldValueByCodeOrName($fields, ['ADDRESS'], $nameNeedles);
    }

    private function buildContactAddress(array $fields): ?string
    {
        $parts = [
            $this->extractFieldValueByCodeOrName($fields, [], ['индекс']),
            $this->extractFieldValueByCodeOrName($fields, [], ['субъект федерации']),
            $this->extractFieldValueByCodeOrName($fields, [], ['район']),
            $this->extractFieldValueByCodeOrName($fields, [], ['город']),
            $this->extractFieldValueByCodeOrName($fields, [], ['населенный пункт', 'населённый пункт']),
            $this->extractFieldValueByCodeOrName($fields, [], ['улица']),
            $this->extractFieldValueByCodeOrName($fields, [], ['дом']),
            $this->extractFieldValueByCodeOrName($fields, [], ['квартира']),
        ];

        $parts = array_values(array_filter(array_map(
            fn (mixed $value) => $this->normalizeOptionalString($value),
            $parts
        )));

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function extractFieldValueByCodeOrName(array $fields, array $fieldCodes, array $nameNeedles): ?string
    {
        $normalizedCodes = array_map('mb_strtoupper', $fieldCodes);

        foreach ($fields as $field) {
            $fieldCode = mb_strtoupper((string) ($field['field_code'] ?? ''), 'UTF-8');
            $fieldName = mb_strtolower((string) ($field['field_name'] ?? ''), 'UTF-8');

            $matchesCode = $fieldCode !== '' && in_array($fieldCode, $normalizedCodes, true);
            $matchesName = $fieldName !== '' && $this->fieldNameContainsAny($fieldName, $nameNeedles);

            if (!$matchesCode && !$matchesName) {
                continue;
            }

            $value = $this->extractRawFieldValue($field);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function extractRawFieldValue(array $field): ?string
    {
        $values = $field['values'] ?? [];

        foreach ($values as $valueItem) {
            if (!is_array($valueItem)) {
                continue;
            }

            $value = $valueItem['value'] ?? ($valueItem['enum'] ?? null);
            $normalizedValue = $this->normalizeOptionalString($value);
            if ($normalizedValue !== null) {
                return $normalizedValue;
            }
        }

        return null;
    }

    private function fieldNameContainsAny(string $fieldName, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($fieldName, mb_strtolower($needle, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function extractEntityCustomFields(array $entity): array
    {
        $fields = $entity['custom_fields_values'] ?? null;
        if (is_array($fields) && !empty($fields)) {
            return $fields;
        }

        $legacyFields = $entity['custom_fields'] ?? null;
        if (is_array($legacyFields) && !empty($legacyFields)) {
            return $this->normalizeLegacyCustomFields($legacyFields);
        }

        return [];
    }

    private function normalizeIncomingEntity(array $entity): array
    {
        $normalized = $entity;
        $normalized['custom_fields_values'] = $this->extractEntityCustomFields($entity);

        return $normalized;
    }

    private function normalizeLegacyCustomFields(array $customFields): array
    {
        $result = [];

        foreach ($customFields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldId = (int) ($field['id'] ?? (is_numeric($key) ? $key : 0));
            if ($fieldId <= 0) {
                continue;
            }

            $values = [];
            foreach (($field['values'] ?? []) as $valueItem) {
                if (!is_array($valueItem)) {
                    continue;
                }

                $normalizedValue = [];
                if (array_key_exists('value', $valueItem)) {
                    $normalizedValue['value'] = $valueItem['value'];
                }
                if (array_key_exists('enum', $valueItem)) {
                    $normalizedValue['enum'] = $valueItem['enum'];
                } elseif (!array_key_exists('value', $valueItem) && array_key_exists('enum_id', $valueItem)) {
                    $normalizedValue['enum'] = $valueItem['enum_id'];
                }

                if (!empty($normalizedValue)) {
                    $values[] = $normalizedValue;
                }
            }

            if (empty($values) && array_key_exists('value', $field)) {
                $values[] = ['value' => $field['value']];
            }

            $result[] = [
                'field_id' => $fieldId,
                'field_name' => $field['name'] ?? ($field['field_name'] ?? null),
                'field_code' => $field['code'] ?? ($field['field_code'] ?? null),
                'values' => $values,
            ];
        }

        return $result;
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
