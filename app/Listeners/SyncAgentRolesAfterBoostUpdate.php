<?php

namespace App\Listeners;

use App\Support\AgentRoles\AgentRoleSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;

final class SyncAgentRolesAfterBoostUpdate
{
    public function __construct(private AgentRoleSynchronizer $synchronizer) {}

    public function handle(CommandFinished $event): void
    {
        if ($event->command !== 'boost:update' || $event->exitCode !== Command::SUCCESS) {
            return;
        }

        $result = $this->synchronizer->synchronize();

        if ($result->hasChanges()) {
            $event->output->writeln('Agent roles synchronized successfully.');
        }
    }
}
