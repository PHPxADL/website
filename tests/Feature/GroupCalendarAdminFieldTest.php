<?php

namespace Tests\Feature;

use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupCalendarAdminFieldTest extends TestCase
{
	use RefreshDatabase;

	public function test_edit_group_form_shows_the_calendar_url_with_a_copy_action(): void
	{
		$user = User::factory()->create();
		config(['auth.super_admins' => [$user->email]]);
		$this->actingAs($user);

		$group = Group::factory()->create();

		Livewire::test(EditGroup::class, ['record' => $group->getKey()])
			->assertFormSet(['calendar_url' => "https://{$group->domain}/calendar.ics"])
			->assertFormFieldExists('calendar_url')
			->callFormComponentAction('calendar_url', 'copyCalendarUrl')
			->assertNotified('Calendar URL copied');
	}
}
