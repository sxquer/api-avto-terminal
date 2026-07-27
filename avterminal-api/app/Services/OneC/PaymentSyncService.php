<?php

namespace App\Services\OneC;

use App\Exceptions\AmoCRM\MultipleLeadsFoundForVinException;
use App\Exceptions\OneC\PaymentConflictException;
use App\Exceptions\OneC\PaymentLeadNotFoundException;
use App\Services\AmoCRM\LeadService;
use Illuminate\Support\Facades\Log;

class PaymentSyncService
{
    private const AMO_SUCCESS_STATUS_ID = 142;

    private const AMO_LOST_STATUS_ID = 143;

    public function __construct(
        private LeadService $leadService
    ) {}

    /**
     * Обработать финальный факт оплаты от 1С.
     *
     * 1С вызывает этот сценарий только после оплаты всех счетов по VIN.
     * Повторный вызов безопасен: уже оплаченную сделку повторно не обновляем.
     */
    public function markPaidByVin(string $vin, bool $dryRun = false): array
    {
        $vin = mb_strtoupper(trim($vin), 'UTF-8');
        try {
            $lead = $this->leadService->findLeadByVin($vin);
        } catch (MultipleLeadsFoundForVinException $e) {
            throw new PaymentConflictException($e->getMessage(), previous: $e);
        }

        if (! $lead) {
            throw new PaymentLeadNotFoundException("Сделка с VIN {$vin} не найдена");
        }

        $leadId = (int) $lead->getId();
        $pipelineId = (int) $lead->getPipelineId();
        $previousStatusId = (int) $lead->getStatusId();
        $paymentPipelineId = (int) config('amocrm.onec.payment_pipeline_id', 7523034);
        $paidStatusId = (int) config('amocrm.onec.paid_status_id', 64577710);

        if ($pipelineId !== $paymentPipelineId) {
            throw new PaymentConflictException(
                "Сделка {$leadId} с VIN {$vin} находится в воронке {$pipelineId}; "
                ."этап «Оплачено» настроен для воронки {$paymentPipelineId}"
            );
        }

        if ($previousStatusId === $paidStatusId) {
            return $this->result(
                'already_paid',
                $vin,
                $leadId,
                $pipelineId,
                $previousStatusId,
                $paidStatusId,
                false
            );
        }

        // Поздний или повторно доставленный webhook не должен переоткрывать закрытую сделку.
        if (in_array($previousStatusId, [self::AMO_SUCCESS_STATUS_ID, self::AMO_LOST_STATUS_ID], true)) {
            return $this->result(
                'ignored_terminal_stage',
                $vin,
                $leadId,
                $pipelineId,
                $previousStatusId,
                $paidStatusId,
                false
            );
        }

        if ($dryRun) {
            return $this->result(
                'would_mark_paid',
                $vin,
                $leadId,
                $pipelineId,
                $previousStatusId,
                $paidStatusId,
                false
            );
        }

        $this->leadService->updateLeadStatusById($leadId, $paidStatusId);

        Log::info('1C payment: сделка переведена на этап «Оплачено»', [
            'vin' => $vin,
            'lead_id' => $leadId,
            'pipeline_id' => $pipelineId,
            'previous_status_id' => $previousStatusId,
            'paid_status_id' => $paidStatusId,
        ]);

        return $this->result(
            'paid',
            $vin,
            $leadId,
            $pipelineId,
            $previousStatusId,
            $paidStatusId,
            true
        );
    }

    private function result(
        string $status,
        string $vin,
        int $leadId,
        int $pipelineId,
        int $previousStatusId,
        int $paidStatusId,
        bool $updated
    ): array {
        return [
            'status' => $status,
            'vin' => $vin,
            'dealId' => $leadId,
            'pipelineId' => $pipelineId,
            'previousStatusId' => $previousStatusId,
            'currentStatusId' => $updated ? $paidStatusId : $previousStatusId,
            'targetStatusId' => $paidStatusId,
            'updated' => $updated,
        ];
    }
}
