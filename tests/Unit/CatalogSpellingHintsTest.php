<?php

namespace Tests\Unit;

use App\Support\CatalogSpellingHints;
use Tests\TestCase;

class CatalogSpellingHintsTest extends TestCase
{
    public function test_prefix_and_one_letter_misspellings_suggest_the_saved_spelling(): void
    {
        $this->assertSame(['Gallon'], CatalogSpellingHints::closest('gal', ['Gallon', 'Long']));
        $this->assertSame(['Gallon'], CatalogSpellingHints::closest('galon', ['Gallon', 'Long']));
        $this->assertSame(['Gallon'], CatalogSpellingHints::closest('gallons', ['Gallon']));
    }

    public function test_a_closer_prefix_wins_over_a_longer_one(): void
    {
        $this->assertSame(['Galon'], CatalogSpellingHints::closest('gal', ['Gallon', 'Galon']));
    }

    public function test_exact_spelling_and_different_words_have_no_hint(): void
    {
        $this->assertSame([], CatalogSpellingHints::closest('gallon', ['Gallon']));
        $this->assertSame([], CatalogSpellingHints::closest('Long', ['Letter', 'A4']));
        $this->assertSame([], CatalogSpellingHints::closest('A5', ['A4']));
        $this->assertSame([], CatalogSpellingHints::closest('', ['Gallon']));
    }

    public function test_two_equally_close_spellings_are_both_offered(): void
    {
        $this->assertSame(
            ['Black', 'Blank'],
            CatalogSpellingHints::closest('blak', ['Blank', 'Black', 'Blue']),
        );
    }

    public function test_sentence_escapes_the_saved_spelling(): void
    {
        $html = CatalogSpellingHints::sentence(['Gallon<script>']);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Did you mean', $html->toHtml());
        $this->assertStringContainsString('Gallon&lt;script&gt;', $html->toHtml());
        $this->assertStringNotContainsString('<script>', $html->toHtml());

        $pair = CatalogSpellingHints::sentence(['Gallon', 'Gal']);

        $this->assertNotNull($pair);
        $this->assertStringContainsString(' or ', $pair->toHtml());
    }
}
