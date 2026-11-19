<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MatchGameCoversTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_verified_matches_change_img_and_the_second_run_is_a_no_op(): void
    {
        $directory = sys_get_temp_dir().'/eneba-cover-test-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory);
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/s8kAAAAASUVORK5CYII=');

        try {
            foreach (['Dead Space.png', 'portal-2.png', 'portal_2.png', 'portal-3.png'] as $filename) {
                File::put($directory.'/'.$filename, $pixel);
            }
            $matched = Game::create(['name' => 'Dead Space', 'location' => ['GLOBAL']]);
            $ambiguous = Game::create(['name' => 'Portal 2', 'location' => ['GLOBAL'], 'img' => '/old.png']);
            $before = $matched->fresh()->getRawOriginal();

            $this->artisan('games:match-covers', ['--directory' => $directory, '--dry-run' => true])
                ->expectsOutput('Games: 2. Image files: 4. Verified matches: 1. Would update: 1.')->assertSuccessful();
            $this->assertNull($matched->fresh()->img);

            $options = ['--directory' => $directory, '--apply' => true];
            $this->artisan('games:match-covers', $options)
                ->expectsOutput('Games: 2. Image files: 4. Verified matches: 1. Updated: 1.')->assertSuccessful();
            $this->assertSame(array_replace($before, ['img' => '/images/games/Dead%20Space.png']), $matched->fresh()->getRawOriginal());
            $this->assertSame('/old.png', $ambiguous->fresh()->img);
            $this->assertDatabaseCount('games', 2);

            $this->artisan('games:match-covers', $options)
                ->expectsOutput('Games: 2. Image files: 4. Verified matches: 1. Updated: 0.')->assertSuccessful();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_related_cover_is_lower_priority_than_exact_and_never_replaces_an_existing_cover(): void
    {
        $directory = sys_get_temp_dir().'/eneba-cover-test-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory);
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/s8kAAAAASUVORK5CYII=');
        $game = Game::create(['name' => 'Forza Horizon 5', 'location' => ['GLOBAL']]);
        $options = ['--directory' => $directory, '--apply' => true];

        try {
            File::put($directory.'/forzahorizon6.png', $pixel);
            $this->artisan('games:match-covers', $options)->assertSuccessful();
            $this->assertSame('/images/games/forzahorizon6.png', $game->fresh()->img);

            File::put($directory.'/forza-horizon-5.png', $pixel);
            $this->artisan('games:match-covers', $options)
                ->expectsOutput('Games: 1. Image files: 2. Verified matches: 1. Updated: 0.')->assertSuccessful();
            $this->assertSame('/images/games/forzahorizon6.png', $game->fresh()->img);

            $game->update(['img' => null]);
            $this->artisan('games:match-covers', $options)->assertSuccessful();
            $this->assertSame('/images/games/forza-horizon-5.png', $game->fresh()->img);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
