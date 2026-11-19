<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Http\Middleware\EnsureUserHasUsableSignature;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SignaturePrerequisiteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes that write request data and therefore need a usable signature.
     *
     * @var list<string>
     */
    private const PROTECTED_ROUTES = [
        'request-forms.stock-requisitions.store',
        'request-forms.purchase-orders.store',
        'request-forms.cash-disbursements.store',
        'request-forms.check-issuances.store',
        'request-forms.stock-requisitions.update',
        'request-forms.purchase-orders.update',
        'request-forms.cash-disbursements.update',
        'request-forms.check-issuances.update',
        'approver.request-forms.decision',
    ];

    /**
     * Routes that must stay reachable without a signature.
     *
     * @var list<string>
     */
    private const EXCLUDED_ROUTES = [
        'request-forms.index',
        'request-forms.show',
        'request-forms.print.show',
        'request-forms.print.signatures.show',
        'request-forms.shares.index',
        'request-forms.shares.store',
        'request-forms.cancel',
        'request-forms.destroy',
        'shared-requests.index',
        'shared-requests.show',
        'profile.show',
        'profile.picture.show',
        'profile.picture.update',
        'profile.picture.destroy',
        'profile.signature.show',
        'profile.signature.update',
        'profile.signature.destroy',
    ];

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_signature_middleware_is_applied_to_every_request_write_route(): void
    {
        foreach (self::PROTECTED_ROUTES as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route [{$name}].");

            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, $name);
            $this->assertContains('signature.required', $middleware, $name);
        }
    }

    public function test_signature_middleware_is_not_applied_to_read_or_profile_routes(): void
    {
        foreach (self::EXCLUDED_ROUTES as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route [{$name}].");

            $this->assertNotContains(
                'signature.required',
                $route->gatherMiddleware(),
                $name,
            );
        }

        $logout = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === 'api/v1/logout');

        $this->assertNotNull($logout);
        $this->assertNotContains(
            'signature.required',
            $logout->gatherMiddleware(),
        );
    }

    public function test_middleware_alias_resolves_to_the_guard(): void
    {
        $this->assertSame(
            EnsureUserHasUsableSignature::class,
            app('router')->getMiddleware()['signature.required'] ?? null,
        );
    }

    public function test_create_route_requires_authentication_before_the_signature_check(): void
    {
        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            [],
        )->assertUnauthorized();
    }

    public function test_user_without_a_signature_is_blocked_with_a_stable_code(): void
    {
        Sanctum::actingAs($this->user('User'));

        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            [],
        )
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'Add your signature in your Profile before continuing.',
                'code' => 'signature_required',
            ]);
    }

    public function test_a_database_value_without_a_stored_png_is_not_usable(): void
    {
        $user = $this->user('User', [
            'signature' => 'signatures/missing.png',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/request-forms/stock-requisitions', [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'signature_required');

        Storage::disk('local')->put(
            'signatures/not-an-image.png',
            'not a real png',
        );
        $user->forceFill(['signature' => 'signatures/not-an-image.png'])->save();

        $this->postJson('/api/v1/request-forms/stock-requisitions', [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'signature_required');
    }

    public function test_a_valid_private_png_lets_the_request_reach_validation(): void
    {
        $path = 'signatures/private-signature.png';
        Storage::disk('local')->put(
            $path,
            UploadedFile::fake()->image('signature.png', 300, 120)->getContent(),
        );

        Sanctum::actingAs($this->user('User', ['signature' => $path]));

        $this->postJson('/api/v1/request-forms/stock-requisitions', [])
            ->assertUnprocessable()
            ->assertJsonMissing(['code' => 'signature_required']);
    }

    public function test_a_valid_legacy_public_png_is_still_usable(): void
    {
        $path = 'signatures/legacy-signature.png';
        Storage::disk('public')->put(
            $path,
            UploadedFile::fake()->image('legacy.png', 300, 120)->getContent(),
        );

        Sanctum::actingAs($this->user('User', ['signature' => $path]));

        $this->postJson('/api/v1/request-forms/stock-requisitions', [])
            ->assertUnprocessable()
            ->assertJsonMissing(['code' => 'signature_required']);
    }

    public function test_profile_routes_stay_usable_without_a_signature(): void
    {
        Sanctum::actingAs($this->user('User'));

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonMissing(['code' => 'signature_required']);
    }

    /**
     * Every write route plus both decision actions, described in one place so
     * the behavior below is exercised identically for all of them.
     *
     * @return array<string, array{
     *     method: string,
     *     url: string,
     *     type: RequestFormType|null,
     *     role: string,
     *     payload: array<string, mixed>
     * }>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'stock requisition create' => [
                'method' => 'POST',
                'url' => '/api/v1/request-forms/stock-requisitions',
                'type' => null,
                'role' => 'User',
                'payload' => [],
            ],
            'purchase order create' => [
                'method' => 'POST',
                'url' => '/api/v1/request-forms/purchase-orders',
                'type' => null,
                'role' => 'User',
                'payload' => [],
            ],
            'cash disbursement create' => [
                'method' => 'POST',
                'url' => '/api/v1/request-forms/cash-disbursements',
                'type' => null,
                'role' => 'User',
                'payload' => [],
            ],
            'check issuance create' => [
                'method' => 'POST',
                'url' => '/api/v1/request-forms/check-issuances',
                'type' => null,
                'role' => 'User',
                'payload' => [],
            ],
            'stock requisition update' => [
                'method' => 'PATCH',
                'url' => '/api/v1/request-forms/{requestForm}/stock-requisition',
                'type' => RequestFormType::StockRequisition,
                'role' => 'User',
                'payload' => [],
            ],
            'purchase order update' => [
                'method' => 'PATCH',
                'url' => '/api/v1/request-forms/{requestForm}/purchase-order',
                'type' => RequestFormType::PurchaseOrder,
                'role' => 'User',
                'payload' => [],
            ],
            'cash disbursement update' => [
                'method' => 'PATCH',
                'url' => '/api/v1/request-forms/{requestForm}/cash-disbursement',
                'type' => RequestFormType::CashDisbursement,
                'role' => 'User',
                'payload' => [],
            ],
            'check issuance update' => [
                'method' => 'PATCH',
                'url' => '/api/v1/request-forms/{requestForm}/check-issuance',
                'type' => RequestFormType::CheckIssuance,
                'role' => 'User',
                'payload' => [],
            ],
            'approve decision' => [
                'method' => 'PATCH',
                'url' => '/api/v1/approver/request-forms/{requestForm}/decision',
                'type' => RequestFormType::StockRequisition,
                'role' => 'approver',
                'payload' => ['action' => 'approve'],
            ],
            'disapprove decision' => [
                'method' => 'PATCH',
                'url' => '/api/v1/approver/request-forms/{requestForm}/decision',
                'type' => RequestFormType::StockRequisition,
                'role' => 'approver',
                'payload' => [
                    'action' => 'disapprove',
                    'comment' => 'Incomplete supporting documents.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('protectedEndpoints')]
    public function test_null_signature_is_rejected_on_every_protected_endpoint(
        string $method,
        string $url,
        ?RequestFormType $type,
        string $role,
        array $payload,
    ): void {
        $response = $this->sendProtectedRequest(
            method: $method,
            urlTemplate: $url,
            type: $type,
            role: $role,
            payload: $payload,
            signaturePath: null,
        );

        $response
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'Add your signature in your Profile before continuing.',
                'code' => 'signature_required',
            ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('protectedEndpoints')]
    public function test_missing_signature_file_is_rejected_on_every_protected_endpoint(
        string $method,
        string $url,
        ?RequestFormType $type,
        string $role,
        array $payload,
    ): void {
        $response = $this->sendProtectedRequest(
            method: $method,
            urlTemplate: $url,
            type: $type,
            role: $role,
            payload: $payload,
            signaturePath: 'signatures/missing-signature.png',
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('code', 'signature_required');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('protectedEndpoints')]
    public function test_invalid_or_unsafe_signature_is_rejected_on_every_protected_endpoint(
        string $method,
        string $url,
        ?RequestFormType $type,
        string $role,
        array $payload,
    ): void {
        Storage::disk('local')->put(
            'signatures/invalid-content.png',
            'this is not a real png',
        );

        $invalid = $this->sendProtectedRequest(
            method: $method,
            urlTemplate: $url,
            type: $type,
            role: $role,
            payload: $payload,
            signaturePath: 'signatures/invalid-content.png',
        );

        $invalid
            ->assertStatus(409)
            ->assertJsonPath('code', 'signature_required');

        /*
         * Traversal is rejected by the path shape check before the file is
         * even read, so it must look exactly like a missing signature.
         */
        Storage::disk('local')->put('.env', 'APP_KEY=not-a-real-key');

        $unsafe = $this->sendProtectedRequest(
            method: $method,
            urlTemplate: $url,
            type: $type,
            role: $role,
            payload: $payload,
            signaturePath: 'signatures/../.env',
        );

        $unsafe
            ->assertStatus(409)
            ->assertJsonPath('code', 'signature_required');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('protectedEndpoints')]
    public function test_valid_signature_lets_every_protected_endpoint_continue(
        string $method,
        string $url,
        ?RequestFormType $type,
        string $role,
        array $payload,
    ): void {
        $path = 'signatures/usable-signature.png';
        Storage::disk('local')->put(
            $path,
            UploadedFile::fake()->image('signature.png', 300, 120)->getContent(),
        );

        $response = $this->sendProtectedRequest(
            method: $method,
            urlTemplate: $url,
            type: $type,
            role: $role,
            payload: $payload,
            signaturePath: $path,
        );

        /*
         * The request is allowed past the signature gate and reaches normal
         * validation/authorization, so its status must not be the signature
         * conflict and the body must not carry the stable signature code.
         */
        $this->assertNotSame(409, $response->status());
        $response->assertJsonMissing(['code' => 'signature_required']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendProtectedRequest(
        string $method,
        string $urlTemplate,
        ?RequestFormType $type,
        string $role,
        array $payload,
        ?string $signaturePath,
    ): TestResponse {
        $user = $this->user($role, ['signature' => $signaturePath]);

        Sanctum::actingAs($user);

        $url = $urlTemplate;

        if ($type !== null) {
            $requestForm = $this->requestForm($user, $type);
            $url = str_replace(
                '{requestForm}',
                (string) $requestForm->id,
                $urlTemplate,
            );

            if ($role === 'approver') {
                $this->approvalStep($requestForm, $user);
            }
        }

        return $this->json($method, $url, $payload);
    }

    private function requestForm(User $requester, RequestFormType $type): RequestForm
    {
        $number = ++$this->sequence;

        $branch = Branch::factory()->create([
            'branch_code' => "SIG-GUARD-{$number}",
            'branch_name' => "SIG-GUARD-{$number} Test Branch",
            'branch' => 'Signature Guard Company',
            'acronym' => 'SIG',
        ]);

        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => ['purpose' => 'Signature guard coverage.'],
        ]);
        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            $number,
        );
        $requestForm->form_type = $type;
        $requestForm->status = RequestStatus::Pending;
        $requestForm->grand_total = '100.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function approvalStep(RequestForm $requestForm, User $approver): void
    {
        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = ApprovalStepStatus::Pending;
        $requestForm->approvalSteps()->save($step);
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $role, array $attributes = []): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "SignatureGuard{$number}",
            'lastName' => 'Tester',
            'contact' => "0916000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => "signature-guard-{$number}",
            'employee_id' => "SIGNATURE-GUARD-{$number}",
            'signature' => null,
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
