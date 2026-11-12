<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Meetup;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

class GroupCalendarController
{
	public const HISTORY_MONTHS = 6;

	public function __invoke(Request $request): Response
	{
		$group = $request->attributes->get('group');

		abort_unless($group instanceof Group, 404);

		$events = $group->meetups()
			->where('ends_at', '>', now()->subMonths(self::HISTORY_MONTHS))
			->orderBy('starts_at')
			->get()
			->map(fn (Meetup $meetup) => $this->event($group, $meetup->setRelation('group', $group)));

		$calendar = Calendar::create($group->name)
			->description((string) $group->description)
			->refreshInterval(60)
			->event($events->all());

		return response($calendar->get(), 200, [
			'Content-Type' => 'text/calendar; charset=utf-8',
			'Content-Disposition' => 'inline; filename="calendar.ics"',
		]);
	}

	protected function event(Group $group, Meetup $meetup): Event
	{
		$event = Event::create("{$group->name} @ {$meetup->location}")
			->uniqueIdentifier("meetup-{$meetup->getKey()}@{$group->domain}")
			->startsAt($meetup->starts_at)
			->endsAt($meetup->ends_at)
			->address((string) $meetup->location)
			->url($meetup->rsvp_url);

		if ($meetup->description) {
			$event->description($meetup->description);
		}

		return $event;
	}
}
