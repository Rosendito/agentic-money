<?php

namespace App\Support\AgentRoles;

use RuntimeException;
use Throwable;

final class AgentRoleSynchronizer
{
    private const MANAGED_MARKER = 'Managed by Agentic Money agent-roles:sync.';

    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? base_path();
    }

    /**
     * @throws RuntimeException
     */
    public function synchronize(bool $check = false): AgentRoleSyncResult
    {
        $roles = $this->loadRoles();
        $outputs = $this->renderOutputs($roles);
        $stalePaths = $this->managedStalePaths(array_keys($outputs));
        $changedPaths = $this->changedPaths($outputs, $stalePaths);

        if ($check || $changedPaths === []) {
            return new AgentRoleSyncResult($changedPaths);
        }

        $this->assertManagedDestinations($outputs);

        foreach ($outputs as $path => $contents) {
            if (! is_file($path) || file_get_contents($path) !== $contents) {
                $this->writeAtomically($path, $contents);
            }
        }

        foreach ($stalePaths as $path) {
            if (! unlink($path)) {
                throw new RuntimeException("Unable to remove stale managed agent role output [{$path}].");
            }
        }

        return new AgentRoleSyncResult($changedPaths);
    }

    /**
     * @return array<string, array{name: string, description: string, prompt: string}>
     */
    private function loadRoles(): array
    {
        $rolePaths = glob($this->path('.ai/roles/*.md')) ?: [];
        sort($rolePaths);

        if ($rolePaths === []) {
            throw new RuntimeException('No canonical role definitions were found in [.ai/roles].');
        }

        $roles = [];

        foreach ($rolePaths as $path) {
            $role = $this->parseRole($path);

            if (array_key_exists($role['name'], $roles)) {
                throw new RuntimeException("Duplicate canonical agent role [{$role['name']}].");
            }

            $roles[$role['name']] = $role;
        }

        return $roles;
    }

    /**
     * @return array{name: string, description: string, prompt: string}
     */
    private function parseRole(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read canonical agent role [{$path}].");
        }

        if (! preg_match('/\A---\R(?<frontmatter>.*?)\R---\R?(?<prompt>.*)\z/s', $contents, $matches)) {
            throw new RuntimeException("Canonical agent role [{$path}] must begin with YAML frontmatter.");
        }

        $frontmatter = [];

        foreach (preg_split('/\R/', $matches['frontmatter']) ?: [] as $line) {
            if (! preg_match('/\A(?<key>[a-z_]+):[ ]*(?<value>.+)\z/', $line, $entry)) {
                throw new RuntimeException("Canonical agent role [{$path}] has unsupported frontmatter.");
            }

            if (! in_array($entry['key'], ['name', 'description'], true) || array_key_exists($entry['key'], $frontmatter)) {
                throw new RuntimeException("Canonical agent role [{$path}] has unsupported frontmatter.");
            }

            $frontmatter[$entry['key']] = trim($entry['value'], " \t\"'");
        }

        $name = $frontmatter['name'] ?? null;
        $description = $frontmatter['description'] ?? null;
        $prompt = trim($matches['prompt']);
        $fileName = pathinfo($path, PATHINFO_FILENAME);

        if (! is_string($name) || ! preg_match('/\A[a-z][a-z0-9-]*\z/', $name)) {
            throw new RuntimeException("Canonical agent role [{$path}] must define a kebab-case name.");
        }

        if ($name !== $fileName) {
            throw new RuntimeException("Canonical agent role name [{$name}] must match filename [{$fileName}].");
        }

        if (! is_string($description) || $description === '' || str_contains($description, "\n")) {
            throw new RuntimeException("Canonical agent role [{$path}] must define a single-line description.");
        }

        if ($prompt === '') {
            throw new RuntimeException("Canonical agent role [{$path}] must define a prompt body.");
        }

        return compact('name', 'description', 'prompt');
    }

    /**
     * @param  array<string, array{name: string, description: string, prompt: string}>  $roles
     * @return array<string, string>
     */
    private function renderOutputs(array $roles): array
    {
        $outputs = [];

        foreach ($roles as $role) {
            $outputs[$this->path(".claude/agents/{$role['name']}.md")] = $this->renderClaudeRole($role);
            $outputs[$this->path(".codex/agents/{$role['name']}.toml")] = $this->renderCodexRole($role);
        }

        ksort($outputs);

        return $outputs;
    }

    /**
     * @param  array{name: string, description: string, prompt: string}  $role
     */
    private function renderClaudeRole(array $role): string
    {
        return sprintf(
            "---\nname: %s\ndescription: %s\n---\n\n<!-- %s Do not edit; change .ai/roles/%s.md and run php artisan agent-roles:sync. -->\n\n%s\n",
            $role['name'],
            $this->quoteYaml($role['description']),
            self::MANAGED_MARKER,
            $role['name'],
            $role['prompt'],
        );
    }

    /**
     * @param  array{name: string, description: string, prompt: string}  $role
     */
    private function renderCodexRole(array $role): string
    {
        return sprintf(
            "# %s Do not edit; change .ai/roles/%s.md and run php artisan agent-roles:sync.\nname = %s\ndescription = %s\ndeveloper_instructions = %s\n",
            self::MANAGED_MARKER,
            $role['name'],
            $this->tomlString($role['name']),
            $this->tomlString($role['description']),
            $this->tomlString($role['prompt']),
        );
    }

    private function quoteYaml(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function tomlString(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<string>  $desiredPaths
     * @return list<string>
     */
    private function managedStalePaths(array $desiredPaths): array
    {
        $desiredPaths = array_fill_keys($desiredPaths, true);
        $stalePaths = [];

        foreach ([$this->path('.claude/agents/*.md'), $this->path('.codex/agents/*.toml')] as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (! isset($desiredPaths[$path]) && $this->isManaged($path)) {
                    $stalePaths[] = $path;
                }
            }
        }

        sort($stalePaths);

        return $stalePaths;
    }

    /**
     * @param  array<string, string>  $outputs
     * @param  list<string>  $stalePaths
     * @return list<string>
     */
    private function changedPaths(array $outputs, array $stalePaths): array
    {
        $changedPaths = $stalePaths;

        foreach ($outputs as $path => $contents) {
            if (! is_file($path) || file_get_contents($path) !== $contents) {
                $changedPaths[] = $path;
            }
        }

        sort($changedPaths);

        return $changedPaths;
    }

    /**
     * @param  array<string, string>  $outputs
     */
    private function assertManagedDestinations(array $outputs): void
    {
        foreach ($outputs as $path => $contents) {
            if (is_file($path) && file_get_contents($path) !== $contents && ! $this->isManaged($path)) {
                throw new RuntimeException("Refusing to overwrite unmanaged agent role output [{$path}].");
            }
        }
    }

    private function isManaged(string $path): bool
    {
        $contents = file_get_contents($path);

        return $contents !== false && str_contains($contents, self::MANAGED_MARKER);
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create agent role output directory [{$directory}].");
        }

        $temporaryPath = tempnam($directory, '.agent-role-');

        if ($temporaryPath === false) {
            throw new RuntimeException("Unable to create temporary agent role output in [{$directory}].");
        }

        try {
            if (file_put_contents($temporaryPath, $contents) === false || ! rename($temporaryPath, $path)) {
                throw new RuntimeException("Unable to write agent role output [{$path}].");
            }
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw $exception;
        }
    }

    private function path(string $path): string
    {
        return $this->basePath.'/'.$path;
    }
}
