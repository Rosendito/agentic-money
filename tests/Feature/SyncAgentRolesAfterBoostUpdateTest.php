<?php

use App\Listeners\SyncAgentRolesAfterBoostUpdate;
use App\Support\AgentRoles\AgentRoleSynchronizer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $this->agentRoleBasePath = sys_get_temp_dir().'/agent-role-listener-'.bin2hex(random_bytes(8));

    mkdir($this->agentRoleBasePath.'/.ai/roles', 0755, true);
    file_put_contents($this->agentRoleBasePath.'/.ai/roles/planner.md', <<<'MARKDOWN'
---
name: planner
description: "Plans bounded work."
---

Plan the work without implementing it.
MARKDOWN);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->agentRoleBasePath);
});

test('it synchronizes roles only after a successful boost update', function (): void {
    $listener = new SyncAgentRolesAfterBoostUpdate(new AgentRoleSynchronizer($this->agentRoleBasePath));
    $output = new BufferedOutput;

    $listener->handle(new CommandFinished('boost:update', new ArrayInput([]), $output, 0));

    expect($this->agentRoleBasePath.'/.claude/agents/planner.md')->toBeFile()
        ->and($output->fetch())->toContain('Agent roles synchronized successfully.');
});

test('it does not synchronize after another command or a failed boost update', function (): void {
    $listener = new SyncAgentRolesAfterBoostUpdate(new AgentRoleSynchronizer($this->agentRoleBasePath));

    $listener->handle(new CommandFinished('cache:clear', new ArrayInput([]), new BufferedOutput, 0));
    $listener->handle(new CommandFinished('boost:update', new ArrayInput([]), new BufferedOutput, 1));

    expect($this->agentRoleBasePath.'/.claude/agents/planner.md')->not->toBeFile();
});

test('it propagates synchronization failures after a successful boost update', function (): void {
    file_put_contents($this->agentRoleBasePath.'/.ai/roles/planner.md', 'invalid role');

    $listener = new SyncAgentRolesAfterBoostUpdate(new AgentRoleSynchronizer($this->agentRoleBasePath));

    expect(fn () => $listener->handle(new CommandFinished('boost:update', new ArrayInput([]), new BufferedOutput, 0)))
        ->toThrow(RuntimeException::class, 'must begin with YAML frontmatter');
});
