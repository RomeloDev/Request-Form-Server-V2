<?php

namespace App\Http\Requests\Admin;

use App\Models\BranchHeadAssignment;
use Illuminate\Foundation\Http\FormRequest;

class ShowBranchHeadAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof BranchHeadAssignment && ($this->user()?->can('view', $assignment) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, never>
     */
    public function rules(): array
    {
        return [];
    }
}
