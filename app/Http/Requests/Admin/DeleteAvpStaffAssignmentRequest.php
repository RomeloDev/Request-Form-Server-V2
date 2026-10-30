<?php

namespace App\Http\Requests\Admin;

use App\Models\AvpStaffAssignment;
use Illuminate\Foundation\Http\FormRequest;

class DeleteAvpStaffAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof AvpStaffAssignment
            && ($this->user()?->can('delete', $assignment) ?? false);
    }

    /**
     * @return array<string, never>
     */
    public function rules(): array
    {
        return [];
    }
}
