<?php

namespace App\Http\Controllers;

use AmoCRM\Client\AmoCRMApiClient;
use Illuminate\Http\Request;
use League\OAuth2\Client\Token\AccessToken;

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

            return response()->json($lead->toArray());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
