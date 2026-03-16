# 1C mock tests

Скрипт для эмуляции поведения 1С в контуре интеграции контрагентов:
- забрать `pending` (pull),
- отправить `result` callback,
- прогнать полный flow.

## Файл

- `simulate_1c.py`

## Быстрый старт

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/avterminal
python3 1c-mock/simulate_1c.py --token "<ВАШ_TOKEN>" pull --limit 50
```

Или через env:

```bash
export ONEC_TEST_TOKEN="<ВАШ_TOKEN>"
python3 1c-mock/simulate_1c.py pull --limit 50
```

## Команды

### 1) Pull pending (эмуляция чтения 1С)

```bash
python3 1c-mock/simulate_1c.py --token "<ВАШ_TOKEN>" pull --limit 50
```

### 2) Callback result по одному requestId

Успех:

```bash
python3 1c-mock/simulate_1c.py --token "<ВАШ_TOKEN>" result \
  --request-id "req_xxxxx" \
  --status created \
  --onec-id "1c-ctr-0001"
```

Ошибка:

```bash
python3 1c-mock/simulate_1c.py --token "<ВАШ_TOKEN>" result \
  --request-id "req_xxxxx" \
  --status error \
  --error "ИНН не прошел проверку"
```

### 3) Full flow (pull + callback по всем item)

Успешный flow:

```bash
python3 1c-mock/simulate_1c.py --token "<ВАШ_TOKEN>" flow --limit 50 --status created
```

Dry-run (без отправки callback):

```bash
python3 1c-mock/simulate_1c.py --token "<ВАШ_TOKEN>" flow --dry-run --status created
```

Flow с ошибками:

```bash
python3 1c-mock/simulate_1c.py --token "<ВАШ_TOKEN>" flow --status error --error-message "Simulated 1C error"
```

## Параметры

- `--base-url` (опц.): по умолчанию `http://api-avto-terminal.ru`
- `--token` или `ONEC_TEST_TOKEN`: Bearer token

