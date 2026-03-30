<?php

namespace App\Http\Controllers;

use App\Services\OneC\CounterpartyFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OneCIntegrationController extends Controller
{
    private const DEFAULT_CONTRACT_PIPELINE_ID = 7523034;
    private const DEFAULT_CONTRACT_STATUS_ID = 62360726;

    public function __construct(
        private CounterpartyFlowService $flowService
    ) {}

    /**
     * Триггер постановки сделки в буфер контрагентов для 1С.
     */
    public function contractReady(Request $request): JsonResponse
    {
        try {
            if ($authError = $this->authorizeAmoWebhook($request)) {
                return $authError;
            }

            $payload = $this->resolveIncomingPayload($request);
            $context = $this->extractContractReadyContext($payload);

            if (!$context['dealId']) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => [
                        'dealId' => ['dealId не найден в payload'],
                    ],
                ], 422);
            }

            if (!$this->isContractStageEvent($context)) {
                return response()->json([
                    'status' => 'ignored',
                    'reason' => 'event is not for contract stage',
                    'dealId' => $context['dealId'],
                    'source' => $context['source'],
                ], 200);
            }

            $result = $this->flowService->enqueueFromLead((int) $context['dealId'], $payload);
            $result['source'] = $context['source'];

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function authorizeAmoWebhook(Request $request): ?JsonResponse
    {
        $expectedSecret = trim((string) config('amocrm.onec.webhook_secret', ''));

        if ($expectedSecret === '') {
            return response()->json([
                'error' => 'Webhook secret is not configured',
            ], 500);
        }

        $providedSecret = trim((string) (
            $request->input('secret')
            ?? $request->query('secret')
            ?? $request->header('X-Amo-Webhook-Secret')
            ?? $request->header('X-Webhook-Secret')
            ?? ''
        ));

        if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            return response()->json([
                'error' => 'Invalid webhook secret',
            ], 403);
        }

        return null;
    }

    /**
     * Pull endpoint: 1С забирает pending-пакеты.
     */
    public function pendingContacts(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'sometimes|integer|min:1|max:200',
            ]);

            $items = $this->flowService->getPending((int) ($validated['limit'] ?? 50));

            return response()->json([
                'count' => count($items),
                'items' => $items,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Callback endpoint: 1С сообщает итог обработки контрагента.
     */
    public function contactsResult(Request $request): JsonResponse
    {
        try {
            // Поддерживаем legacy-ключи, но внутри нормализуем в 1cId.
            if (!$request->has('1cId')) {
                if ($request->has('1cID')) {
                    $request->merge([
                        '1cId' => $request->input('1cID'),
                    ]);
                } elseif ($request->has('1cid')) {
                    $request->merge([
                        '1cId' => $request->input('1cid'),
                    ]);
                }
            }

            $validated = $request->validate([
                'requestId' => 'required|string',
                'vin' => 'nullable|string',
                '1cId' => 'nullable|string',
                '1cID' => 'nullable|string',
                '1cid' => 'nullable|string',
                'status' => 'required|string|in:created,found,error',
                'processedAt' => 'nullable|string',
                'error' => 'nullable|string',
            ]);

            unset($validated['1cID']);
            unset($validated['1cid']);
            $result = $this->flowService->processResult($validated);

            return response()->json($result, 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Debug endpoint: последние записи очереди синхронизации 1С.
     * Без авторизации, как /amocrm/logs.
     */
    public function debugLatestStatuses(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'sometimes|integer|min:1|max:50',
            ]);

            $limit = (int) ($validated['limit'] ?? 5);
            $items = $this->flowService->getLatestBufferStatuses($limit);

            return response()->json([
                'count' => count($items),
                'items' => $items,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Debug endpoint: эмулирует отдачу в 1С для конкретного requestId.
     * Если запись pending, переводит в pulled и возвращает payload, как в pending pull.
     * Без авторизации, как /amocrm/logs.
     */
    public function debugPullByRequestId(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'requestId' => 'required|string',
            ]);

            $result = $this->flowService->debugPullByRequestId($validated['requestId']);

            return response()->json($result, 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveIncomingPayload(Request $request): array
    {
        $payload = $request->all();
        if (!empty($payload)) {
            return $payload;
        }

        $rawBody = trim((string) $request->getContent());
        if ($rawBody === '') {
            return [];
        }

        $parsed = [];
        parse_str($rawBody, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Поддерживаем:
     * 1) JSON: {"dealId": 123}
     * 2) amoCRM status webhook: leads[status][0][id]
     * 3) amoCRM button payload: lead[id]
     */
    private function extractContractReadyContext(array $payload): array
    {
        $dealId = null;
        $pipelineId = null;
        $statusId = null;
        $source = 'unknown';

        if (isset($payload['dealId'])) {
            $dealId = (int) $payload['dealId'];
            $source = 'json_deal_id';
        }

        if (isset($payload['lead']['id'])) {
            $dealId = (int) $payload['lead']['id'];
            $pipelineId = isset($payload['lead']['pipeline_id']) ? (int) $payload['lead']['pipeline_id'] : null;
            $statusId = isset($payload['lead']['status_id']) ? (int) $payload['lead']['status_id'] : null;
            $source = 'amo_button';
        }

        if (isset($payload['leads']['status'][0]['id'])) {
            $dealId = (int) $payload['leads']['status'][0]['id'];
            $pipelineId = isset($payload['leads']['status'][0]['pipeline_id']) ? (int) $payload['leads']['status'][0]['pipeline_id'] : null;
            $statusId = isset($payload['leads']['status'][0]['status_id']) ? (int) $payload['leads']['status'][0]['status_id'] : null;
            $source = 'amo_status_webhook';
        }

        return [
            'dealId' => $dealId,
            'pipelineId' => $pipelineId,
            'statusId' => $statusId,
            'source' => $source,
        ];
    }

    private function isContractStageEvent(array $context): bool
    {
        $expectedPipeline = (int) config('amocrm.onec.contract_pipeline_id', self::DEFAULT_CONTRACT_PIPELINE_ID);
        $expectedStatus = (int) config('amocrm.onec.contract_status_id', self::DEFAULT_CONTRACT_STATUS_ID);

        // Если pipeline/status в событии не пришли, не блокируем обработку.
        if (empty($context['pipelineId']) || empty($context['statusId'])) {
            return true;
        }

        return ((int) $context['pipelineId'] === $expectedPipeline)
            && ((int) $context['statusId'] === $expectedStatus);
    }
}
