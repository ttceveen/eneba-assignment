<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GamePageValidationTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidPages(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'text' => ['abc'],
            'fraction' => ['1.5'],
            'decimal' => ['1.0'],
            'exponent' => ['1e3'],
            'empty' => [''],
            'above offset limit' => [(string) (intdiv(PHP_INT_MAX, 12) + 2)],
            'integer maximum' => [(string) PHP_INT_MAX],
            'beyond integer' => ['99999999999999999999999999999999999999'],
        ];
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_page_returns_validation_error_in_both_modes(string $page): void
    {
        foreach (['popular', 'alphabetical'] as $sort) {
            $this->getJson('/api/games?sort='.$sort.'&page='.rawurlencode($page))
                ->assertUnprocessable()->assertJsonValidationErrors('page');
        }
    }

    public function test_array_page_is_invalid(): void
    {
        $this->getJson('/api/games?page[]=1')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_missing_and_valid_pages_keep_standard_pagination(): void
    {
        $game = Game::create(['name' => 'Page fixture', 'location' => ['GLOBAL']]);
        foreach (['popular', 'alphabetical'] as $sort) {
            $this->getJson('/api/games?sort='.$sort)->assertOk()
                ->assertJsonPath('meta.current_page', 1)->assertJsonPath('data.0.id', $game->id);
            $this->getJson('/api/games?sort='.$sort.'&page=1')->assertOk()
                ->assertJsonPath('meta.per_page', 12)->assertJsonPath('data.0.id', $game->id);
            foreach ([2, 5, intdiv(PHP_INT_MAX, 12) + 1] as $page) {
                $this->getJson('/api/games?sort='.$sort.'&page='.$page)->assertOk()
                    ->assertJsonPath('data', [])->assertJsonPath('meta.current_page', $page)
                    ->assertJsonPath('meta.last_page', 1)->assertJsonPath('meta.total', 1);
            }
        }
    }

    public function test_largest_valid_page_has_exact_non_overflowing_offset(): void
    {
        $page = intdiv(PHP_INT_MAX, 12) + 1;
        $query = Game::query()->forPage($page, 12)->toBase();
        $this->assertIsInt($query->offset);
        $this->assertSame(PHP_INT_MAX - PHP_INT_MAX % 12, $query->offset);
    }
}
