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
        return $this->handlePaid($request, false);
    }

    /**
     * Безопасная проверка тестового контура: находит сделку, но не меняет ее этап.
     */
    public function paidTest(Request $request): JsonResponse
    {
        return $this->handlePaid($request, true);
    }

    private function handlePaid(Request $request, bool $dryRun): JsonResponse
    {
        try {
            $validated = $request->validate([
                'vin' => 'required|string|max:64',
            ]);

            $result = $this->paymentSyncService->markPaidByVin($validated['vin'], $dryRun);

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
            Log::error('1C payment: ошибка обработки факта оплаты', [
                'vin' => $request->input('vin'),
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Не удалось обновить сделку в amoCRM',
            ], 502);
        }
    }
}
