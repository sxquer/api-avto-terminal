# AmoCRM Services - Документация

Этот пакет содержит набор сервисов для работы с AmoCRM API. Код был отрефакторен для улучшения читаемости, поддерживаемости и соблюдения принципов SOLID.

## Структура

```
app/Services/AmoCRM/
├── AmoCRMService.php           # Базовый сервис для работы с API клиентом
├── LeadService.php             # Сервис для работы со сделками
├── CustomFieldService.php      # Сервис для работы с кастомными полями
├── XmlGeneratorService.php     # Сервис для генерации XML
└── README.md                   # Эта документация
```

## Сервисы

### 1. AmoCRMService

**Назначение:** Инициализация и управление API клиентом AmoCRM.

**Основные методы:**
- `getClient()` - Получить инициализированный API клиент
- `getFieldsInfo()` - Получить информацию о всех кастомных полях
- `getCustomFieldEnumOptions($fieldId, $entityType)` - Получить опции для полей типа select/multiselect

**Пример использования:**
```php
$amoCRMService = app(AmoCRMService::class);
$client = $amoCRMService->getClient();
$fieldsInfo = $amoCRMService->getFieldsInfo();
```

### 2. LeadService

**Назначение:** Работа со сделками (leads) в AmoCRM.

**Основные методы:**
- `getLeadData($id)` - Получить данные сделки по ID
- `getFormattedLeadAndContactData($id)` - Получить форматированные данные сделки и контакта
- `findLeadByVin($vin)` - Найти сделку по VIN номеру

**Пример использования:**
```php
$leadService = app(LeadService::class);
$lead = $leadService->getLeadData(12345);
$formattedData = $leadService->getFormattedLeadAndContactData(12345);
$leadByVin = $leadService->findLeadByVin('XW8AC2NE0HK010793');
```

**Форматирование полей:**
- Даты преобразуются в формат YYYY-MM-DD
- Серия паспорта форматируется как "XX XX"
- Все значения переводятся в верхний регистр

### 3. CustomFieldService

**Назначение:** Управление кастомными полями лидов.

**Основные методы:**
- `updateLeadCustomFields($leadId, $fieldsToUpdate)` - Обновить кастомные поля лида

**Формат данных для обновления:**
```php
[
    [
        'field_key' => 'color_field_id',  // Ключ из config/amocrm.php
        'value' => 'Синий',               // Значение
        'type' => 'select',               // Тип поля
        'append' => false                 // Дописать к существующему (опционально)
    ]
]
```

**Поддерживаемые типы полей:**
- `text` - Текстовое поле
- `textarea` - Многострочное текстовое поле
- `date` - Дата
- `datetime` - Дата и время
- `select` - Список (одиночный выбор)
- `multiselect` - Список (множественный выбор)

**Пример использования:**
```php
$customFieldService = app(CustomFieldService::class);

$customFieldService->updateLeadCustomFields(25147637, [
    [
        'field_key' => 'history',
        'value' => 'Новая запись в истории',
        'type' => 'textarea',
        'append' => true  // Добавить к существующему тексту
    ],
    [
        'field_key' => 'color_field_id',
        'value' => 'Красный',
        'type' => 'select'
    ],
    [
        'field_key' => 'status_date',
        'value' => '2025-11-04',
        'type' => 'date'
    ]
]);
```

**Сброс значения поля:**

Чтобы очистить значение поля в AmoCRM, передайте `null` в качестве значения:
```php
$customFieldService->updateLeadCustomFields(25147637, [
    [
        'field_key' => 'color_field_id',
        'value' => null,  // Сброс значения - поле будет очищено
        'type' => 'select'
    ]
]);
```

Это работает для всех типов полей:
```php
$customFieldService->updateLeadCustomFields(25147637, [
    [
        'field_key' => 'color_field_id',
        'value' => null,
        'type' => 'select'
    ],
    [
        'field_key' => 'history',
        'value' => null,
        'type' => 'textarea'
    ],
    [
        'field_key' => 'status_date',
        'value' => null,
        'type' => 'date'
    ]
]);
```

**Важно:** При передаче `null` для поля, если это поле уже существует в сделке, оно будет очищено (значение будет удалено). Если поле не существует, ничего не произойдет.

### 4. XmlGeneratorService

**Назначение:** Генерация XML файлов.

**Основные методы:**
- `arrayToXml($data, $xml)` - Конвертировать массив в XML
- `generatePassengerDeclarationXml($leadData, $leadId)` - Генерировать XML декларацию пассажира

**Пример использования:**
```php
$xmlService = app(XmlGeneratorService::class);

// Генерация декларации пассажира
$leadData = $leadService->getFormattedLeadAndContactData(12345);
$xml = $xmlService->generatePassengerDeclarationXml($leadData, 12345);

// Конвертация массива в XML
$data = ['key' => 'value'];
$xml = new \SimpleXMLElement('<root/>');
$xmlService->arrayToXml($data, $xml);
```

## Контроллер AmoCRMController

После рефакторинга контроллер стал значительно проще и использует внедрение зависимостей через конструктор:

```php
class AmoCRMController extends Controller
{
    public function __construct(
        private AmoCRMService $amoCRMService,
        private LeadService $leadService,
        private CustomFieldService $customFieldService,
        private XmlGeneratorService $xmlGeneratorService
    ) {}
    
    // Методы контроллера...
}
```

## Преимущества новой архитектуры

1. **Разделение ответственности** - Каждый сервис отвечает за конкретную область функциональности
2. **Переиспользуемость** - Сервисы можно использовать в разных частях приложения
3. **Тестируемость** - Каждый сервис можно легко протестировать отдельно
4. **Читаемость** - Код стал более понятным и структурированным
5. **Поддерживаемость** - Легче вносить изменения и добавлять новую функциональность

## Dependency Injection

Laravel автоматически разрешает зависимости сервисов через Service Container. Все сервисы доступны через:

```php
// В контроллере через конструктор
public function __construct(private LeadService $leadService) {}

// В любом месте приложения через app()
$leadService = app(LeadService::class);

// Или через фасад App
$leadService = App::make(LeadService::class);
```

## Конфигурация

Все настройки AmoCRM находятся в `config/amocrm.php`:

```php
return [
    'client_id' => env('AMOCRM_CLIENT_ID'),
    'client_secret' => env('AMOCRM_CLIENT_SECRET'),
    'subdomain' => env('AMOCRM_SUBDOMAIN'),
    'long_lived_token' => env('AMOCRM_LONG_LIVED_TOKEN'),
    
    'fields' => [
        'history' => [
            'id' => 123456,
            'type' => 'textarea'
        ],
        'color_field_id' => [
            'id' => 789012,
            'type' => 'select',
            'values' => [
                'Красный' => 1,
                'Синий' => 2,
                'Зелёный' => 3
            ]
        ]
    ]
];
```

## Миграция со старого кода

Если у вас есть код, использующий старый контроллер напрямую, вам нужно будет обновить его:

**Было:**
```php
$controller = new AmoCRMController();
$lead = $controller->getLeadData($request, 12345);
```

**Стало:**
```php
$leadService = app(LeadService::class);
$lead = $leadService->getLeadData(12345);
```

## Дальнейшие улучшения

Возможные направления для дальнейшего развития:

1. Добавить интерфейсы для сервисов
2. Создать репозитории для работы с данными
3. Добавить кэширование запросов к API
4. Реализовать очереди для асинхронной обработки
5. Добавить логирование операций
6. Создать Events и Listeners для отслеживания изменений
