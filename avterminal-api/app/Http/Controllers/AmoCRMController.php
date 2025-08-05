<?php

namespace App\Http\Controllers;

use AmoCRM\Client\AmoCRMApiClient;
use Illuminate\Http\Request;
use League\OAuth2\Client\Token\AccessToken;
use AmoCRM\Filters\ContactsFilter;

class AmoCRMController extends Controller
{
    protected $apiClient;

    public function __construct()
    {
        $config = config('amocrm');

        // Создаем apiClient, используя данные из конфигурации
        $apiClient = new AmoCRMApiClient($config['client_id'], $config['client_secret']);

        // Устанавливаем домен
        $apiClient->setAccountBaseDomain($config['subdomain'] . '.amocrm.ru');

        // Создаем объект токена из долгосрочного ключа из конфигурации
        $accessToken = new AccessToken([
            'access_token' => $config['long_lived_token'],
            'refresh_token' => 'placeholder_refresh_token', // Токен долгосрочный, refresh_token не используется
            'expires' => time() + 86400 * 365 * 10, // Устанавливаем "вечный" срок жизни
        ]);

        
        $apiClient->setAccessToken($accessToken);
        $this->apiClient = $apiClient;
    }

    public function info()
    {
        try {
            // Получение всех полей для сделок
            $leadsCustomFields = $this->apiClient->customFields('leads')->get();

            // Получение всех полей для контактов
            $contactsCustomFields = $this->apiClient->customFields('contacts')->get();

            return response()->json([
                'leads_custom_fields' => $leadsCustomFields->toArray(),
                'contacts_custom_fields' => $contactsCustomFields->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function exportToXml()
    {
        try {
            $data = $this->info()->getData(true);

            $xml = new \SimpleXMLElement('<root/>');

            $this->arrayToXml($data, $xml);

            $headers = [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="amocrm_fields.xml"',
            ];

            return response($xml->asXML(), 200, $headers);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function arrayToXml(array $data, \SimpleXMLElement $xml)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (!is_numeric($key)) {
                    $subnode = $xml->addChild("$key");
                    $this->arrayToXml($value, $subnode);
                } else {
                    $subnode = $xml->addChild("item$key");
                    $this->arrayToXml($value, $subnode);
                }
            } else {
                $xml->addChild("$key", htmlspecialchars("$value"));
            }
        }
    }

    public function getLeadData(Request $request, $id)
    {
        try {
            // Получение сделки вместе с контактами
            $lead = $this->apiClient->leads()->getOne($id, ['contacts']);

            $leadArray = $lead->toArray();

            if (isset($leadArray['contacts'])) {
                $contactIds = array_map(function ($contact) {
                    return $contact['id'];
                }, $leadArray['contacts']);

                if (!empty($contactIds)) {
                    $filter = new ContactsFilter();
                    $filter->setIds($contactIds);
                    $contacts = $this->apiClient->contacts()->get($filter);
                    $leadArray['contacts'] = $contacts->toArray();
                }
            }

            return response()->json($leadArray);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getFormattedLeadAndContactData(Request $request, $id)
    {
        try {
            // Получение сделки вместе с контактами
            $lead = $this->apiClient->leads()->getOne($id, ['contacts']);
            $leadArray = $lead->toArray();

            $contact = null;
            if (isset($leadArray['contacts'][0])) {
                $contactId = $leadArray['contacts'][0]['id'];
                $contact = $this->apiClient->contacts()->getOne($contactId);
                $contact = $contact->toArray();
            }

            $leadCustomFields = $this->formatCustomFields($leadArray['custom_fields_values'] ?? []);
            $contactCustomFields = $this->formatCustomFields($contact['custom_fields_values'] ?? []);

            $allCustomFields = array_merge($leadCustomFields, $contactCustomFields);

            $response_data = [
                'lead_id' => $leadArray['id'],
                'contact_id' => $contact['id'],
                'custom_fields' => $allCustomFields
            ];

            return response()->json($response_data);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function formatCustomFields(array $customFields): array
    {
        $formattedFields = [];

        foreach ($customFields as $field) {
            $values = [];
            foreach ($field['values'] as $value) {
                $processedValue = $value['value'];

                // 2) Если значение дата, то отформатируй его в YYYY-MM-DD формат
                if (in_array($field['field_type'], ['date', 'birthday']) && !empty($processedValue)) {
                    $processedValue = date('Y-m-d', strtotime($processedValue));
                }

                // 4) Если это поле "Серия паспорта" то нужно чтобы 4 цифры были разделе по 2 пробелов
                if ($field['field_name'] === 'Серия паспорта' && strlen($processedValue) === 4 && is_numeric($processedValue)) {
                    $processedValue = substr($processedValue, 0, 2) . ' ' . substr($processedValue, 2, 2);
                }
                
                // 3) Все значения должны быть написаны заглавными буквами
                $values[] = mb_strtoupper($processedValue, 'UTF-8');
            }
            
            // 1) Если изначально значений несколько, то объедени их через запятую
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
