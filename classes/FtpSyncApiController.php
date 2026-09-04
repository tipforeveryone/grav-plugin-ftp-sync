<?php

declare(strict_types=1);

namespace Grav\Plugin\FtpSync;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin2 backend for FTP Sync.
 *
 * A thin REST wrapper around SyncManager's existing job-queue API — every
 * method here just forwards to the SAME public methods the admin-classic
 * onAdminTaskExecute handlers call (see FTPSyncPlugin::handle*() for the
 * originals), so the sync/diff/backup engine itself is untouched. Only the
 * transport changed: FormData + task name + admin-nonce -> JSON body +
 * REST route + Bearer token.
 *
 * Gate: same as classic-admin's guardRequest() — admin.super there becomes
 * api.super here, and isEnabled() (local env, or force_allow_remote) is
 * still enforced identically, since the FTP credentials must never be
 * exercised from a live hosting copy of this plugin.
 */
class FtpSyncApiController extends AbstractApiController
{
    /**
     * GET /ftp-sync/status — local-env / enabled / backup-path info the
     * page component needs before it decides what to render (mirrors the
     * ftp_sync_is_local() / ftp_sync_is_enabled() / ftp_sync_backup_path()
     * Twig functions + data-is-local attribute the classic template used).
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        return ApiResponse::create([
            'is_local' => $this->isLocalEnvironment(),
            'is_enabled' => $this->isEnabled(),
            'backup_path' => 'user/data/ftp-sync/backups',
        ]);
    }

    public function listBackups(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        return ApiResponse::create(['backups' => $this->run(fn (SyncManager $sm) => $sm->listBackups())]);
    }

    public function deleteBackup(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $name = (string) $this->getRouteParam($request, 'name');
        $this->run(fn (SyncManager $sm) => $sm->deleteBackup($name));

        return ApiResponse::noContent();
    }

    public function startCheckDiff(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $body = $this->getRequestBody($request);
        $kinds = array_values((array) ($body['kinds'] ?? []));

        return ApiResponse::create($this->run(fn (SyncManager $sm) => $sm->startCheckDiffJob($kinds)));
    }

    public function stepCheckDiff(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');

        return ApiResponse::create($this->run(fn (SyncManager $sm) => $sm->stepCheckDiffJob($jobId)));
    }

    public function startSync(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $body = $this->getRequestBody($request);
        $resolutions = (array) ($body['resolutions'] ?? []);

        return ApiResponse::create($this->run(fn (SyncManager $sm) => $sm->startSyncJob($resolutions)));
    }

    public function stepSync(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');

        return ApiResponse::create($this->run(fn (SyncManager $sm) => $sm->stepSyncJob($jobId)));
    }

    public function startFullDeploy(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        return ApiResponse::create($this->run(fn (SyncManager $sm) => $sm->startFullDeployJob()));
    }

    public function stepFullDeploy(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');

        return ApiResponse::create($this->run(fn (SyncManager $sm) => $sm->stepFullDeployJob($jobId)));
    }

    public function cancelFullDeploy(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');
        $this->run(fn (SyncManager $sm) => $sm->cancelFullDeployJob($jobId));

        return ApiResponse::create(['status' => 'ok']);
    }

    public function markFullDeploySynced(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        return ApiResponse::create($this->run(fn (SyncManager $sm) => $sm->markFullDeploySynced()));
    }

    /** Same two-part gate as classic-admin's guardRequest(): api.super, and the local/force_allow_remote check. */
    private function guard(ServerRequestInterface $request): void
    {
        $this->requireSuper($request);

        if (!$this->isEnabled()) {
            throw new ValidationException('FTP Sync is disabled: this is not a local development environment.');
        }
    }

    /**
     * Every SyncManager job method throws plain \RuntimeException for
     * foreseeable, user-actionable failures (wrong FTP username/password, job
     * expired, nothing selected...) — see FtpClient::connect() and
     * SyncManager's job methods. Left uncaught, those bubble past this
     * controller as an "unhandled exception", which the API's global handler
     * intentionally redacts into a bare 500 "Internal Server Error" (correct
     * behavior for a genuine bug, but it also swallowed the real, helpful
     * message for these expected cases — the admin2 page then only had a
     * generic "Request failed (500)" to show, no matter how good the
     * exception message was). Converting to ValidationException here
     * preserves the real message through to the response's `detail` field.
     *
     * ApiException (ValidationException included) also extends
     * \RuntimeException, so it's checked first and rethrown as-is —
     * otherwise a legitimate ApiException thrown deeper down would get
     * needlessly double-wrapped.
     *
     * @template T
     * @param callable(SyncManager): T $fn
     * @return T
     */
    private function run(callable $fn)
    {
        try {
            return $fn($this->syncManager());
        } catch (\Grav\Plugin\Api\Exceptions\ApiException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new ValidationException($e->getMessage());
        }
    }

    private function isLocalEnvironment(): bool
    {
        return is_dir(GRAV_ROOT . '/.ddev');
    }

    private function isEnabled(): bool
    {
        return $this->isLocalEnvironment() || (bool) $this->config->get('plugins.ftp-sync.force_allow_remote', false);
    }

    private function syncManager(): SyncManager
    {
        $config = (array) $this->config->get('plugins.ftp-sync');
        $config['active_theme'] = (string) $this->config->get('system.pages.theme', '');

        return new SyncManager($config, DATA_DIR . 'ftp-sync');
    }
}
