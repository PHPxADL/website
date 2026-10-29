<?php

namespace App\Filament\Resources\MeetupResource\Pages;

use App\Actions\GenerateOpenGraphImage;
use App\Filament\Resources\MeetupResource;
use App\Models\Meetup;
use Filament\Resources\Pages\CreateRecord;

class CreateMeetup extends CreateRecord
{
	protected static string $resource = MeetupResource::class;
	
	protected function fillForm(): void
	{
		$this->callHook('beforeFill');

		$source = Meetup::query()->find(request()->query('duplicate'));

		$this->form->fill($source?->toDuplicateAttributes());

		$this->callHook('afterFill');
	}

	protected function afterCreate()
	{
		GenerateOpenGraphImage::run($this->getRecord());
	}
	
	protected function mutateFormDataBeforeCreate(array $data): array
	{
		if ($group = request()->attributes->get('group')) {
			$data['group_id'] = $group->getKey();
		}
		
		return $data;
	}
}
