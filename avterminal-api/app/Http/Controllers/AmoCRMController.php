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

    public function generateXmlByLeadId(Request $request, $id)
    {
        try {
            $leadData = $this->getFormattedLeadAndContactData($request, $id)->getData(true);

            $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="windows-1251"?><AltaPassengerDeclaration/>');

            $this->addXmlData($xml, $leadData, $id);

            $headers = [
                'Content-Type' => 'application/xml; charset=windows-1251',
                'Content-Disposition' => 'attachment; filename="lead_' . $id . '.xml"',
            ];

            return response($xml->asXML(), 200, $headers);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function addXmlData(\SimpleXMLElement $xml, array $data, $leadId)
    {
        $fields = [];
        foreach ($data['custom_fields'] as $field) {
            $fields[$field['field_name']] = $field['field_value'];
        }

        // DeclarantPerson
        $xml->addChild('DeclarantPerson_PersonSurname', $fields['Фамилия'] ?? '');
        $xml->addChild('DeclarantPerson_PersonName', $fields['Имя'] ?? '');
        $xml->addChild('DeclarantPerson_PersonMiddleName', $fields['Отчество'] ?? '');
        $xml->addChild('DeclarantPerson_BirthDate', $fields['День рождения'] ?? '');
        $xml->addChild('DeclarantPerson_INN', $fields['ИНН'] ?? '');

        // DeclarantPerson_PersonIdCard
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardCode', 'RU01001');
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardName', 'ПАСПОРТ');
        $xml->addChild('DeclarantPerson_PersonIdCard_FullIdentityCardName', 'ПАСПОРТ ГРАЖДАНИНА РФ');
        $xml->addChild('DeclarantPerson_PersonIdCard_CountryCode', 'RU');
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardSeries', $fields['Серия паспорта'] ?? '');
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardNumber', $fields['Номер паспорта'] ?? '');
        $xml->addChild('DeclarantPerson_PersonIdCard_OrganizationName', $fields['Кем выдан'] ?? '');
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardDate', $fields['Дата выдачи'] ?? '');
        $xml->addChild('DeclarantPerson_PersonIdCard_IssuerCode', $fields['Код подразделения'] ?? '');

        // DeclarantPerson_Address
        $address = $xml->addChild('DeclarantPerson_Address');
        $address->addChild('AddressKindCode', '1');
        $address->addChild('Region', $fields['Субъект федерации'] ?? '');
        $address->addChild('District', $fields['Район'] ?? '');
        $address->addChild('Town', $fields['Город'] ?? '');
        $address->addChild('City', '');
        $address->addChild('StreetHouse', $fields['Улица'] ?? '');
        $address->addChild('House', $fields['Дом'] ?? '');
        $address->addChild('Room', $fields['Квартира'] ?? '');
        $address->addChild('CountryCode', 'RU');
        $address->addChild('CounryName', 'РОССИЯ');

        // TransportMeans
        $xml->addChild('TransportMeans_TransferPurposeCode', '1');
        $transportDetails = $xml->addChild('TransportMeans_TransportMeansDetails');
        $transportDetails->addChild('Mark', $fields['Марка'] ?? '');
        $transportDetails->addChild('Model', $fields['Модель'] ?? '');
        $transportDetails->addChild('VINID', $fields['VIN'] ?? '');
        $transportDetails->addChild('BodyID', $fields['VIN'] ?? '');
        $transportDetails->addChild('TransportModeCode', '30');
        $transportDetails->addChild('TransportMeansRegId', 'ОТСУТСТВУЕТ');
        $transportDetails->addChild('ChassisID', 'ОТСУТСТВУЕТ');
        $transportDetails->addChild('TypeIndicator', '1');
        $transportDetails->addChild('TransportKindName', mb_strtoupper('Автодорожный транспорт, ЗА ИСКЛЮЧЕНИЕМ транспортных средств, указанных под кодами 31, 32', 'UTF-8'));

        // MovingCode
        $xml->addChild('MovingCode', '3');

        // FilledPerson_SigningDetails
        $xml->addChild('FilledPerson_SigningDetails_PersonSurname', 'ПОЛУЭКТОВ');
        $xml->addChild('FilledPerson_SigningDetails_PersonName', 'ВИТАЛИЙ');
        $xml->addChild('FilledPerson_SigningDetails_PersonMiddleName', 'СЕРГЕЕВИЧ');
        $xml->addChild('FilledPerson_SigningDetails_PersonPost', 'ГЕНЕРАЛЬНЫЙ ДИРЕКТОР');

        // RoleCode
        $xml->addChild('RoleCode', '2');

        // SignatoryRepresentativeDetails
        $xml->addChild('SignatoryRepresentativeDetails_BrokerRegistryDocDetails_DocKindCode', '09034');
        $xml->addChild('SignatoryRepresentativeDetails_BrokerRegistryDocDetails_RegistrationNumberId', '1695');
        $xml->addChild('SignatoryRepresentativeDetails_RepresentativeContractDetails_DocKindCode', '11002');
        $xml->addChild('SignatoryRepresentativeDetails_RepresentativeContractDetails_PrDocumentName', 'ДОГОВОР С ТАМОЖЕННЫМ ПРЕДСТАВИТЕЛЕМ');
        $xml->addChild('SignatoryRepresentativeDetails_RepresentativeContractDetails_PrDocumentNumber', $leadId);
        $xml->addChild('SignatoryRepresentativeDetails_RepresentativeContractDetails_PrDocumentDate', date('Y-m-d'));

        // ElectronicDocumentSign
        $xml->addChild('ElectronicDocumentSign', 'ЭД');
    }
}
