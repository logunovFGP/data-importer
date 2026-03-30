<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BasisBank\Auth;

use App\Services\BasisBank\Auth\BasisBankFormParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BasisBankFormParser — checkbox detection (fix #2) and
 * alias removal verification (fix #57).
 */
class BasisBankFormParserTest extends TestCase
{
    private BasisBankFormParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BasisBankFormParser();
    }

    // ------------------------------------------------------------------
    // Fix #2: Checkbox detection — only checked checkboxes should appear
    // ------------------------------------------------------------------

    public function testCheckedCheckboxIsIncluded(): void
    {
        $html = <<<'HTML'
<html><body>
<form>
    <input type="hidden" name="__VIEWSTATE" value="abc" />
    <input type="text" name="ctl00$ContentPlaceHolder1$UTXT" value="" />
    <input type="password" name="ctl00$ContentPlaceHolder1$PTXT" value="" />
    <input type="checkbox" name="rememberMe" value="on" checked="checked" />
</form>
</body></html>
HTML;

        $fields = $this->parser->getFormFieldsFromLoginPage($html);

        $this->assertArrayHasKey('rememberMe', $fields);
        $this->assertSame('on', $fields['rememberMe']);
    }

    public function testUncheckedCheckboxIsExcluded(): void
    {
        $html = <<<'HTML'
<html><body>
<form>
    <input type="hidden" name="__VIEWSTATE" value="abc" />
    <input type="text" name="ctl00$ContentPlaceHolder1$UTXT" value="" />
    <input type="password" name="ctl00$ContentPlaceHolder1$PTXT" value="" />
    <input type="checkbox" name="rememberMe" value="on" />
</form>
</body></html>
HTML;

        $fields = $this->parser->getFormFieldsFromLoginPage($html);

        $this->assertArrayNotHasKey('rememberMe', $fields);
    }

    public function testCheckedCheckboxWithBareAttribute(): void
    {
        // HTML allows bare `checked` attribute without value (e.g., <input checked />).
        // DOMDocument normalizes this to checked="" which hasAttribute() still detects.
        $html = <<<'HTML'
<html><body>
<form>
    <input type="hidden" name="__VIEWSTATE" value="abc" />
    <input type="text" name="ctl00$ContentPlaceHolder1$UTXT" value="" />
    <input type="password" name="ctl00$ContentPlaceHolder1$PTXT" value="" />
    <input type="checkbox" name="acceptTerms" value="yes" checked />
</form>
</body></html>
HTML;

        $fields = $this->parser->getFormFieldsFromLoginPage($html);

        $this->assertArrayHasKey('acceptTerms', $fields);
        $this->assertSame('yes', $fields['acceptTerms']);
    }

    public function testMixedCheckedAndUncheckedCheckboxes(): void
    {
        $html = <<<'HTML'
<html><body>
<form>
    <input type="hidden" name="__VIEWSTATE" value="abc" />
    <input type="text" name="ctl00$ContentPlaceHolder1$UTXT" value="" />
    <input type="password" name="ctl00$ContentPlaceHolder1$PTXT" value="" />
    <input type="checkbox" name="opt1" value="a" checked="checked" />
    <input type="checkbox" name="opt2" value="b" />
    <input type="checkbox" name="opt3" value="c" checked="checked" />
</form>
</body></html>
HTML;

        $fields = $this->parser->getFormFieldsFromLoginPage($html);

        $this->assertArrayHasKey('opt1', $fields, 'Checked checkbox opt1 must be included');
        $this->assertArrayNotHasKey('opt2', $fields, 'Unchecked checkbox opt2 must be excluded');
        $this->assertArrayHasKey('opt3', $fields, 'Checked checkbox opt3 must be included');
    }

    public function testRadioButtonCheckedIsIncluded(): void
    {
        $html = <<<'HTML'
<html><body>
<form>
    <input type="hidden" name="__VIEWSTATE" value="abc" />
    <input type="text" name="ctl00$ContentPlaceHolder1$UTXT" value="" />
    <input type="password" name="ctl00$ContentPlaceHolder1$PTXT" value="" />
    <input type="radio" name="choice" value="optionA" />
    <input type="radio" name="choice" value="optionB" checked="checked" />
</form>
</body></html>
HTML;

        $fields = $this->parser->getFormFieldsFromLoginPage($html);

        $this->assertArrayHasKey('choice', $fields);
        $this->assertSame('optionB', $fields['choice']);
    }

    // ------------------------------------------------------------------
    // Fix #57: Alias removal — extractFormFieldsFromLoginPage is removed
    // ------------------------------------------------------------------

    public function testAliasMethodDoesNotExist(): void
    {
        $this->assertFalse(
            method_exists($this->parser, 'extractFormFieldsFromLoginPage'),
            'extractFormFieldsFromLoginPage() alias must be removed (fix #57)'
        );
    }

    public function testGetFormFieldsFromLoginPageReturnsFields(): void
    {
        $html = <<<'HTML'
<html><body>
<form>
    <input type="hidden" name="__VIEWSTATE" value="viewstate123" />
    <input type="text" name="ctl00$ContentPlaceHolder1$UTXT" value="" />
    <input type="password" name="ctl00$ContentPlaceHolder1$PTXT" value="" />
</form>
</body></html>
HTML;

        $fields = $this->parser->getFormFieldsFromLoginPage($html);

        $this->assertArrayHasKey('__VIEWSTATE', $fields);
        $this->assertArrayHasKey('ctl00$ContentPlaceHolder1$UTXT', $fields);
        $this->assertArrayHasKey('ctl00$ContentPlaceHolder1$PTXT', $fields);
    }

    public function testGetFormFieldsFromLoginPageReturnsEmptyForNoForm(): void
    {
        $html = '<html><body><p>No form here</p></body></html>';
        $fields = $this->parser->getFormFieldsFromLoginPage($html);

        $this->assertSame([], $fields);
    }
}
