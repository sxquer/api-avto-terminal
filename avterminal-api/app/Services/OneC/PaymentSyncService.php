<?php

namespace App\Services\OneC;

use AmoCRM\Models\LeadModel;
use App\Exceptions\AmoCRM\MultipleLeadsFoundForVinException;
use App\Exceptions\OneC\PaymentConflictException;
use App\Exceptions\OneC\PaymentLeadNotFoundException;
use App\Services\AmoCRM\CustomFieldService;
use App\Services\AmoCRM\LeadService;
use Illuminate\Support\Facades\Log;

class PaymentSyncService
{
    public const STATUS_PAID = 'paid';

    public const STATUS_UNPAID = 'unpaid';

    private const AMO_SUCCESS_STATUS_ID = 142;

    private const AMO_LOST_STATUS_ID = 143;

    public function __construct(
        private LeadService $leadService,
        private CustomFieldService $customFieldService
    ) {}

    /**
     * Синхронизировать агрегированный статус счетов из 1С с основной воронкой.
     */
    public function syncPaymentStatusByVin(string $vin, string $paymentStatus, bool $dryRun = false): array
    {
        $vin = mb_strtoupper(trim($vin), 'UTF-8');

        if (! in_array($paymentStatus, [self::STATUS_PAID, self::STATUS_UNPAID], true)) {
            throw new \InvalidArgumentException("Неподдерживаемый статус оплаты: {$paymentStatus}");
        }

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
                ."обработка оплаты разрешена только для воронки {$paymentPipelineId}"
            );
        }

        $desiredPaid = $paymentStatus === self::STATUS_PAID;
        $desiredColor = $desiredPaid ? 'Зеленый' : 'Красный';
        $fieldsToUpdate = $this->buildChangedFields($lead, $desiredPaid, $desiredColor);
        $isTerminalStage = in_array(
            $previousStatusId,
            [self::AMO_SUCCESS_STATUS_ID, self::AMO_LOST_STATUS_ID],
            true
        );
        $shouldChangeStage = $desiredPaid
            && ! $isTerminalStage
            && $previousStatusId !== $paidStatusId;

        if ($dryRun) {
            return $this->result(
                'would_sync_'.$paymentStatus,
                $vin,
                $leadId,
                $pipelineId,
                $previousStatusId,
                $paidStatusId,
                $paymentStatus,
                array_column($fieldsToUpdate, 'field_key'),
                $shouldChangeStage,
                false
            );
        }

        if ($fieldsToUpdate !== []) {
            $this->customFieldService->updateLeadCustomFields($leadId, $fieldsToUpdate);
        }

        if ($shouldChangeStage) {
            $this->leadService->updateLeadStatusById($leadId, $paidStatusId);
        }

        $operationStatus = match (true) {
            $isTerminalStage && $desiredPaid => 'ignored_terminal_stage',
            $fieldsToUpdate === [] && ! $shouldChangeStage => 'already_synced',
            default => $paymentStatus,
        };

        Log::info('1C payment: статус счетов синхронизирован', [
            'vin' => $vin,
            'payment_status' => $paymentStatus,
            'lead_id' => $leadId,
            'pipeline_id' => $pipelineId,
            'previous_status_id' => $previousStatusId,
            'current_status_id' => $shouldChangeStage ? $paidStatusId : $previousStatusId,
            'updated_fields' => array_column($fieldsToUpdate, 'field_key'),
            'stage_changed' => $shouldChangeStage,
            'terminal_stage_preserved' => $isTerminalStage,
        ]);

        return $this->result(
            $operationStatus,
            $vin,
            $leadId,
            $pipelineId,
            $previousStatusId,
            $paidStatusId,
            $paymentStatus,
            array_column($fieldsToUpdate, 'field_key'),
            $shouldChangeStage,
            $fieldsToUpdate !== [] || $shouldChangeStage
        );
    }

    private function buildChangedFields(LeadModel $lead, bool $desiredPaid, string $desiredColor): array
    {
        $fields = [];
        $issuedFieldId = (int) config('amocrm.fields.uss_invoice_issued.id');
        $paidFieldId = (int) config('amocrm.fields.uss_invoice_paid.id');
        $colorFieldId = (int) config('amocrm.fields.color_field_id.id');

        if (! $this->checkboxValue($lead, $issuedFieldId)) {
            $fields[] = ['field_key' => 'uss_invoice_issued', 'value' => true, 'type' => 'checkbox'];
        }

        if ($this->checkboxValue($lead, $paidFieldId) !== $desiredPaid) {
            $fields[] = ['field_key' => 'uss_invoice_paid', 'value' => $desiredPaid, 'type' => 'checkbox'];
        }

        if ($this->fieldValue($lead, $colorFieldId) !== $desiredColor) {
            $fields[] = ['field_key' => 'color_field_id', 'value' => $desiredColor, 'type' => 'select'];
        }

        return $fields;
    }

    private function checkboxValue(LeadModel $lead, int $fieldId): bool
    {
        return (bool) ($this->fieldValue($lead, $fieldId) ?? false);
    }

    private function fieldValue(LeadModel $lead, int $fieldId): mixed
    {
        $field = $lead->getCustomFieldsValues()?->getBy('fieldId', $fieldId);

        return $field?->getValues()?->first()?->getValue();
    }

    private function result(
        string $status,
        string $vin,
        int $leadId,
        int $pipelineId,
        int $previousStatusId,
        int $paidStatusId,
        string $paymentStatus,
        array $updatedFields,
        bool $stageChanged,
        bool $updated
    ): array {
        return [
            'status' => $status,
            'paymentStatus' => $paymentStatus,
            'vin' => $vin,
            'dealId' => $leadId,
            'pipelineId' => $pipelineId,
            'previousStatusId' => $previousStatusId,
            'currentStatusId' => $stageChanged ? $paidStatusId : $previousStatusId,
            'targetStatusId' => $paymentStatus === self::STATUS_PAID ? $paidStatusId : null,
            'updatedFields' => $updatedFields,
            'stageChanged' => $stageChanged,
            'updated' => $updated,
        ];
    }
}
