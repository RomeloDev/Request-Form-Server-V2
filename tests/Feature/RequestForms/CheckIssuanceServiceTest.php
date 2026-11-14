<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\RequestItem;
use App\Models\User;
use App\Services\CheckIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

class CheckIssuanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_creation_rolls_back_bank_graph_and_files_when_later_work_fails(): void
    {
        Storage::fake('local');

        $branch = $this->branch('CREATE-ROLLBACK');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $sameFile = UploadedFile::fake()->create(
            'duplicate.pdf',
            100,
            'application/pdf',
        );
        $payload = $this->createPayload($approver);
        $payload['attachments'] = [$sameFile, $sameFile];

        $thrown = null;

        try {
            $this->service()->create($requester, $payload);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull(
            $thrown,
            'Expected the Check Issuance transaction to fail.',
        );
        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
        $this->assertDatabaseCount('request_approval_steps', 0);
        $this->assertDatabaseCount('request_attachments', 0);
        $this->assertDatabaseCount('banks', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_update_failure_restores_the_old_graph_bank_and_file(): void
    {
        Storage::fake('local');

        $branch = $this->branch('UPDATE-ROLLBACK');
        $owner = $this->user($branch);
        $oldApprover = $this->user($branch, 'approver');
        $newApprover = $this->user($branch, 'approver');
        $requestForm = $this->checkIssuance($owner, $branch);
        $oldItem = $this->item($requestForm, 'Original item');
        $oldStep = $this->approvalStep($requestForm, $oldApprover);
        $oldAttachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/old.pdf",
            'original_name' => 'old.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($oldAttachment->path, 'old file');
        Bank::query()->create(['name' => 'Original Bank']);

        $sameFile = UploadedFile::fake()->create(
            'duplicate.pdf',
            100,
            'application/pdf',
        );
        $payload = $this->updatePayload($requestForm, $newApprover);
        $payload['removed_attachment_ids'] = [$oldAttachment->id];
        $payload['attachments'] = [$sameFile, $sameFile];

        $thrown = null;

        try {
            $this->service()->update($requestForm, $owner, $payload);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull(
            $thrown,
            'Expected the Check Issuance update to fail.',
        );
        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'grand_total' => '250.00',
            'status' => RequestStatus::Pending->value,
        ]);
        $this->assertSame(
            [
                'payee' => 'Original Payee',
                'bank' => 'Original Bank',
                'account_no' => '00112233',
                'swift_code' => 'ORIGPHMM',
            ],
            $requestForm->fresh()->form_data,
        );
        $this->assertDatabaseHas('request_items', [
            'id' => $oldItem->id,
            'description' => 'Original item',
        ]);
        $this->assertDatabaseHas('request_approval_steps', [
            'id' => $oldStep->id,
            'approver_id' => $oldApprover->id,
        ]);
        $this->assertDatabaseHas('request_attachments', [
            'id' => $oldAttachment->id,
        ]);
        $this->assertDatabaseMissing('request_approval_steps', [
            'request_form_id' => $requestForm->id,
            'approver_id' => $newApprover->id,
        ]);
        $this->assertDatabaseHas('banks', ['name' => 'Original Bank']);
        $this->assertDatabaseMissing('banks', ['name' => 'Updated Bank']);
        $this->assertSame(
            [$oldAttachment->path],
            Storage::disk('local')->allFiles(),
        );
        Storage::disk('local')->assertExists($oldAttachment->path);
    }

    private function service(): CheckIssuanceService
    {
        return app(CheckIssuanceService::class);
    }

    /** @return array<string, mixed> */
    private function createPayload(User $approver): array
    {
        return [
            'kind_of_request' => 'Office Supplies',
            'form_data' => [
                'payee' => 'Example Payee',
                'bank' => 'Example Bank',
                'account_no' => '00123456789',
                'swift_code' => 'EXAMPHMM',
            ],
            'noted_by_ids' => [],
            'approved_by_ids' => [$approver->id],
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'First payment item',
                    'unit_cost' => '100.20',
                    'remarks' => null,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(
        RequestForm $requestForm,
        User $approvedBy,
    ): array {
        return [
            'updated_at' => $requestForm->fresh()->updated_at?->toISOString(),
            'kind_of_request' => 'Professional Fee',
            'form_data' => [
                'payee' => 'Updated Payee',
                'bank' => 'Updated Bank',
                'account_no' => '00999999',
                'swift_code' => null,
            ],
            'noted_by_ids' => [],
            'approved_by_ids' => [$approvedBy->id],
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'Updated service',
                    'unit_cost' => '150.00',
                    'remarks' => null,
                ],
            ],
        ];
    }

    private function branch(string $code): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "{$code}-{$number}",
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Test Company',
            'acronym' => 'TEST',
        ]);
    }

    private function user(Branch $branch, ?string $role = 'User'): User
    {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'firstName' => "CheckService{$number}",
            'lastName' => 'User',
            'contact' => "0914000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "check-service-{$number}",
            'employee_id' => "CHECK-SERVICE-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function checkIssuance(
        User $requester,
        Branch $branch,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'form_data' => [
                'payee' => 'Original Payee',
                'bank' => 'Original Bank',
                'account_no' => '00112233',
                'swift_code' => 'ORIGPHMM',
            ],
        ]);
        $requestForm->request_code = sprintf(
            '%s-CIRS-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::CheckIssuance;
        $requestForm->status = RequestStatus::Pending;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function item(
        RequestForm $requestForm,
        string $description,
    ): RequestItem {
        $item = new RequestItem([
            'quantity' => 2,
            'description' => $description,
            'unit_cost' => '125.00',
            'remarks' => null,
        ]);
        $item->line_number = 1;
        $item->line_total = '250.00';
        $requestForm->items()->save($item);

        return $item;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = ApprovalStepStatus::Pending;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }
}
