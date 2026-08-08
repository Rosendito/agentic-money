<?php

use App\Support\AgentRoles\AgentRoleSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

beforeEach(function (): void {
    $this->agentRoleBasePath = sys_get_temp_dir().'/agent-role-sync-'.bin2hex(random_bytes(8));

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

test('it renders canonical roles and is idempotent', function (): void {
    $synchronizer = new AgentRoleSynchronizer($this->agentRoleBasePath);

    expect($synchronizer->synchronize()->hasChanges())->toBeTrue()
        ->and(file_get_contents($this->agentRoleBasePath.'/.claude/agents/planner.md'))
        ->toContain('name: planner')
        ->toContain('Plan the work without implementing it.')
        ->and(file_get_contents($this->agentRoleBasePath.'/.codex/agents/planner.toml'))
        ->toContain('name = "planner"')
        ->toContain('developer_instructions = "Plan the work without implementing it."')
        ->and($synchronizer->synchronize()->hasChanges())
        ->toBeFalse();
});

test('the check command reports drift without writing it', function (): void {
    $this->app->instance(AgentRoleSynchronizer::class, new AgentRoleSynchronizer($this->agentRoleBasePath));

    $this->artisan('agent-roles:sync --check')
        ->expectsOutputToContain('out of sync')
        ->assertExitCode(Command::FAILURE);

    expect($this->agentRoleBasePath.'/.claude/agents/planner.md')->not->toBeFile();

    $this->artisan('agent-roles:sync')->assertExitCode(Command::SUCCESS);
    $this->artisan('agent-roles:sync --check')->assertExitCode(Command::SUCCESS);
});

test('it removes stale managed outputs but preserves unmanaged agent files', function (): void {
    mkdir($this->agentRoleBasePath.'/.claude/agents', 0755, true);
    mkdir($this->agentRoleBasePath.'/.codex/agents', 0755, true);

    file_put_contents($this->agentRoleBasePath.'/.claude/agents/retired.md', '<!-- Managed by Agentic Money agent-roles:sync. Do not edit. -->');
    file_put_contents($this->agentRoleBasePath.'/.codex/agents/retired.toml', '# Managed by Agentic Money agent-roles:sync. Do not edit.');
    file_put_contents($this->agentRoleBasePath.'/.claude/agents/personal.md', 'This is an unmanaged personal agent.');

    (new AgentRoleSynchronizer($this->agentRoleBasePath))->synchronize();

    expect($this->agentRoleBasePath.'/.claude/agents/retired.md')->not->toBeFile()
        ->and($this->agentRoleBasePath.'/.codex/agents/retired.toml')->not->toBeFile()
        ->and(file_get_contents($this->agentRoleBasePath.'/.claude/agents/personal.md'))->toContain('unmanaged personal agent');
});

test('it refuses to overwrite an unmanaged output for a canonical role', function (): void {
    mkdir($this->agentRoleBasePath.'/.claude/agents', 0755, true);
    file_put_contents($this->agentRoleBasePath.'/.claude/agents/planner.md', 'Do not overwrite me.');

    expect(fn () => (new AgentRoleSynchronizer($this->agentRoleBasePath))->synchronize())
        ->toThrow(RuntimeException::class, 'Refusing to overwrite unmanaged agent role output');
});
