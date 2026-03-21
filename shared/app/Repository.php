<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Repository
{
    private PDO $pdo;
    private TemplateEngine $templateEngine;

    public function __construct(PDO $pdo, TemplateEngine $templateEngine)
    {
        $this->pdo = $pdo;
        $this->templateEngine = $templateEngine;
        $this->ensureDefaultTemplate();
    }

    public function listJobs(): array
    {
        $statement = $this->pdo->query(
            'SELECT jobs.*, batches.label AS batch_label
             FROM jobs
             LEFT JOIN batches ON batches.id = jobs.batch_id
             ORDER BY
                 CASE jobs.status
                     WHEN "running" THEN 0
                     WHEN "cancel_requested" THEN 1
                     WHEN "ready" THEN 2
                     WHEN "queued" THEN 3
                     WHEN "paused" THEN 4
                     WHEN "failed" THEN 5
                     WHEN "completed" THEN 6
                     WHEN "cancelled" THEN 7
                     ELSE 8
                 END,
                 jobs.position,
                 jobs.created_at'
        );

        $jobs = [];
        foreach ($statement->fetchAll() as $row) {
            $jobs[] = $this->hydrateJob($row);
        }

        return $jobs;
    }

    public function getJob(string $jobId): ?array
    {
        $statement = $this->pdo->prepare('SELECT jobs.*, batches.label AS batch_label FROM jobs LEFT JOIN batches ON batches.id = jobs.batch_id WHERE jobs.id = :id');
        $statement->execute(['id' => $jobId]);
        $row = $statement->fetch();

        return $row ? $this->hydrateJob($row) : null;
    }

    public function listJobSummaries(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                jobs.id,
                jobs.batch_id,
                batches.label AS batch_label,
                jobs.type,
                jobs.source_path,
                jobs.output_folder,
                jobs.output_file,
                jobs.title,
                jobs.status,
                jobs.position,
                jobs.progress_json,
                jobs.error_text,
                jobs.worker_pid,
                jobs.started_at,
                jobs.finished_at,
                jobs.last_heartbeat_at,
                jobs.created_at,
                jobs.updated_at
             FROM jobs
             LEFT JOIN batches ON batches.id = jobs.batch_id
             ORDER BY
                 CASE jobs.status
                     WHEN "running" THEN 0
                     WHEN "cancel_requested" THEN 1
                     WHEN "ready" THEN 2
                     WHEN "queued" THEN 3
                     WHEN "paused" THEN 4
                     WHEN "failed" THEN 5
                     WHEN "completed" THEN 6
                     WHEN "cancelled" THEN 7
                     ELSE 8
                 END,
                 jobs.position,
                 jobs.created_at
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            fn (array $row): array => $this->hydrateJobSummaryRow($row),
            $statement->fetchAll()
        );
    }

    public function listPendingJobs(int $limit = 25): array
    {
        $statement = $this->pdo->prepare(
            'SELECT jobs.*, batches.label AS batch_label
             FROM jobs
             LEFT JOIN batches ON batches.id = jobs.batch_id
             WHERE jobs.status IN ("queued", "ready")
             ORDER BY jobs.position ASC, jobs.created_at ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            fn (array $row): array => $this->hydrateJob($row),
            $statement->fetchAll()
        );
    }

    public function getJobSummary(string $jobId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                jobs.id,
                jobs.batch_id,
                batches.label AS batch_label,
                jobs.type,
                jobs.source_path,
                jobs.output_folder,
                jobs.output_file,
                jobs.title,
                jobs.status,
                jobs.position,
                jobs.progress_json,
                jobs.error_text,
                jobs.worker_pid,
                jobs.started_at,
                jobs.finished_at,
                jobs.last_heartbeat_at,
                jobs.created_at,
                jobs.updated_at
             FROM jobs
             LEFT JOIN batches ON batches.id = jobs.batch_id
             WHERE jobs.id = :id'
        );
        $statement->execute(['id' => $jobId]);
        $row = $statement->fetch();

        return $row ? $this->hydrateJobSummaryRow($row) : null;
    }

    public function createJob(array $payload): array
    {
        $jobId = bin2hex(random_bytes(16));
        $now = gmdate('c');
        $position = $this->nextPosition();

        $statement = $this->pdo->prepare(
            'INSERT INTO jobs (
                id, batch_id, type, source_path, output_folder, output_file, title, status, position,
                template_id, template_version_id, analysis_json, compiled_plan_json, progress_json, result_json,
                error_text, worker_pid, started_at, finished_at, last_heartbeat_at, created_at, updated_at
            ) VALUES (
                :id, :batch_id, :type, :source_path, :output_folder, :output_file, :title, :status, :position,
                :template_id, :template_version_id, :analysis_json, :compiled_plan_json, :progress_json, :result_json,
                :error_text, :worker_pid, :started_at, :finished_at, :last_heartbeat_at, :created_at, :updated_at
            )'
        );

        $statement->execute([
            'id' => $jobId,
            'batch_id' => $payload['batch_id'] ?? null,
            'type' => $payload['type'] ?? 'video',
            'source_path' => $payload['source_path'],
            'output_folder' => $payload['output_folder'],
            'output_file' => $payload['output_file'],
            'title' => $payload['title'] ?? null,
            'status' => $payload['status'] ?? 'queued',
            'position' => $position,
            'template_id' => $payload['template_id'] ?? null,
            'template_version_id' => $payload['template_version_id'] ?? null,
            'analysis_json' => json_encode($payload['analysis'] ?? null, JSON_PRETTY_PRINT),
            'compiled_plan_json' => json_encode($payload['compiled_plan'] ?? null, JSON_PRETTY_PRINT),
            'progress_json' => json_encode($payload['progress'] ?? null, JSON_PRETTY_PRINT),
            'result_json' => json_encode($payload['result'] ?? null, JSON_PRETTY_PRINT),
            'error_text' => $payload['error_text'] ?? null,
            'worker_pid' => null,
            'started_at' => null,
            'finished_at' => null,
            'last_heartbeat_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->appendEvent($jobId, 'status', [
            'status' => $payload['status'] ?? 'queued',
            'message' => 'Job created',
        ]);

        $job = $this->getJob($jobId) ?? [];
        if ($job !== []) {
            $this->appendRuntimeEvent('job.created', 'job', $jobId, $this->summarizeJob($job));
        }

        if (!empty($payload['batch_id'])) {
            $this->syncBatch((int) $payload['batch_id']);
        }

        return $job;
    }

    public function acquireNextRunnableJob(?int $workerPid = null): ?array
    {
        $jobId = null;

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->query(
                "SELECT id
                 FROM jobs
                 WHERE status IN ('queued', 'ready')
                 ORDER BY position ASC, created_at ASC
                 LIMIT 1"
            );
            $row = $statement->fetch();
            if ($row === false) {
                $this->pdo->commit();
                return null;
            }

            $jobId = (string) $row['id'];
            $update = $this->pdo->prepare(
                'UPDATE jobs
                 SET status = :status,
                     worker_pid = :worker_pid,
                     started_at = COALESCE(started_at, :started_at),
                     finished_at = NULL,
                     last_heartbeat_at = :last_heartbeat_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $now = gmdate('c');
            $update->execute([
                'status' => 'running',
                'worker_pid' => $workerPid,
                'started_at' => $now,
                'last_heartbeat_at' => $now,
                'updated_at' => $now,
                'id' => $jobId,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        if ($jobId === null) {
            return null;
        }

        $this->appendEvent($jobId, 'status', [
            'status' => 'running',
            'message' => 'Worker claimed job',
        ]);

        $job = $this->getJob($jobId);
        if ($job !== null && $job['batch_id'] !== null) {
            $this->syncBatch((int) $job['batch_id']);
        }

        return $job;
    }

    public function recoverInterruptedJobs(): void
    {
        $statement = $this->pdo->query(
            "SELECT id, batch_id, status FROM jobs
             WHERE status IN ('running', 'cancel_requested')
                OR (status = 'paused' AND worker_pid IS NOT NULL)
                OR (status IN ('queued', 'ready', 'failed', 'completed', 'cancelled') AND worker_pid IS NOT NULL)"
        );

        foreach ($statement->fetchAll() as $row) {
            $jobId = (string) $row['id'];
            $status = (string) $row['status'];
            if ($status === 'cancel_requested') {
                $this->updateJobStatus($jobId, 'cancelled', 'Recovered cancelled job after worker restart');
                continue;
            }

            if ($status === 'running') {
                $this->updateJobStatus($jobId, 'queued', 'Worker restarted; job returned to queue');
                continue;
            }

            $this->updateJobFields($jobId, [
                'worker_pid' => null,
                'last_heartbeat_at' => gmdate('c'),
            ]);
        }
    }

    public function updateJobStatus(string $jobId, string $status, ?string $message = null, array $extraFields = []): array
    {
        $job = $this->getJob($jobId);
        if ($job === null) {
            throw new \RuntimeException('Job not found');
        }

        $fields = array_merge($extraFields, [
            'status' => $status,
            'updated_at' => gmdate('c'),
        ]);

        if ($status === 'running') {
            $fields['started_at'] = $job['started_at'] ?? gmdate('c');
            $fields['finished_at'] = null;
        }

        if (in_array($status, ['queued', 'ready', 'paused'], true) && $status === 'queued') {
            $fields['finished_at'] = null;
        }

        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $fields['worker_pid'] = null;
            $fields['finished_at'] = gmdate('c');
        }

        $updatedJob = $this->updateJobFields($jobId, $fields);
        $this->appendEvent($jobId, 'status', [
            'status' => $status,
            'message' => $message ?? "Job moved to {$status}",
        ]);
        $this->appendRuntimeEvent('job.updated', 'job', $jobId, $this->summarizeJob($updatedJob));

        if ($updatedJob['batch_id'] !== null) {
            $this->syncBatch((int) $updatedJob['batch_id']);
        }

        return $updatedJob;
    }

    public function updateJobProgress(string $jobId, array $progress, bool $emitEvent = false): array
    {
        $job = $this->updateJobFields($jobId, [
            'progress' => $progress,
            'last_heartbeat_at' => gmdate('c'),
        ]);

        if ($emitEvent) {
            $this->appendEvent($jobId, 'progress', $progress);
            $this->appendRuntimeEvent('job.updated', 'job', $jobId, $this->summarizeJob($job));
        }

        return $job;
    }

    public function updateJob(string $jobId, array $fields): array
    {
        $job = $this->updateJobFields($jobId, $fields);
        if ($job !== []) {
            $this->appendRuntimeEvent('job.updated', 'job', $jobId, $this->summarizeJob($job));
        }
        if ($job !== [] && $job['batch_id'] !== null) {
            $this->syncBatch((int) $job['batch_id']);
        }

        return $job;
    }

    public function markJobStarted(string $jobId, int $workerPid, array $resultContext = []): array
    {
        $job = $this->getJob($jobId);
        if ($job === null) {
            throw new \RuntimeException('Job not found');
        }

        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $result = array_merge($result, $resultContext);

        return $this->updateJobStatus($jobId, 'running', 'Worker process started', [
            'worker_pid' => $workerPid,
            'result' => $result,
            'progress' => [
                'phase' => 'starting',
                'percent' => 0,
                'speed' => null,
                'eta_seconds' => null,
            ],
            'last_heartbeat_at' => gmdate('c'),
        ]);
    }

    public function markJobCompleted(string $jobId, array $result = []): array
    {
        return $this->updateJobStatus($jobId, 'completed', 'Job completed', [
            'error_text' => null,
            'result' => $result,
            'progress' => [
                'phase' => 'completed',
                'percent' => 100,
                'speed' => null,
                'eta_seconds' => 0,
            ],
        ]);
    }

    public function markJobFailed(string $jobId, string $message, array $result = []): array
    {
        return $this->updateJobStatus($jobId, 'failed', $message, [
            'error_text' => $message,
            'result' => $result,
        ]);
    }

    public function markJobCancelled(string $jobId, array $result = []): array
    {
        return $this->updateJobStatus($jobId, 'cancelled', 'Job cancelled', [
            'error_text' => null,
            'result' => $result,
            'progress' => [
                'phase' => 'cancelled',
                'percent' => null,
                'speed' => null,
                'eta_seconds' => null,
            ],
        ]);
    }

    public function touchWorkerHeartbeat(string $jobId, ?int $workerPid = null): void
    {
        $fields = [
            'last_heartbeat_at' => gmdate('c'),
        ];
        if ($workerPid !== null) {
            $fields['worker_pid'] = $workerPid;
        }
        $this->updateJobFields($jobId, $fields);
    }

    public function moveJob(string $jobId, string $direction): array
    {
        $job = $this->getJob($jobId);
        if ($job === null) {
            throw new \RuntimeException('Job not found');
        }

        if (!in_array($job['status'], ['queued', 'ready', 'paused'], true)) {
            throw new \RuntimeException('Only queued, ready or paused jobs can be moved');
        }

        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        $statement = $this->pdo->prepare(
            "SELECT id, position FROM jobs
             WHERE status IN ('queued', 'ready', 'paused')
               AND position {$operator} :position
             ORDER BY position {$order}
             LIMIT 1"
        );
        $statement->execute(['position' => $job['position']]);
        $swapJob = $statement->fetch();

        if ($swapJob === false) {
            return $job;
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE jobs SET position = :position, updated_at = :updated_at WHERE id = :id');
            $now = gmdate('c');
            $update->execute([
                'position' => $swapJob['position'],
                'updated_at' => $now,
                'id' => $job['id'],
            ]);
            $update->execute([
                'position' => $job['position'],
                'updated_at' => $now,
                'id' => $swapJob['id'],
            ]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        $this->appendEvent($jobId, 'position', [
            'direction' => $direction,
            'message' => "Job moved {$direction}",
        ]);
        $updatedJob = $this->getJob($jobId) ?? [];
        if ($updatedJob !== []) {
            $this->appendRuntimeEvent('job.updated', 'job', $jobId, $this->summarizeJob($updatedJob));
        }
        if ($swapJob !== false) {
            $swapSummary = $this->getJobSummary((string) $swapJob['id']);
            if ($swapSummary !== null) {
                $this->appendRuntimeEvent('job.updated', 'job', (string) $swapJob['id'], $swapSummary);
            }
        }

        return $updatedJob;
    }

    public function deleteJob(string $jobId): void
    {
        $job = $this->getJob($jobId);
        if ($job === null) {
            return;
        }

        if (in_array($job['status'], ['running', 'cancel_requested'], true)) {
            throw new \RuntimeException('Active jobs must be cancelled before deletion');
        }

        $statement = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id');
        $statement->execute(['id' => $jobId]);
        $this->appendRuntimeEvent('job.deleted', 'job', $jobId, [
            'id' => $jobId,
        ]);

        if ($job['batch_id'] !== null) {
            $this->syncBatch((int) $job['batch_id']);
        }
    }

    public function retryJob(string $jobId): array
    {
        $job = $this->getJob($jobId);
        if ($job === null) {
            throw new \RuntimeException('Job not found');
        }

        if (!in_array($job['status'], ['failed', 'cancelled'], true)) {
            throw new \RuntimeException('Only failed or cancelled jobs can be retried');
        }

        return $this->updateJobStatus($jobId, 'queued', 'Job retried', [
            'error_text' => null,
            'progress' => null,
            'result' => null,
            'worker_pid' => null,
            'finished_at' => null,
        ]);
    }

    public function performJobAction(string $jobId, string $action, array $payload = []): ?array
    {
        $job = $this->getJob($jobId);
        if ($job === null) {
            throw new \RuntimeException('Job not found');
        }

        return match ($action) {
            'pause' => $this->updateJobStatus($jobId, 'paused', 'Pause requested'),
            'resume' => $this->updateJobStatus($jobId, 'queued', 'Resume requested'),
            'cancel' => in_array($job['status'], ['running', 'paused'], true)
                ? $this->updateJobStatus($jobId, 'cancel_requested', 'Cancel requested')
                : $this->markJobCancelled($jobId, $job['result'] ?? []),
            'retry' => $this->retryJob($jobId),
            'move_up' => $this->moveJob($jobId, 'up'),
            'move_down' => $this->moveJob($jobId, 'down'),
            'delete' => $this->deleteJob($jobId),
            default => throw new \RuntimeException("Unsupported job action: {$action}"),
        };
    }

    public function createBatch(
        string $label,
        string $sourceFolder,
        bool $recursive,
        string $outputFolder,
        ?int $templateId,
        ?int $templateVersionId,
        array $jobPayloads
    ): array
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare(
            'INSERT INTO batches (
                label, source_folder, recursive, output_folder, template_id, template_version_id, status, summary_json, created_at, updated_at
            ) VALUES (
                :label, :source_folder, :recursive, :output_folder, :template_id, :template_version_id, :status, :summary_json, :created_at, :updated_at
            )'
        );
        $statement->execute([
            'label' => $label,
            'source_folder' => $sourceFolder,
            'recursive' => $recursive ? 1 : 0,
            'output_folder' => $outputFolder,
            'template_id' => $templateId,
            'template_version_id' => $templateVersionId,
            'status' => 'queued',
            'summary_json' => json_encode(['job_count' => count($jobPayloads)], JSON_PRETTY_PRINT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $batchId = (int) $this->pdo->lastInsertId();
        foreach ($jobPayloads as $payload) {
            $payload['batch_id'] = $batchId;
            $this->createJob($payload);
        }

        $this->syncBatch($batchId);
        $batchSummary = $this->getBatchSummary($batchId);
        if ($batchSummary !== null) {
            $this->appendRuntimeEvent('batch.created', 'batch', (string) $batchId, $batchSummary);
        }

        return $this->getBatch($batchId) ?? [];
    }

    public function listBatches(): array
    {
        $statement = $this->pdo->query('SELECT id FROM batches ORDER BY created_at DESC');
        $batches = [];

        foreach ($statement->fetchAll() as $row) {
            $batch = $this->getBatch((int) $row['id']);
            if ($batch !== null) {
                $batches[] = $batch;
            }
        }

        return $batches;
    }

    public function listBatchSummaries(int $limit = 20, ?int $beforeId = null): array
    {
        $sql = 'SELECT id, label, source_folder, recursive, output_folder, status, summary_json, created_at, updated_at
                FROM batches';
        $params = [];

        if ($beforeId !== null && $beforeId > 0) {
            $sql .= ' WHERE id < :before_id';
            $params['before_id'] = $beforeId;
        }

        $sql .= ' ORDER BY id DESC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            fn (array $row): array => $this->hydrateBatchSummaryRow($row),
            $statement->fetchAll()
        );
    }

    public function getBatchSummary(int $batchId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, label, source_folder, recursive, output_folder, status, summary_json, created_at, updated_at
             FROM batches
             WHERE id = :id'
        );
        $statement->execute(['id' => $batchId]);
        $row = $statement->fetch();

        return $row ? $this->hydrateBatchSummaryRow($row) : null;
    }

    public function getBatch(int $batchId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM batches WHERE id = :id');
        $statement->execute(['id' => $batchId]);
        $batch = $statement->fetch();
        if ($batch === false) {
            return null;
        }

        $jobStatement = $this->pdo->prepare('SELECT jobs.*, batches.label AS batch_label FROM jobs LEFT JOIN batches ON batches.id = jobs.batch_id WHERE batch_id = :batch_id ORDER BY position');
        $jobStatement->execute(['batch_id' => $batchId]);
        $jobs = array_map(fn (array $row): array => $this->hydrateJob($row), $jobStatement->fetchAll());

        $aggregate = $this->buildBatchAggregate($jobs);
        $summary = [
            'job_count' => count($jobs),
            'queued' => $aggregate['counts']['queued'] ?? 0,
            'running' => $aggregate['counts']['running'] ?? 0,
            'paused' => $aggregate['counts']['paused'] ?? 0,
            'failed' => $aggregate['counts']['failed'] ?? 0,
            'completed' => $aggregate['counts']['completed'] ?? 0,
            'cancelled' => $aggregate['counts']['cancelled'] ?? 0,
            'cancel_requested' => $aggregate['counts']['cancel_requested'] ?? 0,
            'ready' => $aggregate['counts']['ready'] ?? 0,
        ];

        if ($batch['status'] !== $aggregate['status'] || (string) ($batch['summary_json'] ?? '') !== json_encode($summary, JSON_PRETTY_PRINT)) {
            $this->updateBatchFields($batchId, [
                'status' => $aggregate['status'],
                'summary' => $summary,
            ]);
            $batch['status'] = $aggregate['status'];
            $batch['summary_json'] = json_encode($summary, JSON_PRETTY_PRINT);
        }

        return [
            'id' => (int) $batch['id'],
            'label' => $batch['label'],
            'source_folder' => $batch['source_folder'],
            'recursive' => !empty($batch['recursive']),
            'output_folder' => $batch['output_folder'],
            'status' => $aggregate['status'],
            'summary' => $summary,
            'jobs' => $jobs,
            'created_at' => $batch['created_at'],
            'updated_at' => $batch['updated_at'],
        ];
    }

    public function deleteBatch(int $batchId): void
    {
        $batch = $this->getBatch($batchId);
        if ($batch === null) {
            return;
        }

        foreach ($batch['jobs'] as $job) {
            if (in_array($job['status'], ['running', 'cancel_requested'], true)) {
                throw new \RuntimeException('Active batches must be cancelled before deletion');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $jobDelete = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id');
            foreach ($batch['jobs'] as $job) {
                $jobDelete->execute(['id' => $job['id']]);
            }

            $batchDelete = $this->pdo->prepare('DELETE FROM batches WHERE id = :id');
            $batchDelete->execute(['id' => $batchId]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        foreach ($batch['jobs'] as $job) {
            $this->appendRuntimeEvent('job.deleted', 'job', (string) $job['id'], [
                'id' => $job['id'],
            ]);
        }

        $this->appendRuntimeEvent('batch.deleted', 'batch', (string) $batchId, [
            'id' => $batchId,
        ]);
    }

    public function listTemplates(): array
    {
        $statement = $this->pdo->query(
            'SELECT templates.*, template_versions.version AS published_version
             FROM templates
             LEFT JOIN template_versions ON template_versions.id = templates.published_version_id
             ORDER BY templates.name'
        );

        return $statement->fetchAll();
    }

    public function getTemplate(int $templateId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM templates WHERE id = :id');
        $statement->execute(['id' => $templateId]);
        $template = $statement->fetch();
        if ($template === false) {
            return null;
        }

        $versionStatement = $this->pdo->prepare(
            'SELECT * FROM template_versions WHERE template_id = :template_id ORDER BY version DESC'
        );
        $versionStatement->execute(['template_id' => $templateId]);
        $versions = [];
        foreach ($versionStatement->fetchAll() as $row) {
            $row['schema'] = json_decode((string) $row['schema_json'], true);
            unset($row['schema_json']);
            $versions[] = $row;
        }

        $template['versions'] = $versions;
        return $template;
    }

    public function getPublishedTemplate(?int $templateId = null): array
    {
        if ($templateId === null) {
            $statement = $this->pdo->query(
                'SELECT templates.id
                 FROM templates
                 ORDER BY CASE WHEN published_version_id IS NOT NULL THEN 0 ELSE 1 END, id
                 LIMIT 1'
            );
            $row = $statement->fetch();
            if ($row === false) {
                throw new \RuntimeException('No templates configured');
            }
            $templateId = (int) $row['id'];
        }

        $template = $this->getTemplate($templateId);
        if ($template === null) {
            throw new \RuntimeException('Template not found');
        }

        $publishedVersionId = $template['published_version_id'] ?? null;
        foreach ($template['versions'] as $version) {
            if ((int) $version['id'] === (int) $publishedVersionId) {
                return [
                    'template' => $template,
                    'version' => $version,
                ];
            }
        }

        if (!empty($template['versions'])) {
            return [
                'template' => $template,
                'version' => $template['versions'][0],
            ];
        }

        throw new \RuntimeException('Template has no versions');
    }

    public function createTemplate(array $payload): array
    {
        $slug = $payload['slug'] ?? $this->slugify((string) ($payload['name'] ?? 'template'));
        $name = (string) ($payload['name'] ?? 'Unnamed template');
        $description = (string) ($payload['description'] ?? '');
        $schema = $payload['schema'] ?? $this->templateEngine->buildDefaultSchema();
        $this->templateEngine->validateSchema($schema);
        $now = gmdate('c');

        $statement = $this->pdo->prepare(
            'INSERT INTO templates (slug, name, description, published_version_id, created_at, updated_at) VALUES (:slug, :name, :description, :published_version_id, :created_at, :updated_at)'
        );
        $statement->execute([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'published_version_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $templateId = (int) $this->pdo->lastInsertId();
        $versionId = $this->insertTemplateVersion($templateId, 1, 'draft', $schema);

        if (!empty($payload['publish'])) {
            $this->publishTemplate($templateId, $versionId);
        }

        $template = $this->getTemplate($templateId) ?? [];
        $this->appendRuntimeEvent('templates.changed', 'templates', 'templates', [
            'template_id' => $templateId,
            'action' => 'created',
        ]);

        return $template;
    }

    public function updateTemplate(int $templateId, array $payload): array
    {
        $template = $this->getTemplate($templateId);
        if ($template === null) {
            throw new \RuntimeException('Template not found');
        }

        $newVersion = (int) max(array_map(static fn (array $row): int => (int) $row['version'], $template['versions'] ?: [['version' => 0]])) + 1;
        $schema = $payload['schema'] ?? ($template['versions'][0]['schema'] ?? $this->templateEngine->buildDefaultSchema());
        $this->templateEngine->validateSchema($schema);
        $versionId = $this->insertTemplateVersion($templateId, $newVersion, 'draft', $schema);

        if (isset($payload['name']) || isset($payload['description'])) {
            $statement = $this->pdo->prepare(
                'UPDATE templates SET name = :name, description = :description, updated_at = :updated_at WHERE id = :id'
            );
            $statement->execute([
                'name' => (string) ($payload['name'] ?? $template['name']),
                'description' => (string) ($payload['description'] ?? $template['description']),
                'updated_at' => gmdate('c'),
                'id' => $templateId,
            ]);
        }

        if (!empty($payload['publish'])) {
            $this->publishTemplate($templateId, $versionId);
        }

        $template = $this->getTemplate($templateId) ?? [];
        $this->appendRuntimeEvent('templates.changed', 'templates', 'templates', [
            'template_id' => $templateId,
            'action' => 'updated',
        ]);

        return $template;
    }

    public function publishTemplate(int $templateId, int $versionId): array
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare(
            'UPDATE template_versions SET status = :status, published_at = :published_at WHERE id = :id AND template_id = :template_id'
        );
        $statement->execute([
            'status' => 'published',
            'published_at' => $now,
            'id' => $versionId,
            'template_id' => $templateId,
        ]);

        $templateStatement = $this->pdo->prepare(
            'UPDATE templates SET published_version_id = :published_version_id, updated_at = :updated_at WHERE id = :id'
        );
        $templateStatement->execute([
            'published_version_id' => $versionId,
            'updated_at' => $now,
            'id' => $templateId,
        ]);

        $template = $this->getTemplate($templateId) ?? [];
        $this->appendRuntimeEvent('templates.changed', 'templates', 'templates', [
            'template_id' => $templateId,
            'action' => 'published',
            'version_id' => $versionId,
        ]);

        return $template;
    }

    public function listEventsAfter(int $afterId, int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM job_events WHERE id > :after_id ORDER BY id ASC LIMIT :limit'
        );
        $statement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $events = [];
        foreach ($statement->fetchAll() as $row) {
            $events[] = [
                'id' => (int) $row['id'],
                'job_id' => $row['job_id'],
                'topic' => $row['topic'],
                'payload' => json_decode((string) $row['payload_json'], true),
                'created_at' => $row['created_at'],
            ];
        }

        return $events;
    }

    public function listRuntimeEventsAfter(int $afterId, int $limit = 100, string $scope = 'dashboard'): array
    {
        $allowedTypes = $this->runtimeEventTypesForScope($scope);
        $placeholders = [];
        foreach ($allowedTypes as $index => $eventType) {
            $placeholders[] = ':event_type_' . $index;
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM runtime_events
             WHERE id > :after_id
               AND event_type IN (' . implode(', ', $placeholders) . ')
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        foreach ($allowedTypes as $index => $eventType) {
            $statement->bindValue(':event_type_' . $index, $eventType, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $events = [];
        foreach ($statement->fetchAll() as $row) {
            $events[] = [
                'id' => (int) $row['id'],
                'type' => $row['event_type'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'payload' => json_decode((string) $row['payload_json'], true),
                'created_at' => $row['created_at'],
            ];
        }

        return $events;
    }

    public function latestRuntimeEventId(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(MAX(id), 0) AS latest_id FROM runtime_events');
        $row = $statement->fetch();

        return (int) ($row['latest_id'] ?? 0);
    }

    public function appendEvent(string $jobId, string $topic, array $payload): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO job_events (job_id, topic, payload_json, created_at) VALUES (:job_id, :topic, :payload_json, :created_at)'
        );
        $statement->execute([
            'job_id' => $jobId,
            'topic' => $topic,
            'payload_json' => json_encode($payload, JSON_PRETTY_PRINT),
            'created_at' => gmdate('c'),
        ]);
    }

    public function appendRuntimeEvent(string $eventType, string $entityType, ?string $entityId, array $payload): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO runtime_events (event_type, entity_type, entity_id, payload_json, created_at)
             VALUES (:event_type, :entity_type, :entity_id, :payload_json, :created_at)'
        );
        $statement->execute([
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload_json' => json_encode($payload, JSON_PRETTY_PRINT),
            'created_at' => gmdate('c'),
        ]);

        $eventId = (int) $this->pdo->lastInsertId();
        $this->trimRuntimeEvents($eventId);

        return $eventId;
    }

    public function getSetting(string $keyName, mixed $default = null): mixed
    {
        $statement = $this->pdo->prepare('SELECT value_json FROM settings WHERE key_name = :key_name');
        $statement->execute(['key_name' => $keyName]);
        $row = $statement->fetch();
        if ($row === false) {
            return $default;
        }

        return json_decode((string) $row['value_json'], true);
    }

    public function setSetting(string $keyName, mixed $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key_name, value_json, updated_at)
             VALUES (:key_name, :value_json, :updated_at)
             ON CONFLICT(key_name) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );
        $statement->execute([
            'key_name' => $keyName,
            'value_json' => json_encode($value, JSON_PRETTY_PRINT),
            'updated_at' => gmdate('c'),
        ]);
    }

    private function nextPosition(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(MAX(position), 0) + 1 AS next_position FROM jobs');
        $row = $statement->fetch();

        return (int) ($row['next_position'] ?? 1);
    }

    private function hydrateJob(array $row): array
    {
        return [
            'id' => $row['id'],
            'batch_id' => isset($row['batch_id']) ? (int) $row['batch_id'] : null,
            'batch_label' => $row['batch_label'] ?? null,
            'type' => $row['type'],
            'source_path' => $row['source_path'],
            'output_folder' => $row['output_folder'],
            'output_file' => $row['output_file'],
            'title' => $row['title'],
            'status' => $row['status'],
            'position' => (int) $row['position'],
            'template_id' => isset($row['template_id']) ? (int) $row['template_id'] : null,
            'template_version_id' => isset($row['template_version_id']) ? (int) $row['template_version_id'] : null,
            'analysis' => json_decode((string) ($row['analysis_json'] ?? 'null'), true),
            'compiled_plan' => json_decode((string) ($row['compiled_plan_json'] ?? 'null'), true),
            'progress' => json_decode((string) ($row['progress_json'] ?? 'null'), true),
            'result' => json_decode((string) ($row['result_json'] ?? 'null'), true),
            'error_text' => $row['error_text'],
            'worker_pid' => isset($row['worker_pid']) ? (int) $row['worker_pid'] : null,
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'last_heartbeat_at' => $row['last_heartbeat_at'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function hydrateJobSummaryRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'batch_id' => isset($row['batch_id']) ? (int) $row['batch_id'] : null,
            'batch_label' => $row['batch_label'] ?? null,
            'type' => $row['type'],
            'source_path' => $row['source_path'],
            'output_folder' => $row['output_folder'],
            'output_file' => $row['output_file'],
            'title' => $row['title'],
            'status' => $row['status'],
            'position' => (int) $row['position'],
            'progress' => json_decode((string) ($row['progress_json'] ?? 'null'), true),
            'error_text' => $row['error_text'],
            'worker_pid' => isset($row['worker_pid']) ? (int) $row['worker_pid'] : null,
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'last_heartbeat_at' => $row['last_heartbeat_at'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function summarizeJob(array $job): array
    {
        return [
            'id' => $job['id'],
            'batch_id' => $job['batch_id'] ?? null,
            'batch_label' => $job['batch_label'] ?? null,
            'type' => $job['type'] ?? 'video',
            'source_path' => $job['source_path'] ?? null,
            'output_folder' => $job['output_folder'] ?? null,
            'output_file' => $job['output_file'] ?? null,
            'title' => $job['title'] ?? null,
            'status' => $job['status'] ?? null,
            'position' => isset($job['position']) ? (int) $job['position'] : null,
            'progress' => is_array($job['progress'] ?? null) ? $job['progress'] : null,
            'error_text' => $job['error_text'] ?? null,
            'worker_pid' => isset($job['worker_pid']) ? (int) $job['worker_pid'] : null,
            'started_at' => $job['started_at'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
            'last_heartbeat_at' => $job['last_heartbeat_at'] ?? null,
            'created_at' => $job['created_at'] ?? null,
            'updated_at' => $job['updated_at'] ?? null,
        ];
    }

    private function hydrateBatchSummaryRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'label' => $row['label'],
            'source_folder' => $row['source_folder'],
            'recursive' => !empty($row['recursive']),
            'output_folder' => $row['output_folder'],
            'status' => $row['status'],
            'summary' => json_decode((string) ($row['summary_json'] ?? 'null'), true) ?? [],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function ensureDefaultTemplate(): void
    {
        $statement = $this->pdo->query('SELECT COUNT(*) AS template_count FROM templates');
        $row = $statement->fetch();
        if ((int) ($row['template_count'] ?? 0) > 0) {
            return;
        }

        $template = $this->createTemplate([
            'slug' => 'legacy-default',
            'name' => 'Legacy Default',
            'description' => 'Bootstrapped from the legacy decision template.',
            'schema' => $this->templateEngine->buildDefaultSchema(),
            'publish' => true,
        ]);

        if (empty($template)) {
            throw new \RuntimeException('Unable to seed default template');
        }
    }

    private function insertTemplateVersion(int $templateId, int $version, string $status, array $schema): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO template_versions (template_id, version, status, schema_json, created_at, published_at)
             VALUES (:template_id, :version, :status, :schema_json, :created_at, :published_at)'
        );
        $statement->execute([
            'template_id' => $templateId,
            'version' => $version,
            'status' => $status,
            'schema_json' => json_encode($schema, JSON_PRETTY_PRINT),
            'created_at' => gmdate('c'),
            'published_at' => $status === 'published' ? gmdate('c') : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function updateJobFields(string $jobId, array $fields): array
    {
        $columnMap = [
            'batch_id' => 'batch_id',
            'type' => 'type',
            'source_path' => 'source_path',
            'output_folder' => 'output_folder',
            'output_file' => 'output_file',
            'title' => 'title',
            'status' => 'status',
            'position' => 'position',
            'template_id' => 'template_id',
            'template_version_id' => 'template_version_id',
            'analysis' => 'analysis_json',
            'compiled_plan' => 'compiled_plan_json',
            'progress' => 'progress_json',
            'result' => 'result_json',
            'error_text' => 'error_text',
            'worker_pid' => 'worker_pid',
            'started_at' => 'started_at',
            'finished_at' => 'finished_at',
            'last_heartbeat_at' => 'last_heartbeat_at',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];

        if (!isset($fields['updated_at'])) {
            $fields['updated_at'] = gmdate('c');
        }

        $assignments = [];
        $params = ['id' => $jobId];

        foreach ($fields as $field => $value) {
            if (!isset($columnMap[$field])) {
                continue;
            }

            $columnName = $columnMap[$field];
            $paramName = 'field_' . $field;
            $assignments[] = "{$columnName} = :{$paramName}";
            $params[$paramName] = in_array($field, ['analysis', 'compiled_plan', 'progress', 'result'], true)
                ? json_encode($value, JSON_PRETTY_PRINT)
                : $value;
        }

        if ($assignments === []) {
            return $this->getJob($jobId) ?? [];
        }

        $statement = $this->pdo->prepare(
            'UPDATE jobs SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($params);

        return $this->getJob($jobId) ?? [];
    }

    private function buildBatchAggregate(array $jobs): array
    {
        $counts = [];
        foreach ($jobs as $job) {
            $counts[$job['status']] = ($counts[$job['status']] ?? 0) + 1;
        }

        $jobCount = count($jobs);
        $status = 'queued';
        if ($jobCount === 0) {
            $status = 'queued';
        } elseif (($counts['running'] ?? 0) > 0) {
            $status = 'running';
        } elseif (($counts['cancel_requested'] ?? 0) > 0) {
            $status = 'cancel_requested';
        } elseif (($counts['failed'] ?? 0) > 0) {
            $status = 'failed';
        } elseif (($counts['paused'] ?? 0) > 0 && (($counts['queued'] ?? 0) + ($counts['ready'] ?? 0) + ($counts['running'] ?? 0) === 0)) {
            $status = 'paused';
        } elseif (($counts['completed'] ?? 0) === $jobCount) {
            $status = 'completed';
        } elseif (($counts['cancelled'] ?? 0) === $jobCount) {
            $status = 'cancelled';
        } elseif (($counts['queued'] ?? 0) > 0 || ($counts['ready'] ?? 0) > 0) {
            $status = 'queued';
        }

        return [
            'status' => $status,
            'counts' => $counts,
        ];
    }

    private function syncBatch(int $batchId): void
    {
        $batch = $this->getBatchSummary($batchId);
        if ($batch === null) {
            return;
        }

        $aggregate = $this->buildBatchAggregateFromQuery($batchId);
        $summary = [
            'job_count' => $aggregate['job_count'],
            'queued' => $aggregate['counts']['queued'] ?? 0,
            'running' => $aggregate['counts']['running'] ?? 0,
            'paused' => $aggregate['counts']['paused'] ?? 0,
            'failed' => $aggregate['counts']['failed'] ?? 0,
            'completed' => $aggregate['counts']['completed'] ?? 0,
            'cancelled' => $aggregate['counts']['cancelled'] ?? 0,
            'cancel_requested' => $aggregate['counts']['cancel_requested'] ?? 0,
            'ready' => $aggregate['counts']['ready'] ?? 0,
        ];

        $summaryJson = json_encode($summary, JSON_PRETTY_PRINT);
        $currentSummaryJson = json_encode($batch['summary'] ?? [], JSON_PRETTY_PRINT);
        if ($batch['status'] === $aggregate['status'] && $currentSummaryJson === $summaryJson) {
            return;
        }

        $this->updateBatchFields($batchId, [
            'status' => $aggregate['status'],
            'summary' => $summary,
        ]);

        $updated = $this->getBatchSummary($batchId);
        if ($updated !== null) {
            $this->appendRuntimeEvent('batch.updated', 'batch', (string) $batchId, $updated);
        }
    }

    private function updateBatchFields(int $batchId, array $fields): void
    {
        $columnMap = [
            'label' => 'label',
            'source_folder' => 'source_folder',
            'output_folder' => 'output_folder',
            'template_id' => 'template_id',
            'template_version_id' => 'template_version_id',
            'status' => 'status',
            'summary' => 'summary_json',
            'updated_at' => 'updated_at',
        ];

        if (!isset($fields['updated_at'])) {
            $fields['updated_at'] = gmdate('c');
        }

        $assignments = [];
        $params = ['id' => $batchId];
        foreach ($fields as $field => $value) {
            if (!isset($columnMap[$field])) {
                continue;
            }

            $paramName = 'field_' . $field;
            $assignments[] = $columnMap[$field] . " = :{$paramName}";
            $params[$paramName] = $field === 'summary'
                ? json_encode($value, JSON_PRETTY_PRINT)
                : $value;
        }

        if ($assignments === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE batches SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($params);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'template';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'template';
    }

    private function buildBatchAggregateFromQuery(int $batchId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS item_count
             FROM jobs
             WHERE batch_id = :batch_id
             GROUP BY status'
        );
        $statement->execute(['batch_id' => $batchId]);

        $counts = [];
        $jobCount = 0;
        foreach ($statement->fetchAll() as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['item_count'] ?? 0);
            if ($status === '') {
                continue;
            }
            $counts[$status] = $count;
            $jobCount += $count;
        }

        $status = 'queued';
        if ($jobCount === 0) {
            $status = 'queued';
        } elseif (($counts['running'] ?? 0) > 0) {
            $status = 'running';
        } elseif (($counts['cancel_requested'] ?? 0) > 0) {
            $status = 'cancel_requested';
        } elseif (($counts['failed'] ?? 0) > 0) {
            $status = 'failed';
        } elseif (($counts['paused'] ?? 0) > 0 && (($counts['queued'] ?? 0) + ($counts['ready'] ?? 0) + ($counts['running'] ?? 0) === 0)) {
            $status = 'paused';
        } elseif (($counts['completed'] ?? 0) === $jobCount) {
            $status = 'completed';
        } elseif (($counts['cancelled'] ?? 0) === $jobCount) {
            $status = 'cancelled';
        } elseif (($counts['queued'] ?? 0) > 0 || ($counts['ready'] ?? 0) > 0) {
            $status = 'queued';
        }

        return [
            'status' => $status,
            'job_count' => $jobCount,
            'counts' => $counts,
        ];
    }

    private function runtimeEventTypesForScope(string $scope): array
    {
        return match ($scope) {
            'batch' => [
                'worker.updated',
                'batch.created',
                'batch.updated',
                'batch.deleted',
            ],
            default => [
                'worker.updated',
                'job.created',
                'job.updated',
                'job.deleted',
                'batch.created',
                'batch.updated',
                'batch.deleted',
            ],
        };
    }

    private function trimRuntimeEvents(int $latestId): void
    {
        if ($latestId <= 0 || ($latestId % 100) !== 0) {
            return;
        }

        $cutoff = $latestId - 20000;
        if ($cutoff <= 0) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM runtime_events WHERE id <= :cutoff');
        $statement->execute(['cutoff' => $cutoff]);
    }
}
