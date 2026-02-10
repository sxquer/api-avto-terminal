<?php

namespace App\Http\Controllers;

use App\Services\AmoCRM\AmoCRMService;
use App\Services\AmoCRM\CustomFieldService;
use App\Services\AmoCRM\LeadService;
use App\Services\AmoCRM\XmlGeneratorService;
use Illuminate\Http\Request;

class AmoCRMController extends Controller
{
    public function __construct(
        private AmoCRMService $amoCRMService,
        private LeadService $leadService,
        private CustomFieldService $customFieldService,
        private XmlGeneratorService $xmlGeneratorService
    ) {}

    /**
     * Получить информацию о всех кастомных полях
     */
    public function info()
    {
        try {
            $data = $this->amoCRMService->getFieldsInfo();
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Экспортировать информацию о полях в XML
     */
    public function exportToXml()
    {
        try {
            $data = $this->amoCRMService->getFieldsInfo();
            $xml = new \SimpleXMLElement('<root/>');
            $this->xmlGeneratorService->arrayToXml($data, $xml);

            $headers = [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="amocrm_fields.xml"',
            ];

            return response($xml->asXML(), 200, $headers);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Получить данные сделки по ID
     */
    public function getLeadData(Request $request, $id)
    {
        try {
            $leadData = $this->leadService->getLeadData($id);
            return response()->json($leadData);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Получить форматированные данные сделки и контакта
     */
    public function getFormattedLeadAndContactData(Request $request, $id)
    {
        try {
            $data = $this->leadService->getFormattedLeadAndContactData($id);
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Генерировать XML по ID сделки
     */
    public function generateXmlByLeadId(Request $request, $id)
    {
        try {
            $leadData = $this->leadService->getFormattedLeadAndContactData($id);
            $xml = $this->xmlGeneratorService->generatePassengerDeclarationXml($leadData, $id);

            $headers = [
                'Content-Type' => 'application/xml; charset=windows-1251',
                'Content-Disposition' => 'attachment; filename="lead_' . $id . '.xml"',
            ];

            return response($xml->asXML(), 200, $headers);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Найти сделку по VIN
     */
    public function findLeadByVin($vin)
    {
        try {
            return $this->leadService->findLeadByVin($vin);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Тестовый метод для поиска по VIN
     */
    public function testFindByVin(Request $request)
    {
        $this->customFieldService->updateLeadCustomFields(25147637, [
            [
                'field_key' => 'nomer_dt',
                'value' => 'Новый номер ДТ',
                'type' => 'text'
            ],
            [
                'field_key' => 'registration_date',
                'value' => now(),
                'type' => 'date'
            ]
        ], true);

        $this->leadService->updateLeadStatus(25147637, 'vipusk');
        
        
        return true;
    }

    /**
     * Получить опции enum для кастомного поля типа список
     * 
     * @param int $fieldId ID кастомного поля
     * @param string $entityType Тип сущности ('leads', 'contacts', 'companies')
     * @return array Массив в формате ["Текстовое значение" => enum_id]
     * @throws \Exception
     */
    public function getCustomFieldEnumOptions($fieldId, $entityType = 'leads')
    {
        try {
            return $this->amoCRMService->getCustomFieldEnumOptions($fieldId, $entityType);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Обновить кастомные поля лида
     * 
     * @param int $leadId ID лида
     * @param array $fieldsToUpdate Массив полей для обновления
     * @return \AmoCRM\Models\LeadModel Обновленный лид
     * @throws \Exception
     */
    public function updateLeadCustomFields(int $leadId, array $fieldsToUpdate)
    {
        try {
            return $this->customFieldService->updateLeadCustomFields($leadId, $fieldsToUpdate);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Обновить статус ДТ сделки
     * 
     * Принимает JSON:
     * {
     *   "vinNum": "LSGXC8356MV107322",
     *   "pdNum": "10716050/151025/А050168",
     *   "status": "Регистрация ПТД",
     *   "statusDate": "2025-10-15 16:27:11"
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateDtStatus(Request $request)
    {
        try {
            // Валидация входящих данных
            $validated = $request->validate([
                'vinNum' => 'required|string',
                'pdNum' => 'required|string',
                'status' => 'required|string',
                'statusDate' => 'required|string',
            ]);

            // Получаем testMode из конфига (можно добавить в .env)
            $testMode = config('amocrm.test_mode', false);

            // Вызываем метод обработки
            $result = $this->leadService->updateLeadFromDtStatus(
                $validated['vinNum'],
                $validated['pdNum'],
                $validated['status'],
                $validated['statusDate'],
                $testMode
            );

            return response()->json(['message' => 'OK'], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить статус транзитного ДТ сделки
     * 
     * Принимает JSON:
     * {
     *   "vinNum": "LSGXC8356MV107322",
     *   "tdNum": "10716050/151025/0А050168",
     *   "status": "ТД Зарегистрирована",
     *   "statusDate": "2025-10-15 16:27:11"
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateTDStatus(Request $request)
    {
        try {
            // Валидация входящих данных
            $validated = $request->validate([
                'vinNum' => 'required|string',
                'tdNum' => 'required|string',
                'status' => 'required|string',
                'statusDate' => 'required|string',
            ]);

            // Получаем testMode из конфига (можно добавить в .env)
            $testMode = config('amocrm.test_mode', false);

            // Вызываем метод обработки
            $result = $this->leadService->updateLeadFromTDStatus(
                $validated['vinNum'],
                $validated['tdNum'],
                $validated['status'],
                $validated['statusDate'],
                $testMode
            );

            return response()->json(['message' => 'OK'], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Запустить тестовый сценарий
     * 
     * @param int $testNumber Номер теста (1-10)
     * @return \Illuminate\Http\JsonResponse
     */
    public function runDtStatusTest($testNumber)
    {
        $tests = $this->getDtStatusTests();
        
        if (!isset($tests[$testNumber])) {
            return response()->json([
                'error' => 'Тест не найден',
                'available_tests' => array_keys($tests)
            ], 404, ['Content-Type' => 'application/json; charset=utf-8'], JSON_UNESCAPED_UNICODE);
        }
        
        $test = $tests[$testNumber];
        
        try {
            $testMode = config('amocrm.test_mode', false);
            
            $result = $this->leadService->updateLeadFromDtStatus(
                $test['data']['vinNum'],
                $test['data']['pdNum'],
                $test['data']['status'],
                $test['data']['statusDate'],
                $testMode
            );
            
            return response()->json([
                'test_number' => $testNumber,
                'test_name' => $test['name'],
                'description' => $test['description'],
                'request_data' => $test['data'],
                'expected_result' => $test['expected'],
                'actual_result' => $result,
                'status' => 'SUCCESS',
                'message' => 'Тест выполнен успешно'
            ], 200, ['Content-Type' => 'application/json; charset=utf-8'], JSON_UNESCAPED_UNICODE);
            
        } catch (\Exception $e) {
            return response()->json([
                'test_number' => $testNumber,
                'test_name' => $test['name'],
                'description' => $test['description'],
                'request_data' => $test['data'],
                'expected_result' => $test['expected'],
                'status' => 'FAILED',
                'error' => $e->getMessage()
            ], 500, ['Content-Type' => 'application/json; charset=utf-8'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Получить список всех тестов
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function listDtStatusTests()
    {
        $tests = $this->getDtStatusTests();
        
        $testList = [];
        foreach ($tests as $number => $test) {
            $testList[] = [
                'number' => $number,
                'name' => $test['name'],
                'description' => $test['description'],
                'url' => url("/api/test/{$number}")
            ];
        }
        
        return response()->json([
            'total_tests' => count($testList),
            'tests' => $testList
        ], 200, ['Content-Type' => 'application/json; charset=utf-8'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Получить определения всех тестов
     * 
     * @return array
     */
    private function getDtStatusTests()
    {
        return [
            1 => [
                'name' => 'Регистрация ПТД',
                'description' => 'Тест проверяет обработку статуса "регистрация ПТД". Должна заполниться дата регистрации ДТ и сделка должна переместиться на стадию "ПТД/ДТ".',
                'data' => [
                    'vinNum' => 'TEST001',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '05.11.2025 10:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false
                ]
            ],
            2 => [
                'name' => 'Выпуск без уплаты',
                'description' => 'Тест проверяет обработку статуса "выпуск без уплаты". Должна заполниться дата выпуска ДТ и сделка должна переместиться на стадию "Выпуск ПТД/ДТ".',
                'data' => [
                    'vinNum' => 'TEST002',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'выпуск без уплаты',
                    'statusDate' => '05.11.2025 11:00'
                ],
                'expected' => [
                    'stage' => 'vipusk',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'vipusk_date'],
                    'highlight_red' => false
                ]
            ],
            3 => [
                'name' => 'Выпуск с уплатой',
                'description' => 'Тест проверяет обработку статуса "выпуск с уплатой". Должна заполниться дата выпуска ДТ и сделка должна переместиться на стадию "Выпуск ПТД/ДТ".',
                'data' => [
                    'vinNum' => 'TEST003',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'выпуск с уплатой',
                    'statusDate' => '05.11.2025 12:00'
                ],
                'expected' => [
                    'stage' => 'vipusk',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'vipusk_date'],
                    'highlight_red' => false
                ]
            ],
            4 => [
                'name' => 'Требуется уплата (ожидание)',
                'description' => 'Тест проверяет обработку статуса "требуется уплата". Сделка должна остаться на стадии "ПТД/ДТ" и подсветиться красным цветом.',
                'data' => [
                    'vinNum' => 'TEST004',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'требуется уплата',
                    'statusDate' => '05.11.2025 13:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'color_field_id'],
                    'highlight_red' => true
                ]
            ],
            5 => [
                'name' => 'Ожидание решения по временному ввозу',
                'description' => 'Тест проверяет обработку статуса "выпуск разрешен, ожидание решения по временному ввозу". Сделка должна остаться на стадии "ПТД/ДТ" и подсветиться красным цветом.',
                'data' => [
                    'vinNum' => 'TEST005',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'выпуск разрешен, ожидание решения по временному ввозу',
                    'statusDate' => '05.11.2025 14:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'color_field_id'],
                    'highlight_red' => true
                ]
            ],
            6 => [
                'name' => 'Отказ в выпуске товаров',
                'description' => 'Тест проверяет обработку статуса "отказ в выпуске товаров". Должна заполниться дата отказа ДТ, сделка должна переместиться на стадию "ПТД/ДТ" и подсветиться красным цветом.',
                'data' => [
                    'vinNum' => 'TEST006',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'отказ в выпуске товаров',
                    'statusDate' => '05.11.2025 15:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'refuse_date', 'color_field_id'],
                    'highlight_red' => true
                ]
            ],
            7 => [
                'name' => 'Отказ в разрешении',
                'description' => 'Тест проверяет обработку статуса "отказ в разрешении". Должна заполниться дата отказа ДТ, сделка должна переместиться на стадию "ПТД/ДТ" и подсветиться красным цветом.',
                'data' => [
                    'vinNum' => 'TEST007',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'отказ в разрешении',
                    'statusDate' => '05.11.2025 16:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'refuse_date', 'color_field_id'],
                    'highlight_red' => true
                ]
            ],
            8 => [
                'name' => 'Выпуск аннулирован при отзыве ПТД',
                'description' => 'Тест проверяет обработку статуса "выпуск товаров аннулирован при отзыве ПТД". Должна заполниться дата отказа ДТ, сделка должна переместиться на стадию "ПТД/ДТ" и подсветиться красным цветом.',
                'data' => [
                    'vinNum' => 'TEST008',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'выпуск товаров аннулирован при отзыве ПТД',
                    'statusDate' => '05.11.2025 17:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'refuse_date', 'color_field_id'],
                    'highlight_red' => true
                ]
            ],
            9 => [
                'name' => 'Формат даты с точкой вместо двоеточия',
                'description' => 'Тест проверяет корректную обработку альтернативного формата даты (dd.mm.yyyy hh.mm вместо dd.mm.yyyy hh:mm).',
                'data' => [
                    'vinNum' => 'TEST009',
                    'pdNum' => '10716050/051125/А000001',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '05.11.2025 18.30'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'Дата должна быть корректно распарсена несмотря на точку вместо двоеточия'
                ]
            ],
            10 => [
                'name' => 'Несуществующий статус (проверка ошибки)',
                'description' => 'Тест проверяет обработку ошибки при передаче несуществующего статуса. Должна вернуться ошибка с сообщением о том, что статус не найден.',
                'data' => [
                    'vinNum' => 'TEST010',
                    'pdNum' => '10716050/051125/А000010',
                    'status' => 'несуществующий статус',
                    'statusDate' => '05.11.2025 19:00'
                ],
                'expected' => [
                    'status' => 'FAILED',
                    'error_message' => 'Статус \'несуществующий статус\' не найден в конфигурации',
                    'note' => 'Этот тест должен завершиться с ошибкой - это ожидаемое поведение'
                ]
            ],
            11 => [
                'name' => 'Регистрация ПТД с другим номером (проверка переноса в историю)',
                'description' => 'Тест проверяет обработку статуса "регистрация ПТД" с номером ДТ отличным от текущего. Текущие данные ДТ должны быть перенесены в историю, поля ДТ обнулены, затем заполнены новыми данными.',
                'data' => [
                    'vinNum' => 'TEST011',
                    'pdNum' => '10716050/051125/А999999',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '05.11.2025 20:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['history', 'nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'moved_to_history' => true,
                    'note' => 'Старые данные ДТ должны быть перенесены в поле "История ДТ", затем заполнены новые данные'
                ]
            ],
            12 => [
                'name' => 'Защита стадии: Регистрация ПТД на ОПЛАЧЕНО',
                'description' => 'Тест проверяет что при статусе "регистрация ПТД" на защищенной стадии ОПЛАЧЕНО данные обновляются, но стадия не меняется.',
                'data' => [
                    'vinNum' => 'TEST012',
                    'pdNum' => '10716050/051125/А000012',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '05.11.2025 10:00'
                ],
                'expected' => [
                    'stage' => null,
                    'stage_changed' => false,
                    'stage_protection_active' => false,
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'Этот тест требует предварительной установки стадии на ОПЛАЧЕНО'
                ]
            ],
            13 => [
                'name' => 'Защита стадии: Выпуск с уплатой на УСПЕШНО РЕАЛИЗОВАНО',
                'description' => 'Тест проверяет что при статусе "выпуск с уплатой" на защищенной стадии УСПЕШНО РЕАЛИЗОВАНО данные обновляются, но стадия не меняется.',
                'data' => [
                    'vinNum' => 'TEST013',
                    'pdNum' => '10716050/051125/А000013',
                    'status' => 'выпуск с уплатой',
                    'statusDate' => '05.11.2025 11:00'
                ],
                'expected' => [
                    'stage' => null,
                    'stage_changed' => false,
                    'stage_protection_active' => false,
                    'fields_updated' => ['nomer_dt', 'status_dt', 'vipusk_date'],
                    'highlight_red' => false,
                    'note' => 'Этот тест требует предварительной установки стадии на УСПЕШНО РЕАЛИЗОВАНО'
                ]
            ],
            14 => [
                'name' => 'Защита стадии: Отказ на ЭПТС',
                'description' => 'Тест проверяет что при статусе отказа на защищенной стадии ЭПТС данные обновляются (включая подсветку красным), но стадия не меняется.',
                'data' => [
                    'vinNum' => 'TEST014',
                    'pdNum' => '10716050/051125/А000014',
                    'status' => 'отказ в выпуске товаров',
                    'statusDate' => '05.11.2025 12:00'
                ],
                'expected' => [
                    'stage' => null,
                    'stage_changed' => false,
                    'stage_protection_active' => true,
                    'fields_updated' => ['nomer_dt', 'status_dt', 'refuse_date', 'color_field_id'],
                    'highlight_red' => true,
                    'note' => 'Этот тест требует предварительной установки стадии на ЭПТС'
                ]
            ],
            15 => [
                'name' => 'Регистрация ПТД с форматом yyyy-mm-dd hh:mm:ss',
                'description' => 'Тест проверяет обработку статуса "регистрация ПТД" с датой в формате "yyyy-mm-dd hh:mm:ss". Должна корректно распарситься дата и обновиться сделка.',
                'data' => [
                    'vinNum' => 'TEST015',
                    'pdNum' => '10716050/051125/А000015',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '2025-11-05 12:00:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'Проверка поддержки формата даты с секундами yyyy-mm-dd hh:mm:ss'
                ]
            ],
            16 => [
                'name' => 'Регистрация ПТД с пограничным временем 10:00 (без смены дня)',
                'description' => 'Тест с временем 10:00 в UTC+10, после коррекции -10 часов должно остаться 00:00 того же дня. Проверка отсутствия сдвига даты.',
                'data' => [
                    'vinNum' => 'TEST016',
                    'pdNum' => '10716050/051125/А000016',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '05.11.2025 10:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'Пограничный случай: час=10, после -10 -> 00:00, дата не меняется (UTC+10 дата остаётся той же)'
                ]
            ],
            17 => [
                'name' => 'Регистрация ПТД с временем до 10:00 (смена дня назад)',
                'description' => 'Тест с временем 09:00 в UTC+10, после коррекции -10 часов уходит на 14:00 предыдущего дня. Проверка сдвига даты назад.',
                'data' => [
                    'vinNum' => 'TEST017',
                    'pdNum' => '10716050/051125/А000017',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '05.11.2025 09:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'Смена дня: UTC+10 09:00 -10 -> UTC 19:00 предыдущего дня (04.11.2025)'
                ]
            ],
            18 => [
                'name' => 'Регистрация ПТД с временем 00:00 (смена дня назад)',
                'description' => 'Тест с временем 00:00 в UTC+10, после коррекции уходит на 14:00 предыдущего дня. Проверка крайнего случая смены дня.',
                'data' => [
                    'vinNum' => 'TEST018',
                    'pdNum' => '10716050/051125/А000018',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '05.11.2025 00:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'Крайний случай: UTC+10 00:00 -10 -> UTC 14:00 предыдущего дня (04.11.2025)'
                ]
            ],
            19 => [
                'name' => 'Регистрация ПТД с временем 15:00 (без смены дня)',
                'description' => 'Тест с временем 15:00 в UTC+10, после коррекции -10 остаётся 05:00 того же дня в UTC. Проверка что дата сохраняется.',
                'data' => [
                    'vinNum' => 'TEST019',
                    'pdNum' => '10716050/051125/А000019',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '05.11.2025 15:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'UTC+10 дата должна оставаться 05.11.2025, несмотря на коррекцию'
                ]
            ],
            20 => [
                'name' => 'Регистрация ПТД с форматом yyyy-mm-dd и временем после 10:00',
                'description' => 'Тест с форматом yyyy-mm-dd hh:mm:ss и временем после 10:00 в UTC+10. Проверяет коррекцию в ISO формате.',
                'data' => [
                    'vinNum' => 'TEST020',
                    'pdNum' => '10716050/051125/А000020',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '2025-11-05 12:30:45'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'Проверка коррекции: 12:30 -10 -> 02:30 в UTC того же дня'
                ]
            ],
            21 => [
                'name' => 'Регистрация ПТД с форматом yyyy-mm-dd и временем до 10:00',
                'description' => 'Тест с форматом yyyy-mm-dd hh:mm:ss и временем до 10:00 в UTC+10. Проверяет сдвиг на предыдущий день.',
                'data' => [
                    'vinNum' => 'TEST021',
                    'pdNum' => '10716050/051125/А000021',
                    'status' => 'регистрация ПТД',
                    'statusDate' => '2025-11-05 17:45:00'
                ],
                'expected' => [
                    'stage' => 'ptd/dt',
                    'fields_updated' => ['nomer_dt', 'status_dt', 'registration_date'],
                    'highlight_red' => false,
                    'note' => 'Проверка коррекции: 08:45 -10 -> 22:45 предыдущего дня (2025-11-04)'
                ]
            ]
        ];
    }

    /**
     * Запустить тестовый сценарий для транзитных ДТ
     * 
     * @param int $testNumber Номер теста (22-28)
     * @return \Illuminate\Http\JsonResponse
     */
    public function runTdStatusTest($testNumber)
    {
        $tests = $this->getTdStatusTests();
        
        if (!isset($tests[$testNumber])) {
            return response()->json([
                'error' => 'Тест не найден',
                'available_tests' => array_keys($tests)
            ], 404, ['Content-Type' => 'application/json; charset=utf-8'], JSON_UNESCAPED_UNICODE);
        }
        
        $test = $tests[$testNumber];
        
        try {
            $testMode = config('amocrm.test_mode', false);
            
            $result = $this->leadService->updateLeadFromTDStatus(
                $test['data']['vinNum'],
                $test['data']['tdNum'],
                $test['data']['status'],
                $test['data']['statusDate'],
                $testMode
            );
            
            return response()->json([
                'test_number' => $testNumber,
                'test_name' => $test['name'],
                'description' => $test['description'],
                'request_data' => $test['data'],
                'expected_result' => $test['expected'],
                'actual_result' => $result,
                'status' => 'SUCCESS',
                'message' => 'Тест выполнен успешно'
            ], 200, ['Content-Type' => 'application/json; charset=utf-8'], JSON_UNESCAPED_UNICODE);
            
        } catch (\Exception $e) {
            return response()->json([
                'test_number' => $testNumber,
                'test_name' => $test['name'],
                'description' => $test['description'],
                'request_data' => $test['data'],
                'expected_result' => $test['expected'],
                'status' => 'FAILED',
                'error' => $e->getMessage()
            ], 500, ['Content-Type' => 'application/json; charset=utf-8'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Получить список всех тестов TD
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function listTdStatusTests()
    {
        $tests = $this->getTdStatusTests();
        
        $testList = [];
        foreach ($tests as $number => $test) {
            $testList[] = [
                'number' => $number,
                'name' => $test['name'],
                'description' => $test['description'],
                'url' => url("/api/test/{$number}")
            ];
        }
        
        return response()->json([
            'total_tests' => count($testList),
            'tests' => $testList
        ], 200, ['Content-Type' => 'application/json; charset=utf-8'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Получить определения всех тестов для транзитных ДТ
     * 
     * @return array
     */
    private function getTdStatusTests()
    {
        return [
            22 => [
                'name' => 'ТД Зарегистрирована - изменение статуса на Транзит',
                'description' => 'Тест проверяет обработку статуса "ТД Зарегистрирована" когда сделка находится в одном из статусов td_statuses_to_change. Должны заполниться поля ТД и статус сделки должен измениться на "Транзит".',
                'data' => [
                    'vinNum' => 'TDTEST022',
                    'tdNum' => '10716050/051125/TD000022',
                    'status' => 'ТД Зарегистрирована',
                    'statusDate' => '05.11.2025 14:00'
                ],
                'expected' => [
                    'stage_changed' => true,
                    'fields_updated' => ['nomer_td', 'status_td', 'registration_date_td'],
                    'new_stage_id' => 83281430,
                    'note' => 'Статус сделки должен измениться на td_transit_status (Транзит)'
                ]
            ],
            23 => [
                'name' => 'ТД Зарегистрирована - UPPERCASE обработка',
                'description' => 'Тест проверяет регистронезависимую обработку статуса в верхнем регистре. Статус "ТД ЗАРЕГИСТРИРОВАНА" должен быть корректно распознан.',
                'data' => [
                    'vinNum' => 'TDTEST023',
                    'tdNum' => '10716050/051125/TD000023',
                    'status' => 'ТД ЗАРЕГИСТРИРОВАНА',
                    'statusDate' => '05.11.2025 15:00'
                ],
                'expected' => [
                    'fields_updated' => ['nomer_td', 'status_td', 'registration_date_td'],
                    'status_recognition' => 'case_insensitive',
                    'note' => 'Статус в UPPERCASE должен быть корректно обработан'
                ]
            ],
            24 => [
                'name' => 'ТД Зарегистрирована - lowercase обработка',
                'description' => 'Тест проверяет регистронезависимую обработку статуса в нижнем регистре. Статус "тд зарегистрирована" должен быть корректно распознан.',
                'data' => [
                    'vinNum' => 'TDTEST024',
                    'tdNum' => '10716050/051125/TD000024',
                    'status' => 'тд зарегистрирована',
                    'statusDate' => '05.11.2025 16:00'
                ],
                'expected' => [
                    'fields_updated' => ['nomer_td', 'status_td', 'registration_date_td'],
                    'status_recognition' => 'case_insensitive',
                    'note' => 'Статус в lowercase должен быть корректно обработан'
                ]
            ],
            25 => [
                'name' => 'ТД - формат даты yyyy-mm-dd hh:mm:ss',
                'description' => 'Тест проверяет корректную обработку ISO формата даты с секундами. Дата должна быть корректно распарсена и сохранена.',
                'data' => [
                    'vinNum' => 'TDTEST025',
                    'tdNum' => '10716050/051125/TD000025',
                    'status' => 'ТД Зарегистрирована',
                    'statusDate' => '2025-11-05 17:30:45'
                ],
                'expected' => [
                    'fields_updated' => ['nomer_td', 'status_td', 'registration_date_td'],
                    'date_format' => 'yyyy-mm-dd hh:mm:ss',
                    'note' => 'Дата в ISO формате с секундами должна корректно парситься'
                ]
            ],
            26 => [
                'name' => 'ТД - формат даты с точкой вместо двоеточия',
                'description' => 'Тест проверяет альтернативный формат даты dd.mm.yyyy hh.mm (точка вместо двоеточия в времени). Должна быть корректная обработка.',
                'data' => [
                    'vinNum' => 'TDTEST026',
                    'tdNum' => '10716050/051125/TD000026',
                    'status' => 'ТД Зарегистрирована',
                    'statusDate' => '05.11.2025 18.45'
                ],
                'expected' => [
                    'fields_updated' => ['nomer_td', 'status_td', 'registration_date_td'],
                    'date_format' => 'dd.mm.yyyy hh.mm',
                    'note' => 'Альтернативный формат даты должен поддерживаться'
                ]
            ],
            27 => [
                'name' => 'ТД - коррекция времени UTC+10 (пограничное время 10:00)',
                'description' => 'Тест проверяет коррекцию времени на -10 часов. Время 10:00 в UTC+10 должно стать 00:00 UTC без смены даты.',
                'data' => [
                    'vinNum' => 'TDTEST027',
                    'tdNum' => '10716050/051125/TD000027',
                    'status' => 'ТД Зарегистрирована',
                    'statusDate' => '05.11.2025 10:00'
                ],
                'expected' => [
                    'fields_updated' => ['nomer_td', 'status_td', 'registration_date_td'],
                    'timezone_correction' => '-10 hours',
                    'note' => 'Пограничное время 10:00: после -10 часов = 00:00, дата не меняется'
                ]
            ],
            28 => [
                'name' => 'ТД - неверный статус (проверка ошибки)',
                'description' => 'Тест проверяет обработку ошибки при передаче неподдерживаемого статуса. Должна вернуться ошибка 500 с сообщением о неподдерживаемом статусе.',
                'data' => [
                    'vinNum' => 'TDTEST028',
                    'tdNum' => '10716050/051125/TD000028',
                    'status' => 'Неизвестный статус ТД',
                    'statusDate' => '05.11.2025 19:00'
                ],
                'expected' => [
                    'status' => 'FAILED',
                    'error_message' => 'Статус \'Неизвестный статус ТД\' не поддерживается. Ожидается: \'ТД Зарегистрирована\'',
                    'note' => 'Этот тест должен завершиться с ошибкой - это ожидаемое поведение'
                ]
            ],
        ];
    }
}
