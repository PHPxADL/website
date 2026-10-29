<?php

namespace App\Filament\Resources\MeetupResource\Actions\Concerns;

use App\Filament\Resources\MeetupResource;
use App\Models\Meetup;

trait ConfiguresDuplicateAction
{
	public static function getDefaultName(): ?string
	{
		return 'duplicate';
	}
	
	protected function setUp(): void
	{
		parent::setUp();
		
		$this->icon('heroicon-o-document-duplicate');
		$this->color('gray');
		$this->url(fn(Meetup $record) => MeetupResource::getUrl('create', ['duplicate' => $record->getKey()]));
	}
}
