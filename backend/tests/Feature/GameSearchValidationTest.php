<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameSearchValidationTest extends TestCase
{
    public static function invalidInputs(): array
    {
        return [
            'missing' => ['', 'q'],
            'empty' => ['q=', 'q'],
            'spaces' => ['q=%20%20', 'q'],
            'unicode spaces' => ['q=%E2%80%83', 'q'],
            'long' => ['q='.str_repeat('x', 256), 'q'],
            'array' => ['q[]=game', 'q'],
            'zero page' => ['q=game&page=0', 'page'],
            'negative page' => ['q=game&page=-1', 'page'],
            'fraction page' => ['q=game&page=1.5', 'page'],
            'text page' => ['q=game&page=no', 'page'],
            'overflow page' => ['q=game&page='.PHP_INT_MAX, 'page'],
            'huge page' => ['q=game&page=99999999999999999999999999', 'page'],
            'above limit' => ['q=game&page='.(intdiv(PHP_INT_MAX, 12) + 2), 'page'],
            'invalid preview' => ['q=game&preview=all', 'preview'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_search_returns_422_before_querying(string $query, string $field): void
    {
        $this->getJson('/api/games/search?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_sqlite_does_not_pretend_to_support_postgres_fuzzy_search(): void
    {
        $this->getJson('/api/games/search?q=witcher')->assertStatus(503);
    }
}
