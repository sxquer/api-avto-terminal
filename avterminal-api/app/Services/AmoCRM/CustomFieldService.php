<?php

namespace App\Services\AmoCRM;

use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Models\CustomFieldsValues\BaseCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\BaseCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\NullCustomFieldValueCollection;
use AmoCRM\Models\LeadModel;

/**
 * Сервис для работы с кастомными полями AmoCRM
 */
class CustomFieldService
{
    public function __construct(
        private AmoCRMService $amoCRMService
    ) {}

    /**
     * Обновить кастомные поля лида
     * 
     * @param int $leadId ID лида
     * @param array $fieldsToUpdate Массив полей для обновления
     * Формат: [
     *   [
     *     'field_key' => 'color_field_id',  // Ключ из config/amocrm.php
     *     'value' => 'Синий',               // Значение (текстовое для select, получит enum_id из конфига)
     *                                       // Передайте null для сброса значения поля
     *     'type' => 'select',               // Тип: text|textarea|date|datetime|select|multiselect
     *     'append' => false                 // Опционально, только для text/textarea (дописать с новой строки)
     *   ]
     * ]
     * @param bool $moveToHistory Флаг переноса текущих значений полей ДТ в историю
     * 
     * @return LeadModel Обновленный лид
     * @throws \Exception
     */
    public function updateLeadCustomFields(int $leadId, array $fieldsToUpdate, bool $moveToHistory = false): LeadModel
    {
        $apiClient = $this->amoCRMService->getClient();
        
        // Для append или moveToHistory нужно получить текущее значение
        $existingLead = null;
        $needsExistingLead = $moveToHistory;
        
        if (!$needsExistingLead) {
            foreach ($fieldsToUpdate as $fieldData) {
                if (isset($fieldData['append']) && $fieldData['append'] === true) {
                    $needsExistingLead = true;
                    break;
                }
            }
        }
        
        if ($needsExistingLead) {
            $existingLead = $apiClient->leads()->getOne($leadId);
        }
        
        // Логика переноса в историю
        if ($moveToHistory && $existingLead) {
            $fieldsToUpdate = $this->handleMoveToHistory($existingLead, $fieldsToUpdate);
        }
        
        // Создаем НОВУЮ коллекцию только с обновляемыми полями
        $customFieldsValues = new CustomFieldsValuesCollection();
        
        foreach ($fieldsToUpdate as $fieldData) {
            $fieldKey = $fieldData['field_key'];
            $value = $fieldData['value'];
            $type = $fieldData['type'];
            $append = $fieldData['append'] ?? false;
            
            $fieldConfig = config("amocrm.fields.{$fieldKey}");
            
            if (!$fieldConfig) {
                throw new \Exception("Поле {$fieldKey} не найдено в конфигурации");
            }
            
            $fieldId = $fieldConfig['id'];
            
            $preparedValue = $this->prepareFieldValue($existingLead, $fieldId, $value, $type, $append, $fieldConfig);
            
            $this->setCustomFieldValue($customFieldsValues, $fieldId, $preparedValue, $type);
        }
        
        // Создаем НОВЫЙ LeadModel с только ID и custom fields
        $lead = (new LeadModel())
            ->setId($leadId)
            ->setCustomFieldsValues($customFieldsValues);
        
        // Обновляем лид
        return $apiClient->leads()->updateOne($lead);
    }

    /**
     * Подготовить значение поля в зависимости от его типа
     */
    private function prepareFieldValue(?LeadModel $lead, int $fieldId, mixed $value, string $type, bool $append, array $fieldConfig): mixed
    {
        if ($value === null) {
            return null;
        }
        
        switch ($type) {
            case 'text':
            case 'textarea':
                if ($append) {
                    if ($lead === null) {
                        throw new \Exception("Для использования append=true необходимо загрузить существующий лид");
                    }
                    $currentValue = $this->getCurrentFieldValue($lead, $fieldId);
                    if ($currentValue) {
                        return $currentValue . "\n\n" . $value;
                    }
                }
                return $value;
                
            case 'date':
            case 'datetime':
                if (is_string($value)) {
                    return strtotime($value);
                }
                return $value;
                
            case 'select':
            case 'multiselect':
                if (isset($fieldConfig['values'][$value])) {
                    return $fieldConfig['values'][$value];
                }
                throw new \Exception("Значение '{$value}' не найдено в конфигурации для поля");
                
            default:
                return $value;
        }
    }

    /**
     * Получить текущее значение кастомного поля лида
     */
    private function getCurrentFieldValue(LeadModel $lead, int $fieldId): ?string
    {
        $customFieldsValues = $lead->getCustomFieldsValues();
        
        if (!$customFieldsValues) {
            return null;
        }
        
        $field = $customFieldsValues->getBy('fieldId', $fieldId);
        
        if (!$field) {
            return null;
        }
        
        $values = $field->getValues();
        
        if ($values && $values->first()) {
            return $values->first()->getValue();
        }
        
        return null;
    }

    /**
     * Установить значение кастомного поля в коллекции
     */
    private function setCustomFieldValue(CustomFieldsValuesCollection $collection, int $fieldId, mixed $value, string $type): void
    {
        $existingField = $collection->getBy('fieldId', $fieldId);
        
        // Если значение null, устанавливаем пустую коллекцию для сброса значения в AmoCRM
        if ($value === null) {
            // Создаем или обновляем поле с пустой коллекцией values
            if ($existingField) {
                // Устанавливаем пустую коллекцию для существующего поля
                $emptyValueCollection = $this->createEmptyValueCollection($type);
                $existingField->setValues($emptyValueCollection);
            } else {
                // Создаем новое поле с пустой коллекцией
                $fieldValueModel = $this->createFieldValueModel($type);
                $fieldValueModel->setFieldId($fieldId);
                $emptyValueCollection = $this->createEmptyValueCollection($type);
                $fieldValueModel->setValues($emptyValueCollection);
                $collection->add($fieldValueModel);
            }
            return;
        }
        
        if ($existingField) {
            $valueCollection = $this->createValueCollection($type, $value);
            $existingField->setValues($valueCollection);
        } else {
            $fieldValueModel = $this->createFieldValueModel($type);
            $fieldValueModel->setFieldId($fieldId);
            
            $valueCollection = $this->createValueCollection($type, $value);
            $fieldValueModel->setValues($valueCollection);
            
            $collection->add($fieldValueModel);
        }
    }

    /**
     * Создать модель значения поля в зависимости от типа
     */
    private function createFieldValueModel(string $type): BaseCustomFieldValuesModel
    {
        return match ($type) {
            'text' => new \AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel(),
            'textarea' => new \AmoCRM\Models\CustomFieldsValues\TextareaCustomFieldValuesModel(),
            'date' => new \AmoCRM\Models\CustomFieldsValues\DateCustomFieldValuesModel(),
            'datetime' => new \AmoCRM\Models\CustomFieldsValues\DateTimeCustomFieldValuesModel(),
            'select' => new \AmoCRM\Models\CustomFieldsValues\SelectCustomFieldValuesModel(),
            'multiselect' => new \AmoCRM\Models\CustomFieldsValues\MultiselectCustomFieldValuesModel(),
            default => new \AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel(),
        };
    }

    /**
     * Создать пустую коллекцию значений для сброса поля
     * Использует NullCustomFieldValueCollection для корректного сброса значений в AmoCRM
     */
    private function createEmptyValueCollection(string $type): BaseCustomFieldValueCollection
    {
        // Для сброса значений используется специальная коллекция NullCustomFieldValueCollection
        return new NullCustomFieldValueCollection();
    }

    /**
     * Создать коллекцию значений для поля
     */
    private function createValueCollection(string $type, mixed $value): BaseCustomFieldValueCollection
    {
        return match ($type) {
            'text' => $this->createTextValueCollection($value),
            'textarea' => $this->createTextareaValueCollection($value),
            'date', 'datetime' => $this->createDateTimeValueCollection($value),
            'select' => $this->createSelectValueCollection($value),
            'multiselect' => $this->createMultiselectValueCollection($value),
            default => $this->createTextValueCollection($value),
        };
    }

    private function createTextValueCollection(mixed $value): \AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection
    {
        $valueModel = new \AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel();
        $valueModel->setValue($value);
        return (new \AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection())->add($valueModel);
    }

    private function createTextareaValueCollection(mixed $value): \AmoCRM\Models\CustomFieldsValues\ValueCollections\TextareaCustomFieldValueCollection
    {
        $valueModel = new \AmoCRM\Models\CustomFieldsValues\ValueModels\TextareaCustomFieldValueModel();
        $valueModel->setValue($value);
        return (new \AmoCRM\Models\CustomFieldsValues\ValueCollections\TextareaCustomFieldValueCollection())->add($valueModel);
    }

    private function createDateTimeValueCollection(mixed $value): \AmoCRM\Models\CustomFieldsValues\ValueCollections\DateTimeCustomFieldValueCollection
    {
        $valueModel = new \AmoCRM\Models\CustomFieldsValues\ValueModels\DateTimeCustomFieldValueModel();
        $valueModel->setValue($value);
        return (new \AmoCRM\Models\CustomFieldsValues\ValueCollections\DateTimeCustomFieldValueCollection())->add($valueModel);
    }

    private function createSelectValueCollection(mixed $value): \AmoCRM\Models\CustomFieldsValues\ValueCollections\SelectCustomFieldValueCollection
    {
        $valueModel = new \AmoCRM\Models\CustomFieldsValues\ValueModels\SelectCustomFieldValueModel();
        $valueModel->setEnumId($value);
        return (new \AmoCRM\Models\CustomFieldsValues\ValueCollections\SelectCustomFieldValueCollection())->add($valueModel);
    }

    private function createMultiselectValueCollection(mixed $value): \AmoCRM\Models\CustomFieldsValues\ValueCollections\MultiselectCustomFieldValueCollection
    {
        $valueModel = new \AmoCRM\Models\CustomFieldsValues\ValueModels\MultiselectCustomFieldValueModel();
        $valueModel->setEnumId($value);
        return (new \AmoCRM\Models\CustomFieldsValues\ValueCollections\MultiselectCustomFieldValueCollection())->add($valueModel);
    }

    /**
     * Обработать перенос данных в историю
     * 
     * @param LeadModel $existingLead Текущий лид
     * @param array $fieldsToUpdate Массив полей для обновления
     * @return array Дополненный массив полей с обнулением и записью истории
     */
    private function handleMoveToHistory(LeadModel $existingLead, array $fieldsToUpdate): array
    {
        // Извлекаем текущие значения полей ДТ
        $historyData = $this->extractHistoryData($existingLead);
        
        // Формируем запись для истории
        $historyEntry = $this->buildHistoryEntry($historyData);
        
        // Получаем текущее значение поля history
        $currentHistory = $this->getCurrentFieldValue(
            $existingLead, 
            config('amocrm.fields.history.id')
        );
        
        // Добавляем новую запись в историю
        $newHistory = $currentHistory 
            ? $currentHistory . "\n\n" . $historyEntry 
            : $historyEntry;
        
        // Подготавливаем массив полей для обнуления и обновления истории
        $fieldsToReset = [
            ['field_key' => 'nomer_dt', 'value' => null, 'type' => 'text'],
            ['field_key' => 'status_dt', 'value' => null, 'type' => 'select'],
            ['field_key' => 'registration_date', 'value' => null, 'type' => 'date'],
            ['field_key' => 'vipusk_date', 'value' => null, 'type' => 'date'],
            ['field_key' => 'refuse_date', 'value' => null, 'type' => 'date'],
            ['field_key' => 'history', 'value' => $newHistory, 'type' => 'textarea'],
        ];
        
        // Объединяем: сначала обнуление и запись истории, потом новые значения
        return array_merge($fieldsToReset, $fieldsToUpdate);
    }

    /**
     * Извлечь данные для истории из лида
     * 
     * @param LeadModel $lead Лид
     * @return array Массив с данными для истории
     */
    private function extractHistoryData(LeadModel $lead): array
    {
        return [
            'nomer_dt' => $this->getCurrentFieldValue(
                $lead, 
                config('amocrm.fields.nomer_dt.id')
            ) ?? '-',
            'status_dt' => $this->getSelectFieldText(
                $lead, 
                config('amocrm.fields.status_dt.id'),
                'status_dt'
            ) ?? '-',
            'registration_date' => $this->formatDateField(
                $this->getCurrentFieldValue(
                    $lead, 
                    config('amocrm.fields.registration_date.id')
                )
            ),
            'vipusk_date' => $this->formatDateField(
                $this->getCurrentFieldValue(
                    $lead, 
                    config('amocrm.fields.vipusk_date.id')
                )
            ),
            'refuse_date' => $this->formatDateField(
                $this->getCurrentFieldValue(
                    $lead, 
                    config('amocrm.fields.refuse_date.id')
                )
            ),
        ];
    }

    /**
     * Сформировать текст записи истории
     * 
     * @param array $data Данные для истории
     * @return string Текст записи истории
     */
    private function buildHistoryEntry(array $data): string
    {
        return "# Декларация: {$data['nomer_dt']}\n" .
               "# Статус ДТ: {$data['status_dt']}\n" .
               "# Дата регистрации ДТ: {$data['registration_date']}\n" .
               "# Дата выпуска ДТ: {$data['vipusk_date']}\n" .
               "# Дата отказа ДТ: {$data['refuse_date']}";
    }

    /**
     * Получить текстовое значение select-поля
     * 
     * @param LeadModel $lead Лид
     * @param int $fieldId ID поля
     * @param string $fieldKey Ключ поля в конфиге
     * @return string|null Текстовое значение или null
     */
    private function getSelectFieldText(LeadModel $lead, int $fieldId, string $fieldKey): ?string
    {
        $customFieldsValues = $lead->getCustomFieldsValues();
        
        if (!$customFieldsValues) {
            return null;
        }
        
        $field = $customFieldsValues->getBy('fieldId', $fieldId);
        
        if (!$field) {
            return null;
        }
        
        $values = $field->getValues();
        
        if (!$values || !$values->first()) {
            return null;
        }
        
        $firstValue = $values->first();
        
        // Для select-полей используем getEnumId() или getEnumCode() в зависимости от версии SDK
        $enumId = method_exists($firstValue, 'getEnumId') 
            ? $firstValue->getEnumId() 
            : (method_exists($firstValue, 'getValue') ? $firstValue->getValue() : null);
        
        if (!$enumId) {
            return null;
        }
        
        // Получаем обратный маппинг: enum_id => текст
        return $this->getEnumTextByValue($enumId, $fieldKey);
    }

    /**
     * Получить текстовое значение по enum_id (обратный маппинг)
     * 
     * @param int $enumId ID значения enum
     * @param string $fieldKey Ключ поля в конфиге
     * @return string|null Текстовое значение или null
     */
    private function getEnumTextByValue(int $enumId, string $fieldKey): ?string
    {
        $fieldConfig = config("amocrm.fields.{$fieldKey}");
        
        if (!$fieldConfig || !isset($fieldConfig['values'])) {
            return null;
        }
        
        foreach ($fieldConfig['values'] as $text => $id) {
            if ($id === $enumId) {
                return $text;
            }
        }
        
        return null;
    }

    /**
     * Форматировать поле даты для отображения
     * 
     * @param mixed $value Значение даты (timestamp или строка)
     * @return string Форматированная дата dd.mm.yyyy или '-'
     */
    private function formatDateField(mixed $value): string
    {
        if (!$value) {
            return '-';
        }
        
        // Если значение уже строка, пытаемся ее распарсить
        if (is_string($value)) {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return '-';
            }
            return date('d.m.Y', $timestamp);
        }
        
        // Если timestamp
        if (is_numeric($value)) {
            return date('d.m.Y', (int)$value);
        }
        
        return '-';
    }
}
