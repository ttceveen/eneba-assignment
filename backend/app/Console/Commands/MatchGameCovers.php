<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class MatchGameCovers extends Command
{
    protected $signature = 'games:match-covers
        {--directory= : Directory containing the files served under /images/games}
        {--dry-run : Report matches without updating the database (default)}
        {--apply : Fill empty covers with unambiguous matches in the local database}';

    protected $description = 'Match existing games to verified cover filenames and update only games.img';

    // Reviewed variants that normalization alone cannot resolve.
    private const ALIASES = [
        'assasincreedodissey.jpg' => "Assassin's Creed Odyssey",
        'assasincreedorigin.jpg' => "Assassin's Creed Origins",
        'diablo4.jpg' => 'Diablo IV',
        'doomethernal.jpg' => 'DOOM Eternal',
        'kingdomcomedeliverance2.jpg' => 'Kingdom Come: Deliverance II',
        'sekiro.jpg' => 'Sekiro: Shadows Die Twice',
        'spidermanmilesmorales.jpg' => "Marvel's Spider-Man: Miles Morales",
        'spidermanremastered.jpg' => "Marvel's Spider-Man Remastered",
        'theelderscrollsskyrim.jpg' => 'The Elder Scrolls V: Skyrim',
        'thelastofuspart1.jpg' => 'The Last of Us Part I',
        'witcher3vildhunt.jpg' => 'The Witcher 3: Wild Hunt',
    ];

    // Checked against the artwork. Lower priority values are preferred.
    private const RELATED = [
        'assasincreedodissey.jpg' => ["Assassin's Creed Valhalla", 3, "series: Assassin's Creed Odyssey"],
        'baldursgate.jpg' => ["Baldur's Gate 3", 1, 'edition: Deluxe Edition'],
        'cyberpunk2077.jpg' => ['Cyberpunk 2077', 2, 'DLC bundle: Phantom Liberty'],
        'deathstranding.jpg' => ['Death Stranding', 1, "edition: Director's Cut"],
        'dyingkight2stayhyman.jpg' => ['Dying Light 2 Stay Human', 1, 'edition: Digital Extras Edition'],
        'forzahorizon6.png' => ['Forza Horizon 5', 2, 'DLC: Forza Horizon 5 Premium Add-Ons Bundle'],
        'grandtheftauto5.jpg' => ['Grand Theft Auto V', 1, 'edition: Premium Online Edition'],
        'horizonzerodown.jpg' => ['Horizon Zero Dawn', 1, 'edition: Remastered'],
        'kingdomcomedeliverance.jpg' => ['Kingdom Come: Deliverance', 1, 'edition: Royal Edition'],
    ];

    // Despite its filename, this is the first Assassin's Creed, not Valhalla.
    private const MISNAMED = [
        'assasincreedvalhalla.jpg' => "Assassin's Creed Director's Cut",
    ];

    private const IMAGE_TYPES = [
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'webp' => IMAGETYPE_WEBP,
        'gif' => IMAGETYPE_GIF,
        'avif' => IMAGETYPE_AVIF,
    ];

    public function handle(): int
    {
        $apply = $this->option('apply');
        if ($apply && ($this->option('dry-run') || ! app()->environment('local', 'testing'))) {
            $this->error('Apply requires a local or testing environment and cannot be combined with --dry-run.');

            return self::FAILURE;
        }

        $directory = $this->option('directory') ?: base_path('../frontend/public/images/games');
        if (! File::isDirectory($directory) || ! is_readable($directory)) {
            $this->error("Cover directory is not readable: {$directory}");

            return self::FAILURE;
        }

        $games = Game::query()->orderBy('id')->get(['id', 'name', 'img']);
        $gamesByTitle = $games->groupBy(fn (Game $game): string => $this->titleKey($game->name));
        $images = [];
        $report = [];
        $updates = [];
        $matchedGames = [];
        $imageCount = 0;

        foreach (File::files($directory) as $file) {
            $type = self::IMAGE_TYPES[strtolower($file->getExtension())] ?? null;
            if ($type === null) {
                continue;
            }

            $imageCount++;
            $filename = $file->getFilename();
            $url = '/images/games/'.rawurlencode($filename);
            if ((@getimagesize($file->getPathname())[2] ?? null) !== $type) {
                $report[] = ['—', '—', $filename, $url, '—', 'invalid image'];

                continue;
            }

            if (isset(self::MISNAMED[$filename])) {
                $report[] = ['—', '—', $filename, $url, '—', 'unused: '.self::MISNAMED[$filename]];

                continue;
            }

            $related = self::RELATED[$filename] ?? null;
            if ($related !== null) {
                [$title, $priority, $type] = $related;
                $images[$this->titleKey($title)][] = [$filename, $url, $priority, $type];
            }

            if ($related === null || isset(self::ALIASES[$filename])) {
                $key = $this->titleKey(self::ALIASES[$filename] ?? $file->getBasename('.'.$file->getExtension()));
                $images[$key][] = [$filename, $url, 0, 'exact'];
            }
        }

        foreach ($images as $key => $files) {
            $candidates = $key === '' ? collect() : $gamesByTitle->get($key, collect());
            $game = $candidates->count() === 1 ? $candidates->first() : null;
            $bestPriority = min(array_column($files, 2));
            $bestCount = count(array_filter($files, fn (array $file): bool => $file[2] === $bestPriority));

            foreach ($files as [$filename, $url, $priority, $type]) {
                $status = match (true) {
                    $candidates->isEmpty() => 'unmatched',
                    $candidates->count() > 1 => 'ambiguous',
                    $priority > $bestPriority => 'lower priority',
                    $bestCount > 1 => 'ambiguous',
                    $game->img === $url => 'unchanged',
                    $game->img !== null && trim($game->img) !== '' => 'preserved existing cover',
                    default => 'update',
                };
                $report[] = [
                    $candidates->isEmpty() ? '—' : $candidates->pluck('id')->implode(', '),
                    $candidates->isEmpty() ? '—' : $candidates->pluck('name')->implode(' / '),
                    $filename, $url, $type, $status,
                ];

                if ($status === 'update') {
                    $updates[] = [$game, $url];
                }
                if (in_array($status, ['update', 'unchanged', 'preserved existing cover'], true)) {
                    $matchedGames[$game->id] = true;
                }
            }
        }

        foreach ($games as $game) {
            if (! isset($matchedGames[$game->id])) {
                $report[] = [$game->id, $game->name, '—', $game->img ?? '—', '—', 'no verified match; preserve current value'];
            }
        }

        $this->table(['game_id', 'game_title', 'image_filename', 'image_url', 'match_type', 'match_status'], $report);
        if ($apply && $updates !== []) {
            DB::transaction(function () use ($updates): void {
                foreach ($updates as [$game, $url]) {
                    $affected = DB::table('games')->where('id', $game->id)->where('name', $game->name)
                        ->where('img', $game->img)->update(['img' => $url]);
                    if ($affected !== 1) {
                        throw new RuntimeException("Game {$game->id} changed since the scan. No covers were updated.");
                    }
                }
            });
        }

        $this->info(sprintf('Games: %d. Image files: %d. Verified matches: %d. %s: %d.',
            $games->count(), $imageCount, count($matchedGames), $apply ? 'Updated' : 'Would update', count($updates)));

        return self::SUCCESS;
    }

    private function titleKey(string $title): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($title)));
    }
}
