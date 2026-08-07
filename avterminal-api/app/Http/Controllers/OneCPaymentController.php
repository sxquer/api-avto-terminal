<?php

namespace App\Http\Controllers;

use App\Exceptions\OneC\PaymentConflictException;
use App\Exceptions\OneC\PaymentLeadNotFoundException;
use App\Services\OneC\CounterpartyFlowService;
use App\Services\OneC\PaymentSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OneCPaymentController extends Controller
{
    public function __construct(
        private PaymentSyncService $paymentSyncService
    ) {}

    public function paid(Request $request): JsonResponse
    {
        return $this->handlePaymentStatus($request, false, true);
    }

    /**
     * Основной endpoint синхронизации статуса оплаты из 1С.
     */
    public function status(Request $request): JsonResponse
    {
        return $this->handlePaymentStatus($request, false, false);
    }

    /**
     * Безопасная проверка тестового контура: находит сделку, но не меняет карточку.
     */
    public function paidTest(Request $request): JsonResponse
    {
        return $this->handlePaymentStatus($request, true, true);
    }

    private function handlePaymentStatus(Request $request, bool $dryRun, bool $legacyPaidEndpoint): JsonResponse
    {
        try {
            // Старый /payments/paid принимал только VIN. Сохраняем совместимость:
            // отсутствие status на legacy URL означает paid.
            if ($legacyPaidEndpoint && ! $request->has('status')) {
                $request->merge(['status' => PaymentSyncService::STATUS_PAID]);
            }

            $validated = $request->validate([
                'vin' => 'required|string|max:64',
                'status' => 'required|string|in:paid,unpaid',
            ]);

            $result = $this->paymentSyncService->syncPaymentStatusByVin(
                $validated['vin'],
                $validated['status'],
                $dryRun
            );

            if ($dryRun) {
                $result['environment'] = CounterpartyFlowService::ENV_TEST;

                return response()->json($result, 200);
            }

            // Сохраняем формат боевого ответа таким же, как у dt-status и td-status.
            return response()->json(['message' => 'OK'], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        } catch (PaymentLeadNotFoundException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 404);
        } catch (PaymentConflictException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 409);
        } catch (\Throwable $e) {
            Log::error('1C payment: ошибка обработки статуса оплаты', [
                'vin' => $request->input('vin'),
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Не удалось обновить сделку в amoCRM',
            ], 502);
        }
    }
}
