<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Meetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupCalendarFeedTest extends TestCase
{
	use RefreshDatabase;

	public function test_feed_is_served_as_an_ics_calendar(): void
	{
		$group = Group::factory()->create(['name' => 'PHP×Testville']);
		app()->instance("group:{$group->domain}", $group);
		$meetup = Meetup::factory()->for($group)->create(['location' => 'The Pub']);

		$response = $this->get($group->url('calendar.ics'))->assertOk();

		$this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));
		$response->assertSee('BEGIN:VCALENDAR', false)
			->assertSee('BEGIN:VEVENT', false)
			->assertSee("meetup-{$meetup->id}@{$group->domain}", false)
			->assertSee('The Pub', false);
	}

	public function test_feed_includes_the_last_six_months_but_not_older_meetups(): void
	{
		$group = Group::factory()->create();
		app()->instance("group:{$group->domain}", $group);

		$recent = Meetup::factory()->for($group)->create([
			'starts_at' => now()->subMonths(5),
			'ends_at' => now()->subMonths(5)->addHours(2),
		]);
		$old = Meetup::factory()->for($group)->create([
			'starts_at' => now()->subMonths(7),
			'ends_at' => now()->subMonths(7)->addHours(2),
		]);

		$this->get($group->url('calendar.ics'))
			->assertOk()
			->assertSee("meetup-{$recent->id}@", false)
			->assertDontSee("meetup-{$old->id}@", false);
	}

	public function test_feed_only_contains_the_current_groups_meetups(): void
	{
		$group = Group::factory()->create();
		$other = Group::factory()->create();
		app()->instance("group:{$group->domain}", $group);
		$mine = Meetup::factory()->for($group)->create();
		$theirs = Meetup::factory()->for($other)->create();

		$this->get($group->url('calendar.ics'))
			->assertSee("meetup-{$mine->id}@", false)
			->assertDontSee("meetup-{$theirs->id}@", false);
	}

	public function test_group_without_meetups_returns_an_empty_calendar(): void
	{
		$group = Group::factory()->create();
		app()->instance("group:{$group->domain}", $group);

		$this->get($group->url('calendar.ics'))
			->assertOk()
			->assertSee('BEGIN:VCALENDAR', false)
			->assertDontSee('BEGIN:VEVENT', false);
	}

	public function test_feed_is_not_available_on_the_root_domain(): void
	{
		$this->get('https://phpx.test/calendar.ics')->assertNotFound();
	}

	public function test_group_exposes_its_calendar_url(): void
	{
		$group = Group::factory()->create();

		$this->assertSame("https://{$group->domain}/calendar.ics", $group->calendar_url);
	}
}
