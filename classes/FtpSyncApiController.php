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

        return ApiResponse::create(['backups' => $this->syncManager()->listBackups()]);
    }

    public function deleteBackup(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $name = (string) $this->getRouteParam($request, 'name');
        $this->syncManager()->deleteBackup($name);

        return ApiResponse::noContent();
    }

    public function startCheckDiff(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $body = $this->getRequestBody($request);
        $kinds = array_values((array) ($body['kinds'] ?? []));

        return ApiResponse::create($this->syncManager()->startCheckDiffJob($kinds));
    }

    public function stepCheckDiff(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');

        return ApiResponse::create($this->syncManager()->stepCheckDiffJob($jobId));
    }

    public function startSync(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $body = $this->getRequestBody($request);
        $resolutions = (array) ($body['resolutions'] ?? []);

        return ApiResponse::create($this->syncManager()->startSyncJob($resolutions));
    }

    public function stepSync(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');

        return ApiResponse::create($this->syncManager()->stepSyncJob($jobId));
    }

    /**
     * Confirmation phrase the client must echo back after the user accepts
     * the "this deletes everything on Hosting first" modal — checked again
     * here (not just in the UI) so the destructive path can't be triggered
     * by calling the endpoint directly. Kept identical to the admin-classic
     * handler's constant.
     */
    private const FORCE_PUSH_CONFIRM_PHRASE = 'CONFIRMED';

    public function startPush(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $body = $this->getRequestBody($request);
        $confirm = trim((string) ($body['confirm'] ?? ''));
        if ($confirm !== self::FORCE_PUSH_CONFIRM_PHRASE) {
            throw new ValidationException('Confirmation mismatch — upload cancelled for safety.');
        }

        $kinds = array_values((array) ($body['kinds'] ?? []));

        return ApiResponse::create($this->syncManager()->startPushJob($kinds));
    }

    public function stepPush(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');

        return ApiResponse::create($this->syncManager()->stepPushJob($jobId));
    }

    public function startFullDeploy(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        return ApiResponse::create($this->syncManager()->startFullDeployJob());
    }

    public function stepFullDeploy(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');

        return ApiResponse::create($this->syncManager()->stepFullDeployJob($jobId));
    }

    public function cancelFullDeploy(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        $jobId = (string) $this->getRouteParam($request, 'jobId');
        $this->syncManager()->cancelFullDeployJob($jobId);

        return ApiResponse::create(['status' => 'ok']);
    }

    public function markFullDeploySynced(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard($request);

        return ApiResponse::create($this->syncManager()->markFullDeploySynced());
    }

    /** Same two-part gate as classic-admin's guardRequest(): api.super, and the local/force_allow_remote check. */
    private function guard(ServerRequestInterface $request): void
    {
        $this->requireSuper($request);

        if (!$this->isEnabled()) {
            throw new ValidationException('FTP Sync is disabled: this is not a local development environment.');
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
