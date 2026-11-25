<?php

namespace Tests\Feature;

use App\Models\AccountingHeader;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountingTrashTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Location $location;

    private AccountingHeader $incomeHeader;

    private AccountingHeader $expenseHeader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'slug' => 'accounting-trash-test',
            'plan' => 'enterprise',
        ]);

        app(TenantContext::class)->set($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->location = Location::create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Finance Admin',
            'email' => 'finance@accounting-test.org',
            'location_id' => $this->location->id,
        ]);

        $this->incomeHeader = AccountingHeader::create([
            'name' => 'Catering Income',
            'type' => 'income',
            'is_active' => true,
        ]);

        $this->expenseHeader = AccountingHeader::create([
            'name' => 'Kitchen Utilities',
            'type' => 'expense',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_income_can_be_trashed_and_appears_in_trashed_endpoint(): void
    {
        $income = Income::create([
            'location_id' => $this->location->id,
            'header_id' => $this->incomeHeader->id,
            'category' => 'Catering',
            'amount' => 15000,
            'logged_by' => $this->admin->id,
        ]);

        // Active index returns the income
        $indexRes = $this->getJson('/api/v1/incomes');
        $indexRes->assertOk();
        $this->assertContains($income->id, array_column($indexRes->json('data'), 'id'));

        // Delete (trash) the income
        $deleteRes = $this->deleteJson("/api/v1/incomes/{$income->id}");
        $deleteRes->assertStatus(204);

        // Verify database soft delete
        $fresh = Income::withTrashed()->find($income->id);
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->trashed());
        $this->assertEquals($this->admin->id, $fresh->trashed_by);

        // Active index NO LONGER returns the trashed income
        $indexAfterRes = $this->getJson('/api/v1/incomes');
        $indexAfterRes->assertOk();
        $this->assertNotContains($income->id, array_column($indexAfterRes->json('data'), 'id'));

        // Trashed endpoint DOES return the trashed income
        $trashedRes = $this->getJson('/api/v1/incomes-trashed');
        $trashedRes->assertOk();
        $trashedData = $trashedRes->json('data');
        $this->assertContains($income->id, array_column($trashedData, 'id'));

        // Check trashedByUser relation is loaded
        $trashedItem = collect($trashedData)->firstWhere('id', $income->id);
        $this->assertNotNull($trashedItem['trashed_by_user']);
        $this->assertEquals('Finance Admin', $trashedItem['trashed_by_user']['name']);
    }

    public function test_income_can_be_restored_from_trash(): void
    {
        $income = Income::create([
            'location_id' => $this->location->id,
            'header_id' => $this->incomeHeader->id,
            'category' => 'Hall Rental',
            'amount' => 25000,
            'logged_by' => $this->admin->id,
        ]);

        // Trash it
        $this->deleteJson("/api/v1/incomes/{$income->id}")->assertStatus(204);

        // Restore it
        $restoreRes = $this->postJson("/api/v1/incomes-trashed/{$income->id}/restore");
        $restoreRes->assertOk();
        $this->assertEquals($income->id, $restoreRes->json('id'));

        // Verify database state
        $fresh = Income::find($income->id);
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->trashed());
        $this->assertNull($fresh->trashed_by);

        // Back in active index
        $indexRes = $this->getJson('/api/v1/incomes');
        $this->assertContains($income->id, array_column($indexRes->json('data'), 'id'));

        // No longer in trashed index
        $trashedRes = $this->getJson('/api/v1/incomes-trashed');
        $this->assertNotContains($income->id, array_column($trashedRes->json('data'), 'id'));
    }

    public function test_expense_can_be_trashed_and_appears_in_trashed_endpoint(): void
    {
        $expense = Expense::create([
            'location_id' => $this->location->id,
            'header_id' => $this->expenseHeader->id,
            'category' => 'Electricity Bill',
            'amount' => 8500,
            'logged_by' => $this->admin->id,
        ]);

        // Active index returns the expense
        $indexRes = $this->getJson('/api/v1/expenses');
        $indexRes->assertOk();
        $this->assertContains($expense->id, array_column($indexRes->json('data'), 'id'));

        // Delete (trash) the expense
        $deleteRes = $this->deleteJson("/api/v1/expenses/{$expense->id}");
        $deleteRes->assertStatus(204);

        // Verify database soft delete
        $fresh = Expense::withTrashed()->find($expense->id);
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->trashed());
        $this->assertEquals($this->admin->id, $fresh->trashed_by);

        // Active index NO LONGER returns the trashed expense
        $indexAfterRes = $this->getJson('/api/v1/expenses');
        $indexAfterRes->assertOk();
        $this->assertNotContains($expense->id, array_column($indexAfterRes->json('data'), 'id'));

        // Trashed endpoint DOES return the trashed expense
        $trashedRes = $this->getJson('/api/v1/expenses-trashed');
        $trashedRes->assertOk();
        $trashedData = $trashedRes->json('data');
        $this->assertContains($expense->id, array_column($trashedData, 'id'));

        // Check trashedByUser relation is loaded
        $trashedItem = collect($trashedData)->firstWhere('id', $expense->id);
        $this->assertNotNull($trashedItem['trashed_by_user']);
        $this->assertEquals('Finance Admin', $trashedItem['trashed_by_user']['name']);
    }

    public function test_expense_can_be_restored_from_trash(): void
    {
        $expense = Expense::create([
            'location_id' => $this->location->id,
            'header_id' => $this->expenseHeader->id,
            'category' => 'Repair and Maintenance',
            'amount' => 4500,
            'logged_by' => $this->admin->id,
        ]);

        // Trash it
        $this->deleteJson("/api/v1/expenses/{$expense->id}")->assertStatus(204);

        // Restore it
        $restoreRes = $this->postJson("/api/v1/expenses-trashed/{$expense->id}/restore");
        $restoreRes->assertOk();
        $this->assertEquals($expense->id, $restoreRes->json('id'));

        // Verify database state
        $fresh = Expense::find($expense->id);
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->trashed());
        $this->assertNull($fresh->trashed_by);

        // Back in active index
        $indexRes = $this->getJson('/api/v1/expenses');
        $this->assertContains($expense->id, array_column($indexRes->json('data'), 'id'));

        // No longer in trashed index
        $trashedRes = $this->getJson('/api/v1/expenses-trashed');
        $this->assertNotContains($expense->id, array_column($trashedRes->json('data'), 'id'));
    }

    public function test_trashed_incomes_and_expenses_support_location_filtering(): void
    {
        $branch2 = Location::create([
            'name' => 'Branch 2',
            'slug' => 'branch-2',
            'is_active' => true,
        ]);

        $incBranch1 = Income::create(['location_id' => $this->location->id, 'amount' => 1000]);
        $incBranch2 = Income::create(['location_id' => $branch2->id, 'amount' => 2000]);
        $incBranch1->delete();
        $incBranch2->delete();

        $expBranch1 = Expense::create(['location_id' => $this->location->id, 'amount' => 500]);
        $expBranch2 = Expense::create(['location_id' => $branch2->id, 'amount' => 700]);
        $expBranch1->delete();
        $expBranch2->delete();

        // Query trashed for Branch 1
        $incRes = $this->getJson("/api/v1/incomes-trashed?location_id={$this->location->id}");
        $incIds = array_column($incRes->json('data'), 'id');
        $this->assertContains($incBranch1->id, $incIds);
        $this->assertNotContains($incBranch2->id, $incIds);

        $expRes = $this->getJson("/api/v1/expenses-trashed?location_id={$this->location->id}");
        $expIds = array_column($expRes->json('data'), 'id');
        $this->assertContains($expBranch1->id, $expIds);
        $this->assertNotContains($expBranch2->id, $expIds);
    }
}
