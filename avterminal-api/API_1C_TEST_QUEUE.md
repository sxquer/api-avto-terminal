# Тестовая очередь 1С

## Назначение

Тестовый контур позволяет брать реальные данные из боевой amoCRM, но складывать их в отдельную очередь для тестовой 1С. Callback тестовой 1С обновляет только записи буфера и события синхронизации, без записи `1cId` и комментариев обратно в amoCRM.

## Endpoint постановки в очередь

```http
POST /api/amocrm/deals/contract-ready-test
```

Авторизация такая же, как у боевого webhook, но используется отдельный секрет:

```env
ONEC_AMO_WEBHOOK_TEST_SECRET=
```

Секрет можно передать через `secret`, query-параметр, `X-Amo-Webhook-Secret` или `X-Webhook-Secret`.

## Endpoint тестовой 1С

```http
GET /api/amocrm/integrations/1c-test/contacts/pending?limit=50
POST /api/amocrm/integrations/1c-test/contacts/result
```

Оба endpoint требуют Bearer token Sanctum, как и боевой контур.

## Изоляция от боя

- Боевой контур использует `environment=production`.
- Тестовый контур использует `environment=test`.
- `pending` тестовой 1С выбирает только test-записи.
- `result` тестовой 1С ищет `requestId` только в test-очереди.
- Дедупликация payload работает отдельно для production и test.
- Тестовый callback не обновляет поле `onec_counterparty_id` в amoCRM и не добавляет комментарии в сделку.

## Диагностика

Последние записи можно фильтровать по окружению:

```http
GET /api/amocrm/integrations/1c/debug/statuses?environment=test&limit=10
```

