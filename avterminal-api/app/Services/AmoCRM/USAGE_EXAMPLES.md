# Примеры использования сервисов AmoCRM

## LeadService

### Обновление статуса сделки

#### Пример 1: Базовое использование

```php
use App\Services\AmoCRM\LeadService;

// Получаем сервис через DI
$leadService = app(LeadService::class);

$leadId = 12345;

// Меняем статус на "ПТД/ДТ"
$updatedLead = $leadService->updateLeadStatus($leadId, 'ptd/dt');

// Меняем статус на "Выпуск"
$updatedLead = $leadService->updateLeadStatus($leadId, 'vipusk');

// Меняем статус на "СВХ"
$updatedLead = $leadService->updateLeadStatus($leadId, 'svh');
```

#### Пример 2: Использование в контроллере

```php
namespace App\Http\Controllers;

use App\Services\AmoCRM\LeadService;
use Illuminate\Http\Request;

class LeadStatusController extends Controller
{
    public function __construct(
        private LeadService $leadService
    ) {}
    
    public function updateStatus(Request $request, int $leadId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:ptd/dt,vipusk,svh'
        ]);
        
        try {
            $updatedLead = $this->leadService->updateLeadStatus(
                $leadId,
                $validated['status']
            );
            
            return response()->json([
                'success' => true,
                'lead_id' => $updatedLead->getId(),
                'status_id' => $updatedLead->getStatusId()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
```

#### Пример 3: Изменение статуса после обновления полей

```php
use App\Services\AmoCRM\LeadService;
use App\Services\AmoCRM\CustomFieldService;

$leadService = app(LeadService::class);
$customFieldService = app(CustomFieldService::class);

$leadId = 12345;

// Сначала обновляем поля декларации
$fieldsToUpdate = [
    [
        'field_key' => 'nomer_dt',
        'value' => '10511010/260525/5067807',
        'type' => 'text'
    ],
    [
        'field_key' => 'status_dt',
        'value' => 'выпуск без уплаты (10)',
        'type' => 'select'
    ]
];

$customFieldService->updateLeadCustomFields(
    $leadId,
    $fieldsToUpdate,
    moveToHistory: true
);

// Затем меняем статус сделки на "Выпуск"
$leadService->updateLeadStatus($leadId, 'vipusk');
```

#### Доступные статусы

Статусы настраиваются в `config/amocrm.php`:

| Ключ | ID статуса | Описание |
|------|-----------|----------|
| `ptd/dt` | 62360974 | ПТД/ДТ |
| `vipusk` | 81192786 | Выпуск |
| `svh` | 64976646 | СВХ |

#### Обработка ошибок

```php
try {
    $updatedLead = $leadService->updateLeadStatus($leadId, 'invalid_status');
} catch (\Exception $e) {
    // Выбросится исключение: "Статус invalid_status не найден в конфигурации"
    echo $e->getMessage();
}
```

## CustomFieldService

## Обновление полей с переносом в историю

### Пример 1: Базовое использование флага moveToHistory

```php
use App\Services\AmoCRM\CustomFieldService;

// Получаем сервис через DI
$customFieldService = app(CustomFieldService::class);

$leadId = 12345;

// Новые значения для полей ДТ
$fieldsToUpdate = [
    [
        'field_key' => 'nomer_dt',
        'value' => '10511010/260525/5067807',
        'type' => 'text'
    ],
    [
        'field_key' => 'status_dt',
        'value' => 'выпуск без уплаты (10)',
        'type' => 'select'
    ],
    [
        'field_key' => 'registration_date',
        'value' => '2024-03-02',
        'type' => 'date'
    ]
];

// Обновляем с переносом в историю
$updatedLead = $customFieldService->updateLeadCustomFields(
    $leadId, 
    $fieldsToUpdate,
    moveToHistory: true  // Флаг переноса в историю
);
```

**Что происходит:**
1. Текущие значения полей `nomer_dt`, `status_dt`, `registration_date`, `vipusk_date`, `refuse_date` переносятся в поле `history` с форматированием:
```
# Декларация: 10511010/250425/5067806
# Статус ДТ: регистрация ПТД
# Дата регистрации ДТ: 01.03.2024
# Дата выпуска ДТ: -
# Дата отказа ДТ: -
```

2. Поля `nomer_dt`, `status_dt`, `registration_date`, `vipusk_date`, `refuse_date` обнуляются

3. Устанавливаются новые значения из массива `$fieldsToUpdate`

### Пример 2: Без переноса в историю

```php
// Обычное обновление без переноса в историю
$fieldsToUpdate = [
    [
        'field_key' => 'nomer_dt',
        'value' => '10511010/260525/5067807',
        'type' => 'text'
    ]
];

$updatedLead = $customFieldService->updateLeadCustomFields(
    $leadId, 
    $fieldsToUpdate,
    moveToHistory: false  // Или можно опустить, по умолчанию false
);
```

**Что происходит:**
- Просто обновляется поле `nomer_dt` новым значением
- История не изменяется
- Другие поля остаются без изменений

### Пример 3: Использование в контроллере

```php
namespace App\Http\Controllers;

use App\Services\AmoCRM\CustomFieldService;
use Illuminate\Http\Request;

class DeclarationController extends Controller
{
    public function __construct(
        private CustomFieldService $customFieldService
    ) {}
    
    public function updateDeclaration(Request $request, int $leadId)
    {
        $validated = $request->validate([
            'nomer_dt' => 'required|string',
            'status_dt' => 'required|string',
            'registration_date' => 'required|date',
            'vipusk_date' => 'nullable|date',
            'refuse_date' => 'nullable|date',
            'move_to_history' => 'boolean'
        ]);
        
        $fieldsToUpdate = [
            [
                'field_key' => 'nomer_dt',
                'value' => $validated['nomer_dt'],
                'type' => 'text'
            ],
            [
                'field_key' => 'status_dt',
                'value' => $validated['status_dt'],
                'type' => 'select'
            ],
            [
                'field_key' => 'registration_date',
                'value' => $validated['registration_date'],
                'type' => 'date'
            ],
        ];
        
        if (!empty($validated['vipusk_date'])) {
            $fieldsToUpdate[] = [
                'field_key' => 'vipusk_date',
                'value' => $validated['vipusk_date'],
                'type' => 'date'
            ];
        }
        
        if (!empty($validated['refuse_date'])) {
            $fieldsToUpdate[] = [
                'field_key' => 'refuse_date',
                'value' => $validated['refuse_date'],
                'type' => 'date'
            ];
        }
        
        try {
            $updatedLead = $this->customFieldService->updateLeadCustomFields(
                $leadId,
                $fieldsToUpdate,
                moveToHistory: $validated['move_to_history'] ?? false
            );
            
            return response()->json([
                'success' => true,
                'lead_id' => $updatedLead->getId()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
```

### Пример 4: Множественное обновление с историей

```php
// Если у вас несколько деклараций подряд
$declarations = [
    [
        'nomer_dt' => '10511010/260525/5067807',
        'status_dt' => 'выпуск без уплаты (10)',
        'registration_date' => '2024-03-02'
    ],
    [
        'nomer_dt' => '10511010/260525/5067808',
        'status_dt' => 'выпуск с уплатой (32)',
        'registration_date' => '2024-03-15'
    ]
];

foreach ($declarations as $declaration) {
    $fieldsToUpdate = [
        ['field_key' => 'nomer_dt', 'value' => $declaration['nomer_dt'], 'type' => 'text'],
        ['field_key' => 'status_dt', 'value' => $declaration['status_dt'], 'type' => 'select'],
        ['field_key' => 'registration_date', 'value' => $declaration['registration_date'], 'type' => 'date']
    ];
    
    $updatedLead = $customFieldService->updateLeadCustomFields(
        $leadId,
        $fieldsToUpdate,
        moveToHistory: true
    );
    
    // Небольшая задержка между запросами, чтобы не перегружать API
    sleep(1);
}
```

## Формат истории

При использовании `moveToHistory: true`, в поле `history` добавляется запись в таком формате:

```
# Декларация: 10511010/260525/5067807
# Статус ДТ: отказ в разрешении (40)
# Дата регистрации ДТ: 02.03.2024
# Дата выпуска ДТ: 05.03.2024
# Дата отказа ДТ: 07.03.2024
```

Если в истории уже есть записи, новая запись добавляется через двойной перенос строки (`\n\n`).

## Обработка пустых значений

- Если поле пустое (`null`), в истории будет `-`
- Даты форматируются как `dd.mm.yyyy`
- Для select-полей используется текстовое значение из конфига

## Важные замечания

1. **Порядок обновлений**: При `moveToHistory: true` сначала записывается история, обнуляются старые поля, и только потом применяются новые значения из `$fieldsToUpdate`

2. **Производительность**: При использовании `moveToHistory: true` выполняется дополнительный запрос к API AmoCRM для получения текущих значений

3. **Конфигурация**: Убедитесь, что все необходимые поля настроены в `config/amocrm.php`:
   - `nomer_dt`
   - `status_dt` (с values для маппинга)
   - `registration_date`
   - `vipusk_date`
   - `refuse_date`
   - `history`

4. **Обратная совместимость**: Параметр `moveToHistory` необязательный и по умолчанию `false`, поэтому существующий код продолжит работать без изменений
