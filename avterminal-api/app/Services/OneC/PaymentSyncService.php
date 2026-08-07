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
        if ($pipelineId !== $paymentPipelineId) {
            throw new PaymentConflictException(
                "Сделка {$leadId} с VIN {$vin} находится в воронке {$pipelineId}; "
                ."обработка оплаты разрешена только для воронки {$paymentPipelineId}"
            );
        }

        $desiredPaid = $paymentStatus === self::STATUS_PAID;
        $desiredColor = $desiredPaid ? 'Зеленый' : 'Красный';
        $fieldsToUpdate = $this->buildChangedFields($lead, $desiredPaid, $desiredColor);

        if ($dryRun) {
            return $this->result(
                'would_sync_'.$paymentStatus,
                $vin,
                $leadId,
                $pipelineId,
                $previousStatusId,
                $paymentStatus,
                array_column($fieldsToUpdate, 'field_key'),
                false
            );
        }

        if ($fieldsToUpdate !== []) {
            $this->customFieldService->updateLeadCustomFields($leadId, $fieldsToUpdate);
        }

        $operationStatus = $fieldsToUpdate === [] ? 'already_synced' : $paymentStatus;

        Log::info('1C payment: статус счетов синхронизирован', [
            'vin' => $vin,
            'payment_status' => $paymentStatus,
            'lead_id' => $leadId,
            'pipeline_id' => $pipelineId,
            'previous_status_id' => $previousStatusId,
            'current_status_id' => $previousStatusId,
            'updated_fields' => array_column($fieldsToUpdate, 'field_key'),
            'stage_changed' => false,
        ]);

        return $this->result(
            $operationStatus,
            $vin,
            $leadId,
            $pipelineId,
            $previousStatusId,
            $paymentStatus,
            array_column($fieldsToUpdate, 'field_key'),
            $fieldsToUpdate !== []
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
        string $paymentStatus,
        array $updatedFields,
        bool $updated
    ): array {
        return [
            'status' => $status,
            'paymentStatus' => $paymentStatus,
            'vin' => $vin,
            'dealId' => $leadId,
            'pipelineId' => $pipelineId,
            'previousStatusId' => $previousStatusId,
            'currentStatusId' => $previousStatusId,
            'targetStatusId' => null,
            'updatedFields' => $updatedFields,
            'stageChanged' => false,
            'updated' => $updated,
        ];
    }
}
