<?php

namespace LucaLongo\Licensing\Observers\Concerns;

use Illuminate\Support\Facades\Auth;

trait TracksActor
{
    protected function getActorData(): array
    {
        if (! Auth::check()) {
            return [];
        }
        
        $user = Auth::user();
        
        return [
            'actor_id' => $user->id,
            'actor_type' => get_class($user),
            'actor_name' => $user->name ?? null,
            'actor_email' => $user->email ?? null,
        ];
    }
    
    protected function withActorData(array $data): array
    {
        $actorData = $this->getActorData();
        
        if (! empty($actorData)) {
            $data['meta'] = array_merge($data['meta'] ?? [], [
                'actor' => $actorData,
            ]);
        }
        
        return $data;
    }
}