<?php

namespace App\Console\Commands;

use App\Support\AgentRoles\AgentRoleSynchronizer;
use Illuminate\Console\Command;

class SyncAgentRolesCommand extends Command
{
    protected $signature = 'agent-roles:sync {--check : Fail when generated agent role files are out of sync}';

    protected $description = 'Synchronize canonical .ai roles to Claude and Codex agent definitions';

    public function handle(AgentRoleSynchronizer $synchronizer): int
    {
        $result = $synchronizer->synchronize($this->option('check'));

        if ($this->option('check') && $result->hasChanges()) {
            $this->error('Agent role outputs are out of sync:');

            foreach ($result->changedPaths as $path) {
                $this->line(' - '.str_replace(base_path().'/', '', $path));
            }

            return self::FAILURE;
        }

        $this->info($result->hasChanges()
            ? 'Agent roles synchronized successfully.'
            : 'Agent roles are already synchronized.');

        return self::SUCCESS;
    }
}
