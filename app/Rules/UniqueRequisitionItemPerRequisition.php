<?php

namespace App\Rules;

use App\Models\RequisitionItem;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueRequisitionItemPerRequisition implements DataAwareRule, ValidationRule
{
    public function __construct(
        protected int $requisitionId
    ) {}

    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $itemId = is_numeric($value) ? (int) $value : $value;
        if (! $itemId) {
            return;
        }

        $query = RequisitionItem::query()
            ->where('requisition_id', $this->requisitionId)
            ->where('item_id', $itemId);

        if (isset($this->data['id'])) {
            $query->where('id', '!=', $this->data['id']);
        }

        if ($query->exists()) {
            $fail('This item has already been added to this requisition.');
        }
    }
}
