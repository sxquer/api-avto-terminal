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

        // Создаем apiClient, не передавая clientId и clientSecret в конструктор
        $apiClient = new AmoCRMApiClient("0b6002fd-93c6-4821-8529-6d310f001ef2", "5iXjnkIEQSdLJOvITKavDPUnT2RLPsZRqLJEmSnS7EfCht23YajAKIIuNu6QbW0k");

        // Устанавливаем домен

        $apiClient->setAccountBaseDomain($config['subdomain'] . '.amocrm.ru');

        // Создаем объект токена из долгосрочного ключа
        $accessToken = new AccessToken([
            'access_token' => "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6IjA1NTQwMTM2MWE4NTUwNjI5M2I4MTJlNzZmMmQzZjA4ZDc1YzQ5ODU3MjYyMzBkYmFjZWVkZjhkM2IwYzhhNDFkMDQwOWYwMjFjOGI2M2MwIn0.eyJhdWQiOiIwYjYwMDJmZC05M2M2LTQ4MjEtODUyOS02ZDMxMGYwMDFlZjIiLCJqdGkiOiIwNTU0MDEzNjFhODU1MDYyOTNiODEyZTc2ZjJkM2YwOGQ3NWM0OTg1NzI2MjMwZGJhY2VlZGY4ZDNiMGM4YTQxZDA0MDlmMDIxYzhiNjNjMCIsImlhdCI6MTc1Mzc4NDM0OCwibmJmIjoxNzUzNzg0MzQ4LCJleHAiOjE3NTM5MjAwMDAsInN1YiI6IjEwMzkxNzAyIiwiZ3JhbnRfdHlwZSI6IiIsImFjY291bnRfaWQiOjMxNDMxNzAyLCJiYXNlX2RvbWFpbiI6ImFtb2NybS5ydSIsInZlcnNpb24iOjIsInNjb3BlcyI6WyJjcm0iLCJmaWxlcyIsImZpbGVzX2RlbGV0ZSIsIm5vdGlmaWNhdGlvbnMiLCJwdXNoX25vdGlmaWNhdGlvbnMiXSwiaGFzaF91dWlkIjoiYTcxMTU0NDUtOTEyNi00MDBlLTg4NzAtOTEwN2VmMTg1OTJhIiwiYXBpX2RvbWFpbiI6ImFwaS1iLmFtb2NybS5ydSJ9.ZpI7fF62-GUkseLIArWkCnYr9secrkX5CDNz6iMfh5uMYVY8LTsGgK46MVKDgYMBbnyZ_GQW5htuPpP50vJvq5DzcERqZdOM3BhA8eHQyjyKAw1ObE2_xBRIktilN8ct_4pQNyCKZWLi-xPwfY5dzGinUuVkcIKOkAH2OoIChzq3Gvvg96px346KjCGSNE5HdsqXTkPf8SkPKhEciTFEDgz5nbjr2xpmcnUMOQz9MG-o5uutQpBNE3KVCt5PS6hN3Td_iGJT_IVmy55js3pnYLtzymxKSnwr0s2rkMHLbd-C03dSn2TmzctApMAyGFfzQisRi-LUZslfo1w29-u81A",
            'refresh_token' => "2232313213123123123",
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
}
