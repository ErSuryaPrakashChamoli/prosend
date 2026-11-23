<?php

namespace App\Services\Campaigns;

use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * The days and hours a campaign may send, in one time zone. Days are ISO weekdays (1 = Monday … 7 = Sunday); hours are
 * "HH:MM", with the end excluded. Without days or hours the window is open all the time.
 */
final readonly class SendingWindow
{
    /**
     * @param  list<int>|null  $days
     */
    public function __construct(
        public ?array $days,
        public ?string $start,
        public ?string $end,
        public string $timezone,
    ) {}

    public static function isValidTimezone(?string $timezone): bool
    {
        return $timezone !== null && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public function isUnrestricted(): bool
    {
        return ! $this->restrictsDays() && ! $this->restrictsHours();
    }

    public function contains(CarbonInterface $moment): bool
    {
        $local = $moment->copy()->setTimezone($this->timezone);

        if ($this->restrictsDays() && ! in_array($local->isoWeekday(), $this->days, true)) {
            return false;
        }

        if (! $this->restrictsHours()) {
            return true;
        }

        $time = $local->format('H:i');

        return $time >= $this->start && $time < $this->end;
    }

    /**
     * The first moment at or after the given one that is inside the window, in the application's time zone.
     */
    public function nextOpening(CarbonInterface $from): CarbonInterface
    {
        if ($this->contains($from)) {
            return $from->copy();
        }

        $local = $from->copy()->setTimezone($this->timezone);

        for ($day = 0; $day <= 7; $day++) {
            $candidate = $local->copy()->startOfDay()->addDays($day);

            if ($this->restrictsDays() && ! in_array($candidate->isoWeekday(), $this->days, true)) {
                continue;
            }

            if ($this->restrictsHours()) {
                [$hour, $minute] = array_map(intval(...), explode(':', $this->start));
                $candidate = $candidate->setTime($hour, $minute);
            }

            if ($candidate->greaterThanOrEqualTo($local)) {
                return $candidate->setTimezone(config('app.timezone'));
            }
        }

        // Unreachable while at least one day is allowed, which validation requires.
        return $from->copy();
    }

    /**
     * A short description, such as "Mon–Fri, 09:00–17:00 (Asia/Kolkata)".
     */
    public function describe(): string
    {
        if ($this->isUnrestricted()) {
            return 'Any day, any time';
        }

        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = $this->restrictsDays()
            ? implode(', ', array_map(fn (int $day): string => $names[$day], $this->sortedDays()))
            : 'Every day';
        $hours = $this->restrictsHours() ? "{$this->start}–{$this->end}" : 'any time';

        return "{$days}, {$hours} ({$this->timezone})";
    }

    private function restrictsDays(): bool
    {
        return $this->days !== null && count(array_unique($this->days)) < 7;
    }

    private function restrictsHours(): bool
    {
        return $this->start !== null && $this->end !== null && $this->start < $this->end;
    }

    /**
     * @return list<int>
     */
    private function sortedDays(): array
    {
        $days = array_values(array_unique($this->days ?? []));
        sort($days);

        return $days;
    }
}
