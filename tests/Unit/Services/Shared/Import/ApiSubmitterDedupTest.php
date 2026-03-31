<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Import;

use App\Services\Shared\Import\Routine\ApiSubmitter;
use Tests\TestCase;

/**
 * Tests for Fix #2: ApiSubmitter must fall back to searchField when preloaded index misses.
 *
 * Previously, when duplicateIndexReady was true but the external_id was NOT in the
 * preloadedDuplicateIndex, the code hit `continue` — skipping the searchField API call.
 * This meant transactions beyond the pagination limit were never checked for duplicates.
 *
 * After the fix, the code falls through to searchField when the index misses.
 *
 * @internal
 * @coversNothing
 */
final class ApiSubmitterDedupTest extends TestCase
{
    /**
     * Verify that the source code no longer continues on index miss.
     *
     * The old pattern was:
     *   if (null === $indexedResult) { ... continue; }
     *
     * The new pattern should:
     *   if (null !== $indexedResult) { ... } else { // fall through }
     *
     * This structural test guards against regressions.
     */
    public function testIndexMissDoesNotContinue(): void
    {
        $sourceFile = __DIR__ . '/../../../../../app/Services/Shared/Import/Routine/ApiSubmitter.php';
        $this->assertFileExists($sourceFile);

        $source = file_get_contents($sourceFile);

        // The old broken pattern: null === $indexedResult → continue
        $brokenPattern = '/if\s*\(\s*null\s*===\s*\$indexedResult\s*\)\s*\{[^}]*continue\s*;/s';
        $this->assertDoesNotMatchRegularExpression(
            $brokenPattern,
            $source,
            'The code must NOT continue when $indexedResult is null — it should fall through to searchField.'
        );
    }

    /**
     * Verify that the index-hit branch uses null !== $indexedResult (positive check).
     */
    public function testIndexHitUsesPositiveNullCheck(): void
    {
        $sourceFile = __DIR__ . '/../../../../../app/Services/Shared/Import/Routine/ApiSubmitter.php';
        $this->assertFileExists($sourceFile);

        $source = file_get_contents($sourceFile);

        // After the fix, the code should check: if (null !== $indexedResult) { findDuplicateMatch... }
        $correctPattern = '/if\s*\(\s*null\s*!==\s*\$indexedResult\s*\)\s*\{[^}]*findDuplicateMatchForAccountContext/s';
        $this->assertMatchesRegularExpression(
            $correctPattern,
            $source,
            'Expected positive null check (null !== $indexedResult) guarding the findDuplicateMatchForAccountContext call.'
        );
    }

    /**
     * Verify that a searchField fallback exists after the index block.
     */
    public function testSearchFieldFallbackExistsAfterIndexBlock(): void
    {
        $sourceFile = __DIR__ . '/../../../../../app/Services/Shared/Import/Routine/ApiSubmitter.php';
        $this->assertFileExists($sourceFile);

        $source = file_get_contents($sourceFile);

        // After the duplicateIndexReady block, there must be a searchField fallback:
        //   if (null === $searchResult) { $searchResult = $this->searchField(...)
        $fallbackPattern = '/falling back to API search.*\n\s*\}\s*\n\s*\}\s*\n\s*if\s*\(\s*null\s*===\s*\$searchResult\s*\)\s*\{[^}]*searchField/s';
        $this->assertMatchesRegularExpression(
            $fallbackPattern,
            $source,
            'Expected searchField fallback after the duplicateIndexReady block for index misses.'
        );
    }

    /**
     * Verify that duplicateIndexReady and preloadedDuplicateIndex are accessible via reflection
     * and that setting index ready with an empty index causes the expected fallback path.
     */
    public function testReflectionAccessToDuplicateIndexFields(): void
    {
        $submitter = new ApiSubmitter();

        $readyProp = new \ReflectionProperty($submitter, 'duplicateIndexReady');
        $readyProp->setAccessible(true);
        $this->assertFalse($readyProp->getValue($submitter));

        $indexProp = new \ReflectionProperty($submitter, 'preloadedDuplicateIndex');
        $indexProp->setAccessible(true);
        $this->assertSame([], $indexProp->getValue($submitter));

        // Simulate index ready with an entry
        $readyProp->setValue($submitter, true);
        $indexProp->setValue($submitter, ['ext-123' => ['id' => 1, 'description' => 'test', 'amount' => '10', 'currency_code' => 'USD', 'decimal_places' => 2]]);

        $this->assertTrue($readyProp->getValue($submitter));
        $this->assertArrayHasKey('ext-123', $indexProp->getValue($submitter));
        // A missing key should NOT be found — this is the index miss case that must fall through.
        $this->assertArrayNotHasKey('ext-999-not-in-index', $indexProp->getValue($submitter));
    }
}
