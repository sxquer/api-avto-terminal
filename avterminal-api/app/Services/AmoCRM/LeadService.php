<?php

namespace App\Services\AmoCRM;

use AmoCRM\Filters\ContactsFilter;
use AmoCRM\Filters\LeadsFilter;
use AmoCRM\Models\LeadModel;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы со сделками (leads) в AmoCRM
 */
class LeadService
{
    public function __construct(
        private AmoCRMService $amoCRMService
    ) {}

    /**
     * Получить данные сделки по ID
     */
    public function getLeadData(int $id): array
    {
        $apiClient = $this->amoCRMService->getClient();
        $lead = $apiClient->leads()->getOne($id, ['contacts']);
        $leadArray = $lead->toArray();

        if (isset($leadArray['contacts'])) {
            $contactIds = array_map(fn($contact) => $contact['id'], $leadArray['contacts']);

            if (!empty($contactIds)) {
                $filter = new ContactsFilter();
                $filter->setIds($contactIds);
                $contacts = $apiClient->contacts()->get($filter);
                $leadArray['contacts'] = $contacts->toArray();
            }
        }

        return $leadArray;
    }

    /**
     * Получить форматированные данные сделки и контакта
     */
    public function getFormattedLeadAndContactData(int $id): array
    {
        $apiClient = $this->amoCRMService->getClient();
        $lead = $apiClient->leads()->getOne($id, ['contacts']);
        $leadArray = $lead->toArray();

        $contact = null;
        if (isset($leadArray['contacts'][0])) {
            $contactId = $leadArray['contacts'][0]['id'];
            $contact = $apiClient->contacts()->getOne($contactId);
            $contact = $contact->toArray();
        }

        $leadCustomFields = $this->formatCustomFields($leadArray['custom_fields_values'] ?? []);
        $contactCustomFields = $this->formatCustomFields($contact['custom_fields_values'] ?? []);

        $allCustomFields = array_merge($leadCustomFields, $contactCustomFields);

        return [
            'lead_id' => $leadArray['id'],
            'contact_id' => $contact['id'],
            'custom_fields' => $allCustomFields
        ];
    }

    /**
     * Найти сделку по VIN
     */
    public function findLeadByVin(string $vin): ?LeadModel
    {
        $apiClient = $this->amoCRMService->getClient();
        $filter = new LeadsFilter();

        // Фильтруем по кастомному полю VIN (ID: 808681)
        $filter->setCustomFieldsValues([
            808681 => $vin
        ]);

        $leads = $apiClient->leads()->get($filter);

        if ($leads->count() === 0) {
            return null;
        }

        return $leads->first();
    }

    /**
     * Обновить статус сделки
     *
     * @param int $leadId ID лида
     * @param string $statusKey Ключ статуса из конфига ('ptd/dt', 'vipusk', 'svh')
     * @return LeadModel Обновленный лид
     * @throws \Exception
     */
    public function updateLeadStatus(int $leadId, string $statusKey): LeadModel
    {
        $statusId = config("amocrm.statuses.{$statusKey}");

        if (!$statusId) {
            throw new \Exception("Статус {$statusKey} не найден в конфигурации");
        }

        $apiClient = $this->amoCRMService->getClient();

        $lead = (new LeadModel())
            ->setId($leadId)
            ->setStatusId($statusId);

        return $apiClient->leads()->updateOne($lead);
    }

    /**
     * Найти ID статуса по подстроке (игнорируя цифры в скобках)
     *
     * @param string $statusText Текст статуса без скобок (например, "выпуск с уплатой")
     * @return array|null Массив с ключами 'id' (enum_id) и 'full_text' (полный текст из конфига) или null
     */
    public function findStatusIdBySubstring(string $statusText): ?array
    {
        $statusConfig = config('amocrm.fields.status_dt.values');

        if (!$statusConfig) {
            return null;
        }

        // Приводим входящий текст к нижнему регистру для сравнения
        $searchText = mb_strtolower(trim($statusText), 'UTF-8');

        foreach ($statusConfig as $configText => $enumId) {
            // Убираем часть со скобками из текста конфига
            $configTextWithoutBrackets = preg_replace('/\s*\(\d+\)\s*$/', '', $configText);
            $configTextLower = mb_strtolower(trim($configTextWithoutBrackets), 'UTF-8');

            // Сравниваем
            if ($configTextLower === $searchText) {
                return [
                    'id' => $enumId,
                    'full_text' => $configText
                ];
            }
        }

        return null;
    }

    /**
     * Обновить сделку на основе статуса ДТ
     *
     * @param string $vinNum VIN номер
     * @param string $pdNum Номер ДТ
     * @param string $status Текстовый статус
     * @param string $statusDate Дата статуса в формате "dd.mm.yyyy hh:mm"
     * @param bool $testMode Тестовый режим (всегда возвращает ID 25147637)
     * @return array Результат операции
     * @throws \Exception
     */
    public function updateLeadFromDtStatus(
        string $vinNum,
        string $pdNum,
        string $status,
        string $statusDate,
        bool $testMode = false
    ): array {
        // 1. Получить сделку по VIN
        if ($testMode) {
            // В тестовом режиме работаем с фиксированной сделкой
            $leadId = 25147637;
            $lead = $this->amoCRMService->getClient()->leads()->getOne($leadId);
        } else {
            $lead = $this->findLeadByVin($vinNum);
            if (!$lead) {
                throw new \Exception("Сделка с VIN {$vinNum} не найдена");
            }
            $leadId = $lead->getId();
        }

        $moveToHistory = false;

        // 2. Найти ID статуса по подстроке
        $statusData = $this->findStatusIdBySubstring($status);
        if (!$statusData) {
            throw new \Exception("Статус '{$status}' не найден в конфигурации");
        }

        $statusEnumId = $statusData['id'];
        $statusFullText = $statusData['full_text'];

        // 3. Проверить номер ДТ - правило 2.4.0
        $customFieldsValues = $lead->getCustomFieldsValues();
        if ($customFieldsValues) {
            $nomerDtField = $customFieldsValues->getBy('fieldId', config('amocrm.fields.nomer_dt.id'));
            if ($nomerDtField) {
                $values = $nomerDtField->getValues();
                if ($values && $values->first()) {
                    $currentNomerDt = $values->first()->getValue();
                    if ($currentNomerDt && $currentNomerDt !== $pdNum) {
                        $moveToHistory = true;
                    }
                }
            }
        }

        // 4. Конвертировать дату из формата "dd.mm.yyyy hh:mm" в timestamp
        $dateTimestamp = $this->parseDateString($statusDate);

        // 5. Получить текущий статус сделки для проверки "защищенных" этапов
        $currentStatusId = $lead->getStatusId();

        // 6. Проверить на "защищенные" этапы - правило защиты от излишнего "отката"
        $restrictedStatuses = [
            config('amocrm.statuses.svh_do2', 64976646),
            config('amocrm.statuses.epts', 62360978),
            config('amocrm.statuses.oplata_payment', 64577706),
            config('amocrm.statuses.oplateno_paid', 64577710),
            config('amocrm.statuses.yspshno_realizovano', 142),
            config('amocrm.statuses.zakryto_ne_realizovano', 143)
        ];

        $isRestrictedStage = in_array($currentStatusId, $restrictedStatuses);
        $stageProtectionActive = false;

        // Логировать защиты стадий
        if ($isRestrictedStage) {
            Log::info("DT status update: защита стадии активирована", [
                'lead_id' => $leadId,
                'current_stage_id' => $currentStatusId,
                'status' => $statusFullText,
                'pd_num' => $pdNum,
                'stage_not_changed' => true
            ]);
        }

        // 7. Подготовить поля для обновления и определить стадию
        $fieldsToUpdate = [
            ['field_key' => 'nomer_dt', 'value' => $pdNum, 'type' => 'text'],
            ['field_key' => 'status_dt', 'value' => $statusFullText, 'type' => 'select'],
        ];

        $stageKey = null;
        $highlightRed = false;

        // Определяем какие поля заполнять и на какую стадию переводить
        // Правило 2.4.1 - Регистрация ПТД
        if (mb_stripos($statusFullText, 'регистрация ПТД') !== false) {
            $fieldsToUpdate[] = ['field_key' => 'registration_date', 'value' => $dateTimestamp, 'type' => 'datetime'];
            $stageKey = $isRestrictedStage ? null : 'ptd/dt';
        }
        // Правило 2.4.2 - Выпуск без уплаты или с уплатой
        elseif (mb_stripos($statusFullText, 'выпуск без уплаты') !== false ||
                mb_stripos($statusFullText, 'выпуск с уплатой') !== false) {
            $fieldsToUpdate[] = ['field_key' => 'vipusk_date', 'value' => $dateTimestamp, 'type' => 'datetime'];
            $stageKey = $isRestrictedStage ? null : 'vipusk';
        }
        // Правило 2.4.3 - Отказы
        elseif (mb_stripos($statusFullText, 'отказ в выпуске товаров') !== false ||
                mb_stripos($statusFullText, 'выпуск товаров аннулирован при отзыве ПТД') !== false ||
                mb_stripos($statusFullText, 'отказ в разрешении') !== false) {
            $fieldsToUpdate[] = ['field_key' => 'refuse_date', 'value' => $dateTimestamp, 'type' => 'datetime'];
            $stageKey = $isRestrictedStage ? null : 'ptd/dt';
            $highlightRed = true;
            if ($isRestrictedStage) {
                $stageProtectionActive = true;
            }
        }
        // Правило 2.4.4 - Ожидание (требуется уплата, ожидание решения)
        elseif (mb_stripos($statusFullText, 'требуется уплата') !== false ||
                mb_stripos($statusFullText, 'выпуск разрешен, ожидание решения по временному ввозу') !== false) {
            $stageKey = $isRestrictedStage ? null : 'ptd/dt';
            $highlightRed = true;
            if ($isRestrictedStage) {
                $stageProtectionActive = true;
            }
        }

        // Если нужно подсветить красным - добавляем поле color
        if ($highlightRed) {
            $fieldsToUpdate[] = ['field_key' => 'color_field_id', 'value' => 'Красный', 'type' => 'select'];
        }

        // 8. Обновить кастомные поля (с переносом в историю если нужно)
        app(CustomFieldService::class)->updateLeadCustomFields(
            $leadId,
            $fieldsToUpdate,
            $moveToHistory
        );

        // 9. Обновить стадию сделки (только если не защищена)
        if ($stageKey) {
            $this->updateLeadStatus($leadId, $stageKey);
        }

        return [
            'lead_id' => $leadId,
            'status' => 'OK',
            'stage' => $stageKey,
            'highlight_red' => $highlightRed,
            'moved_to_history' => $moveToHistory,
            'stage_protection_active' => $stageProtectionActive,
            'current_stage_id' => $currentStatusId,
            'stage_changed' => !is_null($stageKey)
        ];
    }

    /**
     * Парсинг строки даты в timestamp
     * Поддерживает форматы: "dd.mm.yyyy hh:mm", "dd.mm.yyyy hh.mm" и "yyyy-mm-dd hh:mm:ss"
     *
     * @param string $dateString Строка даты
     * @return int Timestamp
     * @throws \Exception
     */
    private function parseDateString(string $dateString): int
    {
        // Пробуем разобрать формат dd.mm.yyyy hh:mm или dd.mm.yyyy hh.mm
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2})[:.](\d{2})$/', $dateString, $matches)) {
            $day = $matches[1];
            $month = $matches[2];
            $year = $matches[3];
            $hour = $matches[4];
            $minute = $matches[5];

            $timestamp = mktime((int)$hour, (int)$minute, 0, (int)$month, (int)$day, (int)$year);

            if ($timestamp === false) {
                throw new \Exception("Неверный формат даты: {$dateString}");
            }

            return $timestamp;
        }

        // Пробуем разобрать формат yyyy-mm-dd hh:mm:ss
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/', $dateString, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];
            $hour = $matches[4];
            $minute = $matches[5];
            $second = $matches[6];

            $timestamp = mktime((int)$hour, (int)$minute, (int)$second, (int)$month, (int)$day, (int)$year);

            if ($timestamp === false) {
                throw new \Exception("Неверный формат даты: {$dateString}");
            }

            return $timestamp;
        }

        // Пробуем стандартный strtotime как fallback
        $timestamp = strtotime($dateString);

        if ($timestamp === false) {
            throw new \Exception("Неверный формат даты: {$dateString}");
        }

        return $timestamp;
    }

    /**
     * Форматирование кастомных полей
     */
    private function formatCustomFields(array $customFields): array
    {
        $formattedFields = [];

        foreach ($customFields as $field) {
            $values = [];
            foreach ($field['values'] as $value) {
                $processedValue = $value['value'];

                // Форматирование даты в YYYY-MM-DD формат
                if (in_array($field['field_type'], ['date', 'birthday']) && !empty($processedValue)) {
                    $processedValue = date('Y-m-d', strtotime($processedValue));
                }

                // Форматирование серии паспорта (4 цифры разделить по 2 пробелом)
                if ($field['field_name'] === 'Серия паспорта' && strlen($processedValue) === 4 && is_numeric($processedValue)) {
                    $processedValue = substr($processedValue, 0, 2) . ' ' . substr($processedValue, 2, 2);
                }

                // Все значения должны быть написаны заглавными буквами
                $values[] = mb_strtoupper($processedValue, 'UTF-8');
            }

            // Если изначально значений несколько, то объединить их через запятую
            $finalValue = implode(',', $values);

            $formattedFields[] = [
                'field_id' => $field['field_id'],
                'field_name' => $field['field_name'],
                'field_value' => $finalValue,
            ];
        }

        return $formattedFields;
    }
}