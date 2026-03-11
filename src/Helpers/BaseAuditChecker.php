<?php
/**
 * Base class for audit/compliance checkers.
 */
namespace Gitcd\Helpers;

abstract class BaseAuditChecker
{
    protected string $repoDir;
    protected array $results = [];

    public function __construct(string $repoDir)
    {
        $this->repoDir = $repoDir;
    }

    /**
     * Run all checks and return results.
     */
    abstract public function runAll(): array;

    /**
     * True if no checks returned 'fail' status.
     */
    public function passed(): bool
    {
        foreach ($this->results as $r) {
            if ($r['status'] === 'fail') return false;
        }
        return true;
    }

    /**
     * Get all check results.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Add a check result.
     */
    protected function addResult(string $name, string $status, string $message): void
    {
        $this->results[] = [
            'name' => $name,
            'status' => $status,
            'message' => $message,
        ];
    }
}
