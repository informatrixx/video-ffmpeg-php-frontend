<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private Config $config;
    private ?PDO $pdo = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = $this->config->databaseDsn();
        if (str_starts_with($dsn, 'sqlite:')) {
            $this->prepareSqlitePath(substr($dsn, 7));
        }

        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if (str_starts_with($dsn, 'sqlite:')) {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }

        $this->ensureSchema($this->pdo);

        return $this->pdo;
    }

    private function prepareSqlitePath(string $databasePath): void
    {
        if ($databasePath === '') {
            throw new \RuntimeException('Empty sqlite database path');
        }

        $directory = dirname($databasePath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException("Unable to create database directory: {$directory}");
            }
        }
    }

    private function ensureSchema(PDO $pdo): void
    {
        $schemaStatements = [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description TEXT,
                published_version_id INTEGER,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS template_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_id INTEGER NOT NULL,
                version INTEGER NOT NULL,
                status TEXT NOT NULL,
                schema_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                published_at TEXT,
                UNIQUE(template_id, version),
                FOREIGN KEY(template_id) REFERENCES templates(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL,
                source_folder TEXT NOT NULL,
                recursive INTEGER NOT NULL DEFAULT 0,
                output_folder TEXT NOT NULL,
                template_id INTEGER,
                template_version_id INTEGER,
                status TEXT NOT NULL,
                summary_json TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS jobs (
                id TEXT PRIMARY KEY,
                batch_id INTEGER,
                type TEXT NOT NULL,
                source_path TEXT NOT NULL,
                output_folder TEXT NOT NULL,
                output_file TEXT NOT NULL,
                title TEXT,
                status TEXT NOT NULL,
                position INTEGER NOT NULL,
                template_id INTEGER,
                template_version_id INTEGER,
                analysis_json TEXT,
                compiled_plan_json TEXT,
                progress_json TEXT,
                result_json TEXT,
                error_text TEXT,
                worker_pid INTEGER,
                started_at TEXT,
                finished_at TEXT,
                last_heartbeat_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL
            )',
            'CREATE TABLE IF NOT EXISTS job_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id TEXT NOT NULL,
                topic TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS runtime_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id TEXT,
                payload_json TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS settings (
                key_name TEXT PRIMARY KEY,
                value_json TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_jobs_status_position ON jobs(status, position)',
            'CREATE INDEX IF NOT EXISTS idx_job_events_created ON job_events(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_job_events_job_id ON job_events(job_id)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_events_created ON runtime_events(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_events_type ON runtime_events(event_type)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_events_entity ON runtime_events(entity_type, entity_id)',
        ];

        foreach ($schemaStatements as $statement) {
            $pdo->exec($statement);
        }

        $this->ensureColumn($pdo, 'jobs', 'progress_json', 'TEXT');
        $this->ensureColumn($pdo, 'jobs', 'worker_pid', 'INTEGER');
        $this->ensureColumn($pdo, 'jobs', 'started_at', 'TEXT');
        $this->ensureColumn($pdo, 'jobs', 'finished_at', 'TEXT');
        $this->ensureColumn($pdo, 'jobs', 'last_heartbeat_at', 'TEXT');
        $this->ensureColumn($pdo, 'batches', 'recursive', 'INTEGER NOT NULL DEFAULT 0');
    }

    private function ensureColumn(PDO $pdo, string $tableName, string $columnName, string $definition): void
    {
        $statement = $pdo->query("PRAGMA table_info({$tableName})");
        $columns = $statement->fetchAll();
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === $columnName) {
                return;
            }
        }

        try {
            $pdo->exec("ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$definition}");
        } catch (PDOException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'duplicate column name')) {
                return;
            }

            throw $exception;
        }
    }
}
