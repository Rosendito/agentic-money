<?php

namespace App\Support\AgentRoles;

final class AgentRoleSyncResult
{
    /**
     * @param  list<string>  $changedPaths
     */
    public function __construct(public array $changedPaths) {}

    public function hasChanges(): bool
    {
        return $this->changedPaths !== [];
    }
}
