<?php

declare(strict_types=1);

namespace App;

final class Runtime
{
    private Config $config;
    private ?Database $database = null;
    private ?PathService $paths = null;
    private ?AuthService $auth = null;
    private ?MediaAnalyzer $analyzer = null;
    private ?ArchiveAnalyzer $archiveAnalyzer = null;
    private ?BrowserService $browser = null;
    private ?TemplateEngine $templateEngine = null;
    private ?Repository $repository = null;
    private ?WorkspaceService $workspace = null;
    private ?BatchService $batch = null;

    private function __construct()
    {
        $this->config = new Config();
    }

    public static function boot(): self
    {
        return new self();
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function database(): Database
    {
        if ($this->database === null) {
            $this->database = new Database($this->config);
        }

        return $this->database;
    }

    public function paths(): PathService
    {
        if ($this->paths === null) {
            $this->paths = new PathService($this->config);
        }

        return $this->paths;
    }

    public function auth(): AuthService
    {
        if ($this->auth === null) {
            $this->auth = new AuthService($this->config);
        }

        return $this->auth;
    }

    public function analyzer(): MediaAnalyzer
    {
        if ($this->analyzer === null) {
            $this->analyzer = new MediaAnalyzer($this->config, $this->paths());
        }

        return $this->analyzer;
    }

    public function archiveAnalyzer(): ArchiveAnalyzer
    {
        if ($this->archiveAnalyzer === null) {
            $this->archiveAnalyzer = new ArchiveAnalyzer($this->config, $this->paths());
        }

        return $this->archiveAnalyzer;
    }

    public function browser(): BrowserService
    {
        if ($this->browser === null) {
            $this->browser = new BrowserService($this->config, $this->paths());
        }

        return $this->browser;
    }

    public function templateEngine(): TemplateEngine
    {
        if ($this->templateEngine === null) {
            $this->templateEngine = new TemplateEngine($this->config);
        }

        return $this->templateEngine;
    }

    public function repository(): Repository
    {
        if ($this->repository === null) {
            $this->repository = new Repository($this->database()->connection(), $this->templateEngine());
        }

        return $this->repository;
    }

    public function workspace(): WorkspaceService
    {
        if ($this->workspace === null) {
            $this->workspace = new WorkspaceService(
                $this->config,
                $this->paths(),
                $this->analyzer(),
                $this->archiveAnalyzer(),
                $this->templateEngine(),
                $this->repository()
            );
        }

        return $this->workspace;
    }

    public function batch(): BatchService
    {
        if ($this->batch === null) {
            $this->batch = new BatchService(
                $this->paths(),
                $this->analyzer(),
                $this->templateEngine(),
                $this->repository(),
                $this->workspace()
            );
        }

        return $this->batch;
    }
}
