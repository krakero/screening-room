<?php

namespace App\Actions\Tmdb;

use App\Concerns\NormalizesTmdbArrays;
use App\Enums\CreditType;
use App\Models\Person;
use App\Models\Title;

class SyncCredits
{
    use NormalizesTmdbArrays;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_CREW_JOBS = ['Director', 'Writer', 'Screenplay', 'Creator', 'Executive Producer'];

    /**
     * @param  array<string, mixed>  $credits
     */
    public function handle(Title $title, array $credits, bool $aggregate): void
    {
        $rows = [
            ...$this->castRows($credits['cast'] ?? [], $aggregate),
            ...$this->crewRows($credits['crew'] ?? [], $aggregate),
        ];

        $title->credits()->delete();

        foreach ($rows as $row) {
            $person = Person::updateOrCreate(
                ['tmdb_id' => $row['tmdb_id']],
                [
                    'name' => $row['name'],
                    'known_for_department' => $row['known_for_department'],
                    'profile_path' => $row['profile_path'],
                ],
            );

            $title->credits()->create([
                'person_id' => $person->id,
                'type' => $row['type'],
                'character' => $row['character'] ?? null,
                'job' => $row['job'] ?? null,
                'department' => $row['department'] ?? null,
                'order' => $row['order'] ?? 0,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $cast
     * @return array<int, array<string, mixed>>
     */
    private function castRows(array $cast, bool $aggregate): array
    {
        return collect($cast)
            ->sortBy('order')
            ->take(20)
            ->map(function (array $member) use ($aggregate): array {
                $character = $aggregate
                    ? ($member['roles'][0]['character'] ?? null)
                    : ($member['character'] ?? null);

                return [
                    'tmdb_id' => $member['id'],
                    'name' => $member['name'],
                    'known_for_department' => $member['known_for_department'] ?? null,
                    'profile_path' => $member['profile_path'] ?? null,
                    'type' => CreditType::Cast,
                    'character' => $character,
                    'order' => $member['order'] ?? 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $crew
     * @return array<int, array<string, mixed>>
     */
    private function crewRows(array $crew, bool $aggregate): array
    {
        $rows = [];

        foreach ($crew as $member) {
            $jobs = $aggregate
                ? collect($this->arrayOfArrays($member['jobs'] ?? []))->pluck('job')->all()
                : array_filter([$member['job'] ?? null]);

            foreach ($jobs as $job) {
                if (! in_array($job, self::ALLOWED_CREW_JOBS, true)) {
                    continue;
                }

                $rows[] = [
                    'tmdb_id' => $member['id'],
                    'name' => $member['name'],
                    'known_for_department' => $member['known_for_department'] ?? null,
                    'profile_path' => $member['profile_path'] ?? null,
                    'type' => CreditType::Crew,
                    'job' => $job,
                    'department' => $member['department'] ?? null,
                ];
            }
        }

        return $rows;
    }
}
