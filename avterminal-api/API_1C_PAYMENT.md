# API: факт полной оплаты из 1С

Endpoint принимает от 1С финальный факт: **все счета по автомобилю оплачены**. Проверка счетов остается на стороне 1С; backend сопоставляет сделку по VIN и переводит ее на этап `Оплачено`.

## Боевой запрос

```http
POST /api/amocrm/integrations/1c/payments/paid
Authorization: Bearer <SANCTUM_TOKEN>
Content-Type: application/json
Accept: application/json
```

```json
{
  "vin": "JTDBR32E720123456"
}
```

`vin` — обязательная непустая строка длиной до 64 символов. Перед поиском пробелы по краям удаляются, символы переводятся в верхний регистр.

## Успешный боевой ответ

Боевой endpoint отвечает в том же формате, что и существующие `dt-status` и `td-status`:

```json
{
  "message": "OK"
}
```

Ответ означает, что факт оплаты принят и обработан. Такой же ответ возвращается при безопасной повторной отправке, если сделка уже находится на этапе `Оплачено`. Поэтому запрос можно повторить, если 1С не получила HTTP-ответ из-за сетевой ошибки.

Если сделка уже закрыта как успешно или неуспешно реализованная, endpoint не откатывает ее назад и также подтверждает прием запроса через HTTP `200` / `message: OK`.

## Тестовый запрос без изменения amoCRM

```http
POST /api/amocrm/integrations/1c-test/payments/paid
Authorization: Bearer <TEST_SANCTUM_TOKEN>
Content-Type: application/json
```

Payload тот же. Endpoint выполняет поиск в amoCRM, но не меняет этап сделки. Для сделки, которую можно перевести, ответ содержит:

```json
{
  "status": "would_mark_paid",
  "vin": "JTDBR32E720123456",
  "dealId": 123456,
  "pipelineId": 7523034,
  "previousStatusId": 64577706,
  "currentStatusId": 64577706,
  "targetStatusId": 64577710,
  "updated": false,
  "environment": "test"
}
```

## Ошибки

| HTTP | Когда возвращается |
|---:|---|
| `401` | Bearer-токен отсутствует или неверен |
| `404` | Сделка с таким VIN не найдена |
| `409` | Найдено несколько сделок с VIN или сделка находится не в целевой воронке |
| `422` | `vin` отсутствует или имеет неверный тип/длину |
| `502` | amoCRM не ответила или не приняла обновление |

Пример ошибки:

```json
{
  "error": "Сделка с VIN JTDBR32E720123456 не найдена"
}
```

## Настройки

```dotenv
ONEC_PAYMENT_PIPELINE_ID=7523034
ONEC_PAID_STATUS_ID=64577710
```

По умолчанию используются основная воронка `7523034` и этап `Оплачено` `64577710`.

## Пример curl

```bash
curl -X POST "https://api.example.ru/api/amocrm/integrations/1c/payments/paid" \
  -H "Authorization: Bearer SANCTUM_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"vin":"JTDBR32E720123456"}'
```
