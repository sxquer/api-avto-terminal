<?php

namespace App\Services\AmoCRM;

use AmoCRM\Client\AmoCRMApiClient;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Сервис для инициализации и управления API клиентом AmoCRM
 */
class AmoCRMService
{
    protected AmoCRMApiClient $apiClient;

    public function __construct()
    {
        $this->initializeClient();
    }

    /**
     * Инициализация API клиента AmoCRM
     */
    private function initializeClient(): void
    {
        $config = config('amocrm');

        $this->apiClient = new AmoCRMApiClient($config['client_id'], $config['client_secret']);
        $this->apiClient->setAccountBaseDomain($config['subdomain'] . '.amocrm.ru');

        $accessToken = new AccessToken([
            'access_token' => $config['long_lived_token'],
            'refresh_token' => 'placeholder_refresh_token',
            'expires' => time() + 86400 * 365 * 10,
        ]);

        $this->apiClient->setAccessToken($accessToken);
    }

    /**
     * Получить API клиент
     */
    public function getClient(): AmoCRMApiClient
    {
        return $this->apiClient;
    }

    /**
     * Получить информацию о всех кастомных полях
     */
    public function getFieldsInfo(): array
    {
        $leadsCustomFields = $this->apiClient->customFields('leads')->get();
        $contactsCustomFields = $this->apiClient->customFields('contacts')->get();

        return [
            'leads_custom_fields' => $leadsCustomFields->toArray(),
            'contacts_custom_fields' => $contactsCustomFields->toArray(),
        ];
    }

    /**
     * Получить опции enum для кастомного поля типа список
     * 
     * @param int $fieldId ID кастомного поля
     * @param string $entityType Тип сущности ('leads', 'contacts', 'companies')
     * @return array Массив в формате ["Текстовое значение" => enum_id]
     * @throws \Exception
     */
    public function getCustomFieldEnumOptions(int $fieldId, string $entityType = 'leads'): array
    {
        $customField = $this->apiClient->customFields($entityType)->getOne($fieldId);
        
        if (!$customField) {
            throw new \Exception("Поле с ID {$fieldId} не найдено");
        }
        
        $fieldArray = $customField->toArray();
        
        if (!in_array($fieldArray['type'], ['select', 'multiselect'])) {
            throw new \Exception("Поле с ID {$fieldId} не является полем типа список (текущий тип: {$fieldArray['type']})");
        }
        
        $enumOptions = [];
        if (isset($fieldArray['enums']) && is_array($fieldArray['enums'])) {
            foreach ($fieldArray['enums'] as $enum) {
                $enumOptions[$enum['value']] = $enum['id'];
            }
        }
        
        return $enumOptions;
    }
}
