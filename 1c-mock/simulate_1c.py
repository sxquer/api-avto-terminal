#!/usr/bin/env python3
import argparse
import json
import os
import sys
from datetime import datetime, timezone
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

try:
    import settings as local_settings
except Exception:
    local_settings = None


def build_headers(token: str) -> dict:
    return {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }


def http_get_json(url: str, headers: dict) -> tuple[int, dict]:
    req = Request(url, headers=headers, method="GET")
    try:
        with urlopen(req, timeout=30) as resp:
            body = resp.read().decode("utf-8")
            return resp.status, json.loads(body) if body else {}
    except HTTPError as e:
        body = e.read().decode("utf-8", errors="ignore")
        return e.code, {"error": body}
    except URLError as e:
        return 0, {"error": str(e)}


def http_post_json(url: str, headers: dict, payload: dict) -> tuple[int, dict]:
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = Request(url, data=data, headers=headers, method="POST")
    try:
        with urlopen(req, timeout=30) as resp:
            body = resp.read().decode("utf-8")
            return resp.status, json.loads(body) if body else {}
    except HTTPError as e:
        body = e.read().decode("utf-8", errors="ignore")
        return e.code, {"error": body}
    except URLError as e:
        return 0, {"error": str(e)}


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def print_json(title: str, data: dict) -> None:
    print(f"\n{title}")
    print(json.dumps(data, ensure_ascii=False, indent=2))


def cmd_pull(args: argparse.Namespace) -> int:
    token = args.token
    headers = build_headers(token)
    query = urlencode({"limit": args.limit})
    url = f"{args.base_url.rstrip('/')}/api/amocrm/integrations/1c/contacts/pending?{query}"
    status, data = http_get_json(url, headers)
    print(f"GET {url}")
    print(f"HTTP {status}")
    print_json("Response:", data)
    return 0 if status == 200 else 1


def cmd_result(args: argparse.Namespace) -> int:
    token = args.token
    headers = build_headers(token)
    url = f"{args.base_url.rstrip('/')}/api/amocrm/integrations/1c/contacts/result"

    payload = {
        "requestId": args.request_id,
        "status": args.status,
        "processedAt": args.processed_at or now_iso(),
    }
    if args.vin:
        payload["vin"] = args.vin
    if args.onec_id:
        payload["1cId"] = args.onec_id
    if args.error:
        payload["error"] = args.error

    print(f"POST {url}")
    print_json("Payload:", payload)
    status, data = http_post_json(url, headers, payload)
    print(f"HTTP {status}")
    print_json("Response:", data)
    return 0 if status == 200 else 1


def cmd_flow(args: argparse.Namespace) -> int:
    token = args.token
    headers = build_headers(token)

    pending_url = (
        f"{args.base_url.rstrip('/')}/api/amocrm/integrations/1c/contacts/pending?"
        f"{urlencode({'limit': args.limit})}"
    )
    status, data = http_get_json(pending_url, headers)
    print(f"GET {pending_url}")
    print(f"HTTP {status}")
    print_json("Pending response:", data)
    if status != 200:
        return 1

    items = data.get("items", [])
    if not items:
        print("\nNo pending items.")
        return 0

    result_url = f"{args.base_url.rstrip('/')}/api/amocrm/integrations/1c/contacts/result"
    exit_code = 0

    for idx, item in enumerate(items, start=1):
        request_id = item.get("requestId")
        vin = item.get("vin")
        if not request_id:
            print(f"\n[{idx}] Skip: requestId отсутствует в item")
            exit_code = 1
            continue

        payload = {
            "requestId": request_id,
            "status": args.status,
            "processedAt": now_iso(),
        }
        if vin:
            payload["vin"] = vin

        if args.status in ("created", "found"):
            payload["1cId"] = f"{args.onec_id_prefix}{request_id[-8:]}"
        else:
            payload["error"] = args.error_message

        print(f"\n[{idx}] POST {result_url}")
        print_json("Payload:", payload)

        if args.dry_run:
            print("Dry-run: callback not sent")
            continue

        cb_status, cb_data = http_post_json(result_url, headers, payload)
        print(f"HTTP {cb_status}")
        print_json("Response:", cb_data)
        if cb_status != 200:
            exit_code = 1

    return exit_code


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Эмулятор 1С для теста интеграции контрагентов (pending/result)."
    )
    default_base_url = (
        getattr(local_settings, "BASE_URL", None) if local_settings is not None else None
    ) or "http://api-avto-terminal.ru"
    default_token = (
        os.getenv("ONEC_TEST_TOKEN")
        or (getattr(local_settings, "TOKEN", None) if local_settings is not None else None)
    )
    parser.add_argument(
        "--base-url",
        default=default_base_url,
        help="Базовый URL API (default: http://api-avto-terminal.ru)",
    )
    parser.add_argument(
        "--token",
        default=default_token,
        help="Bearer token (или ONEC_TEST_TOKEN / 1c-mock/settings.py).",
    )

    sub = parser.add_subparsers(dest="command", required=True)

    pull = sub.add_parser("pull", help="Забрать pending-контрагентов (как 1С pull).")
    pull.add_argument("--limit", type=int, default=50, help="Лимит выборки (1..200).")
    pull.set_defaults(func=cmd_pull)

    result_cmd = sub.add_parser("result", help="Отправить callback result по одному requestId.")
    result_cmd.add_argument("--request-id", required=True, help="requestId из pending.")
    result_cmd.add_argument(
        "--status",
        required=True,
        choices=["created", "found", "error"],
        help="Статус обработки в 1С.",
    )
    result_cmd.add_argument("--onec-id", help="1cId (обязательно для created/found).")
    result_cmd.add_argument("--vin", help="VIN (опционально).")
    result_cmd.add_argument("--error", help="Текст ошибки (для status=error).")
    result_cmd.add_argument("--processed-at", help="ISO datetime, например 2026-03-16T14:00:00+10:00")
    result_cmd.set_defaults(func=cmd_result)

    flow = sub.add_parser(
        "flow",
        help="Сценарий end-to-end: pull pending и отправка callback по каждому item.",
    )
    flow.add_argument("--limit", type=int, default=50, help="Лимит выборки pending.")
    flow.add_argument(
        "--status",
        default="created",
        choices=["created", "found", "error"],
        help="Какой статус отправлять в callback.",
    )
    flow.add_argument(
        "--onec-id-prefix",
        default="1c-test-",
        help="Префикс для генерации 1cId при created/found.",
    )
    flow.add_argument(
        "--error-message",
        default="Simulated 1C error",
        help="Текст ошибки для status=error.",
    )
    flow.add_argument(
        "--dry-run",
        action="store_true",
        help="Показать payload callback без отправки.",
    )
    flow.set_defaults(func=cmd_flow)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    if not args.token:
        parser.error("Нужен токен: передайте --token или задайте ONEC_TEST_TOKEN")
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
