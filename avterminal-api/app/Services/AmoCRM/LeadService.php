<?php

namespace App\Services\AmoCRM;

use AmoCRM\Filters\ContactsFilter;
use AmoCRM\Filters\LeadsFilter;
use AmoCRM\Models\LeadModel;
use App\Exceptions\AmoCRM\MultipleLeadsFoundForVinException;
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
            $contactIds = array_map(fn ($contact) => $contact['id'], $leadArray['contacts']);

            if (! empty($contactIds)) {
                $filter = new ContactsFilter;
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
            'custom_fields' => $allCustomFields,
        ];
    }

    /**
     * Найти сделку по VIN
     */
    public function findLeadByVin(string $vin): ?LeadModel
    {
        $apiClient = $this->amoCRMService->getClient();
        $filter = new LeadsFilter;

        $pipelineIds = array_values(array_filter(array_map(
            static fn (array $pipeline): ?int => isset($pipeline['id']) ? (int) $pipeline['id'] : null,
            config('amocrm.pipelines', [])
        )));

        if ($pipelineIds === []) {
            throw new \Exception('В конфигурации не заданы воронки для поиска сделки');
        }

        // Фильтруем по кастомному полю VIN (ID: 808681)
        $filter->setCustomFieldsValues([
            808681 => $vin,
        ]);
        $filter->setPipelineIds($pipelineIds);

        $leads = $apiClient->leads()->get($filter);

        if ($leads->count() === 0) {
            return null;
        }

        if ($leads->count() > 1) {
            $leadIds = array_map(
                static fn (LeadModel $lead): int => $lead->getId(),
                $leads->all()
            );

            throw new MultipleLeadsFoundForVinException($vin, $leadIds);
        }

        return $leads->first();
    }

    /**
     * Обновить статус сделки
     *
     * @param  int  $leadId  ID лида
     * @param  string  $statusKey  Ключ статуса из конфига ('ptd/dt', 'vipusk', 'svh')
     * @return LeadModel Обновленный лид
     *
     * @throws \Exception
     */
    public function updateLeadStatus(int $leadId, string $statusKey): LeadModel
    {
        $statusId = config("amocrm.statuses.{$statusKey}");

        if (! $statusId) {
            throw new \Exception("Статус {$statusKey} не найден в конфигурации");
        }

        return $this->updateLeadStatusById($leadId, (int) $statusId);
    }

    /**
     * Обновить этап сделки по ID статуса amoCRM.
     */
    public function updateLeadStatusById(int $leadId, int $statusId): LeadModel
    {
        $apiClient = $this->amoCRMService->getClient();

        $lead = (new LeadModel)
            ->setId($leadId)
            ->setStatusId($statusId);

        return $apiClient->leads()->updateOne($lead);
    }

    /**
     * Найти ID статуса по подстроке (игнорируя цифры в скобках)
     *
     * @param  string  $statusText  Текст статуса без скобок (например, "выпуск с уплатой")
     * @return array|null Массив с ключами 'id' (enum_id) и 'full_text' (полный текст из конфига) или null
     */
    public function findStatusIdBySubstring(string $statusText): ?array
    {
        $statusConfig = config('amocrm.fields.status_dt.values');

        if (! $statusConfig) {
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
                    'full_text' => $configText,
                ];
            }
        }

        return null;
    }

    /**
     * Обновить сделку на основе статуса ДТ
     *
     * @param  string  $vinNum  VIN номер
     * @param  string  $pdNum  Номер ДТ
     * @param  string  $status  Текстовый статус
     * @param  string  $statusDate  Дата статуса в формате "dd.mm.yyyy hh:mm"
     * @param  bool  $testMode  Тестовый режим (всегда возвращает ID 25147637)
     * @return array Результат операции
     *
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
            if (! $lead) {
                throw new \Exception("Сделка с VIN {$vinNum} не найдена");
            }
            $leadId = $lead->getId();
        }

        $pipelineKey = $this->getPipelineKey($lead);

        $moveToHistory = false;

        // 2. Найти ID статуса по подстроке
        $statusData = $this->findStatusIdBySubstring($status);
        if (! $statusData) {
            throw new \Exception("Статус '{$status}' не найден в конфигурации");
        }

        $statusFullText = $statusData['full_text'];

        $isRegistrationStatus = mb_stripos($statusFullText, 'регистрация ПТД') !== false;
        $isReleaseWithoutPaymentStatus = mb_stripos($statusFullText, 'выпуск без уплаты') !== false;
        $isReleaseWithPaymentStatus = mb_stripos($statusFullText, 'выпуск с уплатой') !== false;

        if ($pipelineKey === 'transit_russia') {
            return $this->ignoredStatusResult($lead, $pipelineKey, 'Статусы ДТ не обрабатываются в воронке «Транзит по РФ»');
        }

        if ($pipelineKey === 'office_moscow'
            && ! $isRegistrationStatus
            && ! $isReleaseWithPaymentStatus) {
            return $this->ignoredStatusResult($lead, $pipelineKey, 'Этот статус ДТ не обрабатывается в воронке «Офис Москва»');
        }

        $currentStatusId = $lead->getStatusId();
        $officeReleaseStatusId = $this->getPipelineStatusId('office_moscow', 'dt_release');

        if ($pipelineKey === 'office_moscow'
            && $isRegistrationStatus
            && $currentStatusId === $officeReleaseStatusId) {
            return $this->ignoredStatusResult($lead, $pipelineKey, 'Регистрация ДТ не откатывает сделку с этапа выпуска');
        }

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

        // 5. Проверить на "защищенные" этапы - правило защиты от излишнего "отката"
        $restrictedStatuses = [
            config('amocrm.statuses.svh_do2', 64976646),
            config('amocrm.statuses.epts', 62360978),
            config('amocrm.statuses.oplata_payment', 64577706),
            config('amocrm.statuses.oplateno_paid', 64577710),
            config('amocrm.statuses.yspshno_realizovano', 142),
            config('amocrm.statuses.zakryto_ne_realizovano', 143),
        ];

        $isRestrictedStage = $pipelineKey === 'main'
            && in_array($currentStatusId, $restrictedStatuses, true);
        $stageProtectionActive = false;

        // Логировать защиты стадий
        if ($isRestrictedStage) {
            Log::info('DT status update: защита стадии активирована', [
                'lead_id' => $leadId,
                'current_stage_id' => $currentStatusId,
                'status' => $statusFullText,
                'pd_num' => $pdNum,
                'stage_not_changed' => true,
            ]);
        }

        // 6. Подготовить поля для обновления и определить стадию
        $fieldsToUpdate = [
            ['field_key' => 'nomer_dt', 'value' => $pdNum, 'type' => 'text'],
            ['field_key' => 'status_dt', 'value' => $statusFullText, 'type' => 'select'],
        ];

        $stageKey = null;
        $targetStatusId = null;
        $highlightRed = false;

        // Определяем какие поля заполнять и на какую стадию переводить
        // Правило 2.4.1 - Регистрация ПТД
        if ($isRegistrationStatus) {
            $fieldsToUpdate[] = ['field_key' => 'registration_date', 'value' => $dateTimestamp, 'type' => 'datetime'];
            if (! $isRestrictedStage) {
                $stageKey = 'dt_registration';
                $targetStatusId = $this->getPipelineStatusId($pipelineKey, $stageKey);
            }
        }
        // Правило 2.4.2 - Выпуск без уплаты или с уплатой
        elseif ($isReleaseWithoutPaymentStatus || $isReleaseWithPaymentStatus) {
            $fieldsToUpdate[] = ['field_key' => 'vipusk_date', 'value' => $dateTimestamp, 'type' => 'datetime'];
            if (! $isRestrictedStage) {
                $stageKey = 'dt_release';
                $targetStatusId = $this->getPipelineStatusId($pipelineKey, $stageKey);
            }
        }
        // Правило 2.4.3 - Отказы
        elseif (mb_stripos($statusFullText, 'отказ в выпуске товаров') !== false ||
                mb_stripos($statusFullText, 'выпуск товаров аннулирован при отзыве ПТД') !== false ||
                mb_stripos($statusFullText, 'отказ в разрешении') !== false) {
            $fieldsToUpdate[] = ['field_key' => 'refuse_date', 'value' => $dateTimestamp, 'type' => 'datetime'];
            if (! $isRestrictedStage) {
                $stageKey = 'dt_registration';
                $targetStatusId = $this->getPipelineStatusId($pipelineKey, $stageKey);
            }
            $highlightRed = true;
            if ($isRestrictedStage) {
                $stageProtectionActive = true;
            }
        }
        // Правило 2.4.4 - Ожидание (требуется уплата, ожидание решения)
        elseif (mb_stripos($statusFullText, 'требуется уплата') !== false ||
                mb_stripos($statusFullText, 'выпуск разрешен, ожидание решения по временному ввозу') !== false) {
            if (! $isRestrictedStage) {
                $stageKey = 'dt_registration';
                $targetStatusId = $this->getPipelineStatusId($pipelineKey, $stageKey);
            }
            $highlightRed = true;
            if ($isRestrictedStage) {
                $stageProtectionActive = true;
            }
        }

        // Если нужно подсветить красным - добавляем поле color
        if ($highlightRed) {
            $fieldsToUpdate[] = ['field_key' => 'color_field_id', 'value' => 'Красный', 'type' => 'select'];
        }

        // 7. Обновить кастомные поля (с переносом в историю если нужно)
        app(CustomFieldService::class)->updateLeadCustomFields(
            $leadId,
            $fieldsToUpdate,
            $moveToHistory
        );

        // 8. Обновить этап сделки (только если он отличается от текущего)
        $stageChanged = false;
        if ($targetStatusId !== null && $currentStatusId !== $targetStatusId) {
            $this->updateLeadStatusById($leadId, $targetStatusId);
            $stageChanged = true;
        }

        return [
            'lead_id' => $leadId,
            'status' => 'OK',
            'pipeline' => $pipelineKey,
            'stage' => $stageKey,
            'highlight_red' => $highlightRed,
            'moved_to_history' => $moveToHistory,
            'stage_protection_active' => $stageProtectionActive,
            'current_stage_id' => $currentStatusId,
            'stage_changed' => $stageChanged,
        ];
    }

    /**
     * Обновить сделку на основе статуса транзитного ДТ
     *
     * @param  string  $vinNum  VIN номер
     * @param  string  $tdNum  Номер транзитного ДТ
     * @param  string  $status  Текстовый статус (регистронезависимый)
     * @param  string  $statusDate  Дата статуса в формате "dd.mm.yyyy hh:mm" или "yyyy-mm-dd hh:mm:ss"
     * @param  bool  $testMode  Тестовый режим (всегда возвращает ID 25147637)
     * @return array Результат операции
     *
     * @throws \Exception
     */
    public function updateLeadFromTDStatus(
        string $vinNum,
        string $tdNum,
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
            if (! $lead) {
                throw new \Exception("Сделка с VIN {$vinNum} не найдена");
            }
            $leadId = $lead->getId();
        }

        $pipelineKey = $this->getPipelineKey($lead);

        // 2. Определить поддерживаемое событие (регистронезависимо)
        $receivedStatusLower = mb_strtolower(trim($status), 'UTF-8');

        $statusType = match ($receivedStatusLower) {
            'тд зарегистрирована' => 'registration',
            'транзит завершен' => 'completion',
            default => null,
        };

        if ($statusType === null) {
            throw new \Exception(
                "Статус '{$status}' не поддерживается. Ожидается: 'ТД Зарегистрирована' или 'Транзит завершен'"
            );
        }

        // 3. Найти канонический текст статуса в конфигурации (регистронезависимо)
        $statusFullText = null;
        $statusConfig = config('amocrm.fields.status_td.values');

        if ($statusConfig) {
            foreach (array_keys($statusConfig) as $configText) {
                if (mb_strtolower($configText, 'UTF-8') === $receivedStatusLower) {
                    $statusFullText = $configText;
                    break;
                }
            }
        }

        if ($statusFullText === null) {
            throw new \Exception("Статус ТД '{$status}' не найден в конфигурации");
        }

        // 4. Конвертировать дату в timestamp
        $dateTimestamp = $this->parseDateString($statusDate);

        // 5. Получить текущий статус сделки
        $currentStatusId = $lead->getStatusId();

        if ($pipelineKey === 'office_moscow') {
            return $this->ignoredStatusResult($lead, $pipelineKey, 'Статусы транзитной ДТ не обрабатываются в воронке «Офис Москва»');
        }

        $transitClosedStatusId = $this->getPipelineStatusId('transit_russia', 'td_completion');
        if ($pipelineKey === 'transit_russia'
            && $statusType === 'registration'
            && $currentStatusId === $transitClosedStatusId) {
            return $this->ignoredStatusResult($lead, $pipelineKey, 'Регистрация ТД не открывает уже завершенный транзит');
        }

        // 6. Определить целевой этап с учетом текущей воронки.
        $tdStatusesToChange = config('amocrm.td_statuses_to_change', []);
        $targetStatusId = null;

        if ($pipelineKey === 'main'
            && $statusType === 'registration'
            && in_array($currentStatusId, $tdStatusesToChange, true)) {
            $targetStatusId = $this->getPipelineStatusId('main', 'td_registration');
        } elseif ($pipelineKey === 'transit_russia') {
            $targetStatusId = $this->getPipelineStatusId(
                $pipelineKey,
                $statusType === 'registration' ? 'td_registration' : 'td_completion'
            );
        }

        $shouldChangeStatus = $targetStatusId !== null && $currentStatusId !== $targetStatusId;
        $stageChanged = false;

        // 7. Логирование начала обработки
        Log::info('TD status update: начало обработки', [
            'lead_id' => $leadId,
            'vin' => $vinNum,
            'td_num' => $tdNum,
            'status' => $statusFullText,
            'status_type' => $statusType,
            'pipeline' => $pipelineKey,
            'status_date' => $statusDate,
            'current_stage_id' => $currentStatusId,
            'target_stage_id' => $targetStatusId,
            'should_change_status' => $shouldChangeStatus,
        ]);

        // 8. Подготовить поля для конкретного события
        if ($statusType === 'registration') {
            $fieldsToUpdate = [
                ['field_key' => 'nomer_td', 'value' => $tdNum, 'type' => 'text'],
                ['field_key' => 'status_td', 'value' => $statusFullText, 'type' => 'select'],
                ['field_key' => 'registration_date_td', 'value' => $dateTimestamp, 'type' => 'datetime'],
            ];
        } else {
            $fieldsToUpdate = [
                ['field_key' => 'status_td', 'value' => $statusFullText, 'type' => 'select'],
                ['field_key' => 'completion_date_td', 'value' => $dateTimestamp, 'type' => 'datetime'],
            ];
        }

        // 9. Обновить кастомные поля (без переноса в историю)
        app(CustomFieldService::class)->updateLeadCustomFields(
            $leadId,
            $fieldsToUpdate,
            false // Для ТД истории нет
        );

        Log::info('TD status update: поля обновлены', [
            'lead_id' => $leadId,
            'fields_updated' => array_column($fieldsToUpdate, 'field_key'),
        ]);

        // 10. Изменить статус сделки, если требуется
        if ($shouldChangeStatus) {
            $this->updateLeadStatusById($leadId, $targetStatusId);
            $stageChanged = true;

            Log::info('TD status update: статус сделки изменен', [
                'lead_id' => $leadId,
                'old_status_id' => $currentStatusId,
                'new_status_id' => $targetStatusId,
            ]);
        } else {
            $reason = match (true) {
                $currentStatusId === $targetStatusId => 'сделка уже находится на целевом этапе',
                $pipelineKey === 'main' && $statusType === 'completion' => 'завершение транзита не меняет этап основной воронки',
                default => 'текущий этап не входит в разрешенный список перехода',
            };

            Log::info('TD status update: статус сделки НЕ изменен', [
                'lead_id' => $leadId,
                'current_status_id' => $currentStatusId,
                'reason' => $reason,
            ]);
        }

        // 11. Возврат результата
        return [
            'lead_id' => $leadId,
            'status' => 'OK',
            'pipeline' => $pipelineKey,
            'stage_changed' => $stageChanged,
            'current_stage_id' => $currentStatusId,
            'new_stage_id' => $stageChanged ? $targetStatusId : null,
        ];
    }

    /**
     * Определить настроенную воронку, в которой находится сделка.
     */
    private function getPipelineKey(LeadModel $lead): string
    {
        $pipelineId = $lead->getPipelineId();

        foreach (config('amocrm.pipelines', []) as $pipelineKey => $pipeline) {
            if ((int) ($pipeline['id'] ?? 0) === $pipelineId) {
                return $pipelineKey;
            }
        }

        throw new \Exception("Воронка {$pipelineId} не поддерживается");
    }

    /**
     * Получить ID этапа внутри настроенной воронки.
     */
    private function getPipelineStatusId(string $pipelineKey, string $statusKey): int
    {
        $statusId = config("amocrm.pipelines.{$pipelineKey}.statuses.{$statusKey}");

        if (! $statusId) {
            throw new \Exception("Этап {$statusKey} для воронки {$pipelineKey} не задан в конфигурации");
        }

        return (int) $statusId;
    }

    /**
     * Вернуть успешный результат для события, которое не относится к этой воронке.
     */
    private function ignoredStatusResult(LeadModel $lead, string $pipelineKey, string $reason): array
    {
        Log::info('Статус проигнорирован для текущей воронки', [
            'lead_id' => $lead->getId(),
            'pipeline' => $pipelineKey,
            'current_stage_id' => $lead->getStatusId(),
            'reason' => $reason,
        ]);

        return [
            'lead_id' => $lead->getId(),
            'status' => 'OK',
            'pipeline' => $pipelineKey,
            'ignored' => true,
            'ignore_reason' => $reason,
            'stage' => null,
            'highlight_red' => false,
            'moved_to_history' => false,
            'stage_protection_active' => false,
            'current_stage_id' => $lead->getStatusId(),
            'stage_changed' => false,
            'new_stage_id' => null,
        ];
    }

    /**
     * Парсинг строки даты в timestamp
     * Поддерживает форматы: "dd.mm.yyyy hh:mm", "dd.mm.yyyy hh.mm" и "yyyy-mm-dd hh:mm:ss"
     *
     * @param  string  $dateString  Строка даты
     * @return int Timestamp
     *
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

            $timestamp = mktime((int) $hour - 10, (int) $minute, 0, (int) $month, (int) $day, (int) $year);

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

            $timestamp = mktime((int) $hour - 10, (int) $minute, (int) $second, (int) $month, (int) $day, (int) $year);

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

                // Форматирование даты в YYYY-MM-DD формат с коррекцией на timezone AmoCRM UTC+10
                if (in_array($field['field_type'], ['date', 'birthday']) && ! empty($processedValue)) {
                    $timestamp = strtotime($processedValue);
                    $timestamp += 10 * 3600; // корректировка +10 часов для совпадения с отображением в AmoCRM
                    $processedValue = date('Y-m-d', $timestamp);
                }

                // Форматирование серии паспорта (4 цифры разделить по 2 пробелом)
                if ($field['field_name'] === 'Серия паспорта' && strlen($processedValue) === 4 && is_numeric($processedValue)) {
                    $processedValue = substr($processedValue, 0, 2).' '.substr($processedValue, 2, 2);
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
