<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TBank\Request;

use App\Services\TBank\Request\GetAccountsRequest;
use App\Services\TBank\Request\GetTransactionsRequest;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fix #78: Verify that recursive walkers (collectTransactionRows / collectRows)
 * do not emit both a parent node and its matching children.
 *
 * @internal
 */
final class RecursiveWalkerTest extends TestCase
{
    private ReflectionMethod $collectTransactionRows;
    private GetTransactionsRequest $txRequest;

    private ReflectionMethod $collectAccountRows;
    private GetAccountsRequest $acctRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->txRequest              = new GetTransactionsRequest('fake-session', 'fake-cookie', 'fake-account');
        $this->collectTransactionRows = new ReflectionMethod(GetTransactionsRequest::class, 'collectTransactionRows');

        $this->acctRequest           = new GetAccountsRequest('fake-session', 'fake-cookie');
        $this->collectAccountRows    = new ReflectionMethod(GetAccountsRequest::class, 'collectRows');
    }

    /**
     * Helper to invoke collectTransactionRows with $rows passed by reference.
     */
    private function invokeCollectTransactionRows(mixed $node, array &$rows, int $depth): void
    {
        $args = [$node, &$rows, $depth];
        $this->collectTransactionRows->invokeArgs($this->txRequest, $args);
    }

    /**
     * Helper to invoke collectRows (accounts) with $rows passed by reference.
     */
    private function invokeCollectAccountRows(mixed $node, array &$rows, int $depth): void
    {
        $args = [$node, &$rows, $depth];
        $this->collectAccountRows->invokeArgs($this->acctRequest, $args);
    }

    /**
     * When a parent node is a transaction candidate AND contains child candidates,
     * only the children should be collected (parent skipped).
     */
    public function testTransactionWalkerSkipsParentWhenChildrenMatch(): void
    {
        $child1 = ['id' => 'tx-1', 'amount' => 100.0, 'date' => '2025-01-01'];
        $child2 = ['id' => 'tx-2', 'amount' => -50.0, 'date' => '2025-01-02'];

        // Parent wraps children in a sub-array and also looks like a candidate itself
        $parent = [
            'id'           => 'parent-group',
            'amount'       => 50.0,
            'date'         => '2025-01-01',
            'transactions' => [$child1, $child2],
        ];

        $rows = [];
        $this->invokeCollectTransactionRows($parent, $rows, 0);

        // Should find the 2 children but NOT the parent
        $ids = array_column($rows, 'id');
        $this->assertContains('tx-1', $ids, 'Child 1 should be collected');
        $this->assertContains('tx-2', $ids, 'Child 2 should be collected');
        $this->assertNotContains('parent-group', $ids, 'Parent should be skipped when children match');
        $this->assertCount(2, $rows, 'Exactly 2 rows expected (children only)');
    }

    /**
     * When a node is a candidate but has no matching children, it should still be collected.
     */
    public function testTransactionWalkerCollectsLeafCandidate(): void
    {
        $leaf = ['id' => 'tx-solo', 'amount' => 200.0, 'date' => '2025-03-01'];

        $rows = [];
        $this->invokeCollectTransactionRows($leaf, $rows, 0);

        $this->assertCount(1, $rows);
        $this->assertSame('tx-solo', $rows[0]['id']);
    }

    /**
     * When a parent node is an account candidate AND contains child candidates,
     * only the children should be collected.
     */
    public function testAccountWalkerSkipsParentWhenChildrenMatch(): void
    {
        $childAccount = [
            'id'       => 'acct-child',
            'name'     => 'Savings',
            'currency' => 'RUB',
            'status'   => 'active',
        ];

        $parentAccount = [
            'id'       => 'acct-parent',
            'name'     => 'Group Account',
            'currency' => 'RUB',
            'status'   => 'active',
            'accounts' => [$childAccount],
        ];

        $rows = [];
        $this->invokeCollectAccountRows($parentAccount, $rows, 0);

        $ids = array_column($rows, 'id');
        $this->assertContains('acct-child', $ids, 'Child account should be collected');
        $this->assertNotContains('acct-parent', $ids, 'Parent account should be skipped when child matches');
        $this->assertCount(1, $rows);
    }

    /**
     * Account leaf nodes without matching children should still be collected.
     */
    public function testAccountWalkerCollectsLeafCandidate(): void
    {
        $leaf = [
            'id'       => 'acct-leaf',
            'name'     => 'Checking',
            'currency' => 'RUB',
        ];

        $rows = [];
        $this->invokeCollectAccountRows($leaf, $rows, 0);

        $this->assertCount(1, $rows);
        $this->assertSame('acct-leaf', $rows[0]['id']);
    }

    /**
     * Flat list nodes should not be treated as candidates (array_is_list guard).
     */
    public function testTransactionWalkerSkipsListNodes(): void
    {
        $list = [
            ['id' => 'tx-a', 'amount' => 10.0, 'date' => '2025-01-01'],
            ['id' => 'tx-b', 'amount' => 20.0, 'date' => '2025-01-02'],
        ];

        $rows = [];
        $this->invokeCollectTransactionRows($list, $rows, 0);

        // The list itself is not a candidate; its items are.
        $ids = array_column($rows, 'id');
        $this->assertContains('tx-a', $ids);
        $this->assertContains('tx-b', $ids);
        $this->assertCount(2, $rows);
    }
}
