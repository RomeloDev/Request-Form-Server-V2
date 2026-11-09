<?php

namespace App\Http\Resources;

use App\Enums\ApprovalStepStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignedRequestResource extends JsonResource
{
    /**
     * Transform an assigned approval step into a safe list response.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $requestForm = $this->requestForm;

        return [
            'id' => $requestForm->id,
            'request_code' => $requestForm->request_code,
            'form_type' => $requestForm->form_type->value,
            'kind_of_request' => $requestForm->kind_of_request,
            'status' => $requestForm->status->value,
            'grand_total' => $requestForm->grand_total,

            'requester' => [
                'id' => $requestForm->requester->id,
                'firstname' => $requestForm->requester->firstName,
                'lastname' => $requestForm->requester->lastName,
                'position' => $requestForm->requester->position,
            ],

            'branch' => [
                'id' => $requestForm->branch->id,
                'branch_code' => $requestForm->branch->branch_code,
                'branch_name' => $requestForm->branch->branch_name,
                'branch' => $requestForm->branch->branch,
                'acronym' => $requestForm->branch->acronym,
            ],

            'my_step' => [
                'id' => $this->id,
                'level' => $this->level,
                'step_type' => $this->step_type->value,
                'status' => $this->status->value,
                'comment' => $this->comment,
                'acted_at' => $this->acted_at?->toISOString(),

                /*
                 * The upcoming controller query will only include a Pending
                 * step when it is actually this approver's turn.
                 */
                'can_process' => $this->status ===
                    ApprovalStepStatus::Pending,
            ],

            'created_at' => $requestForm->created_at?->toISOString(),
            'updated_at' => $requestForm->updated_at?->toISOString(),
        ];
    }
}
