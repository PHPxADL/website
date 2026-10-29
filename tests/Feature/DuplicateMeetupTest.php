<?php

namespace Tests\Feature;

use App\Actions\GenerateOpenGraphImage;
use App\Enums\GroupRole;
use App\Filament\Resources\MeetupResource;
use App\Filament\Resources\MeetupResource\Pages\CreateMeetup;
use App\Filament\Resources\MeetupResource\Pages\EditMeetup;
use App\Filament\Resources\MeetupResource\Pages\ListMeetups;
use App\Models\Group;
use App\Models\Meetup;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicateMeetupTest extends TestCase
{
	use RefreshDatabase;

	protected Group $group;

	protected Meetup $meetup;

	protected function setUp(): void
	{
		parent::setUp();

		$this->group = Group::factory()->create();
		app()->instance(Group::class, $this->group);
		app()->instance("group:{$this->group->domain}", $this->group);

		$user = User::factory()->create();
		$this->group->users()->attach($user, ['role' => GroupRole::Admin]);
		$this->actingAs($user);

		$this->meetup = Meetup::factory()->for($this->group)->create([
			'location' => 'Original Location',
			'capacity' => 42,
			'description' => 'Original description',
			'external_rsvp_url' => 'https://example.com/rsvp',
			'starts_at' => now()->subMonth()->hour(18)->minute(30)->second(0),
			'ends_at' => now()->subMonth()->hour(20)->minute(0)->second(0),
		]);
	}

	public function test_duplicate_attributes_move_dates_to_a_month_after_the_latest_meetup_and_keep_time_of_day(): void
	{
		$this->setMeetupLocalTimes($this->meetup, '2026-10-08 18:30', '2026-10-08 20:00');

		$attributes = $this->meetup->toDuplicateAttributes();

		$this->assertSame('Original Location', $attributes['location']);
		$this->assertSame(42, $attributes['capacity']);
		$this->assertSame('Original description', $attributes['description']);
		$this->assertSame('https://example.com/rsvp', $attributes['external_rsvp_url']);
		$this->assertSame('2026-11-08 18:30', $this->localTime($attributes['starts_at']), 'Local time-of-day is kept across the DST change');
		$this->assertSame('2026-11-08 20:00', $this->localTime($attributes['ends_at']));
		$this->assertSame('2026-10-08 18:30', $this->localTime($this->meetup->fresh()->starts_at), 'Source meetup must not be mutated');
	}

	public function test_duplicating_an_older_meetup_uses_the_groups_latest_future_meetup_date(): void
	{
		$this->setMeetupLocalTimes($this->meetup, '2026-10-08 18:30', '2026-10-08 20:00');
		$this->setMeetupLocalTimes(Meetup::factory()->for($this->group)->create(), '2027-01-14 17:00', '2027-01-14 19:00');

		$attributes = $this->meetup->toDuplicateAttributes();

		$this->assertSame('2027-02-14 18:30', $this->localTime($attributes['starts_at']));
		$this->assertSame('2027-02-14 20:00', $this->localTime($attributes['ends_at']));
	}

	public function test_duplicate_ignores_meetups_from_other_groups(): void
	{
		$this->setMeetupLocalTimes($this->meetup, '2026-10-08 18:30', '2026-10-08 20:00');
		$this->setMeetupLocalTimes(Meetup::factory()->for(Group::factory())->create(), '2027-06-01 18:00', '2027-06-01 20:00');

		$attributes = $this->meetup->toDuplicateAttributes();

		$this->assertSame('2026-11-08 18:30', $this->localTime($attributes['starts_at']));
	}

	public function test_duplicate_does_not_overflow_into_the_following_month(): void
	{
		$this->setMeetupLocalTimes($this->meetup, '2027-01-31 18:30', '2027-01-31 20:00');

		$attributes = $this->meetup->toDuplicateAttributes();

		$this->assertSame('2027-02-28 18:30', $this->localTime($attributes['starts_at']));
	}

	public function test_duplicate_preserves_duration_when_the_meetup_ends_on_a_later_day(): void
	{
		$this->setMeetupLocalTimes($this->meetup, '2027-05-10 22:00', '2027-05-11 01:00');

		$attributes = $this->meetup->toDuplicateAttributes();

		$this->assertSame('2027-06-10 22:00', $this->localTime($attributes['starts_at']));
		$this->assertSame('2027-06-11 01:00', $this->localTime($attributes['ends_at']));
	}

	public function test_create_page_is_prefilled_from_the_duplicated_meetup(): void
	{
		GenerateOpenGraphImage::mock()->shouldReceive('handle')->once()->andReturn('og.png');

		Livewire::withQueryParams(['duplicate' => $this->meetup->getKey()])
			->test(CreateMeetup::class)
			->assertFormSet([
				'group_id' => $this->group->getKey(),
				'location' => 'Original Location',
				'capacity' => 42,
				'description' => 'Original description',
				'external_rsvp_url' => 'https://example.com/rsvp',
			])
			->call('create')
			->assertHasNoFormErrors();

		$this->assertSame(2, Meetup::query()->count());
		$this->assertSame(1, Meetup::query()->whereKeyNot($this->meetup->getKey())->where('location', 'Original Location')->count());
	}

	public function test_create_page_without_duplicate_is_prefilled_with_defaults_from_the_latest_meetup(): void
	{
		$this->setMeetupLocalTimes($this->meetup, '2026-10-08 18:30', '2026-10-08 20:00');

		$state = Livewire::test(CreateMeetup::class)
			->assertFormSet([
				'group_id' => $this->group->getKey(),
				'location' => 'Original Location',
				'capacity' => 42,
				'description' => null,
			])
			->get('data');

		$this->assertSame('2026-11-08 18:30:00', $state['starts_at']);
		$this->assertSame('2026-11-08 20:00:00', $state['ends_at']);
	}

	public function test_create_page_defaults_come_from_the_meetup_with_the_latest_start_not_the_latest_created(): void
	{
		$this->setMeetupLocalTimes($this->meetup, '2026-11-18 18:30', '2026-11-18 20:00');

		$this->travel(1)->minute();

		$recently_created_past_meetup = Meetup::factory()->for($this->group)->create([
			'location' => 'Older Location',
			'capacity' => 10,
		]);
		$this->setMeetupLocalTimes($recently_created_past_meetup, '2026-11-04 17:00', '2026-11-04 19:00');

		$state = Livewire::test(CreateMeetup::class)
			->assertFormSet([
				'location' => 'Original Location',
				'capacity' => 42,
			])
			->get('data');

		$this->assertSame('2026-12-18 18:30:00', $state['starts_at']);
		$this->assertSame('2026-12-18 20:00:00', $state['ends_at']);
	}

	public function test_create_page_ignores_unknown_duplicate_ids(): void
	{
		Livewire::withQueryParams(['duplicate' => '123'])
			->test(CreateMeetup::class)
			->assertFormSet(['description' => null])
			->assertSuccessful();
	}

	public function test_duplicate_action_is_available_on_the_table_and_edit_page(): void
	{
		$url = MeetupResource::getUrl('create', ['duplicate' => $this->meetup->getKey()]);

		Livewire::test(ListMeetups::class)
			->assertTableActionExists('duplicate')
			->assertTableActionHasUrl('duplicate', $url, $this->meetup);

		Livewire::test(EditMeetup::class, ['record' => $this->meetup->getKey()])
			->assertActionExists('duplicate')
			->assertActionHasUrl('duplicate', $url);
	}

	protected function setMeetupLocalTimes(Meetup $meetup, string $starts_at, string $ends_at): void
	{
		$meetup->update([
			'starts_at' => Carbon::parse($starts_at, $meetup->group->timezone)->utc(),
			'ends_at' => Carbon::parse($ends_at, $meetup->group->timezone)->utc(),
		]);
	}

	protected function localTime(CarbonInterface $date): string
	{
		return $date->avoidMutation()->timezone($this->group->timezone)->format('Y-m-d H:i');
	}
}
