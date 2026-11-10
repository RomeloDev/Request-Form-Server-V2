<?php

namespace Tests\Feature\RequestForms;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestShareFoundationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_relationships_expose_shared_recipients_and_shared_requests(): void
    {
        $branch = $this->branch('SHARE');
        $requester = $this->user($branch, 'Requester');
        $recipient = $this->user($branch, 'Recipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'SHARE-SRL-0000001',
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        $this->assertTrue(
            $requestForm->sharedRecipients->contains($recipient)
        );
        $this->assertTrue(
            $recipient->sharedRequests->contains($requestForm)
        );
    }

    public function test_a_request_cannot_be_shared_twice_with_the_same_recipient(): void
    {
        $branch = $this->branch('UNIQUE');
        $requester = $this->user($branch, 'Requester');
        $recipient = $this->user($branch, 'Recipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'UNIQUE-SRL-0000001',
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        $this->expectException(QueryException::class);

        $requestForm->sharedRecipients()->attach($recipient->id);
    }

    public function test_permanently_deleting_a_request_removes_its_shares(): void
    {
        $branch = $this->branch('CASCADE-FORM');
        $requester = $this->user($branch, 'Requester');
        $recipient = $this->user($branch, 'Recipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'CASCADE-FORM-SRL-0000001',
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        $requestForm->forceDelete();

        $this->assertDatabaseMissing('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test_deleting_a_recipient_removes_their_shares(): void
    {
        $branch = $this->branch('CASCADE-USER');
        $requester = $this->user($branch, 'Requester');
        $recipient = $this->user($branch, 'Recipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'CASCADE-USER-SRL-0000001',
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        $recipient->delete();

        $this->assertDatabaseMissing('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
    }

    private function branch(string $code): Branch
    {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Test Company',
            'acronym' => $code,
        ]);
    }

    private function user(Branch $branch, string $firstName): User
    {
        $number = ++$this->sequence;

        return User::factory()->create([
            'firstName' => $firstName,
            'lastName' => "Example{$number}",
            'contact' => "0917000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "share-user-{$number}",
            'employee_id' => "SHARE-EMP-{$number}",
        ]);
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        string $requestCode,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing the request sharing foundation.',
            ],
        ]);

        $requestForm->request_code = $requestCode;
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = RequestStatus::Pending;
        $requestForm->grand_total = '100.00';

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
