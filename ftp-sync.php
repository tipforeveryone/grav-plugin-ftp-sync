<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\FtpSync\SyncManager;
use RocketTheme\Toolbox\Event\Event;

/**
 * FTP Sync
 *
 * Dedicated Admin Panel page to sync user/pages + the active theme between
 * local (DDEV) and hosting over FTP/FTPS. Diff is based on mtime+size (see
 * DiffEngine), with automatic backup before overwriting; conflicts must be
 * resolved manually. The whole feature only works when detected as a local
 * environment, unless force_allow_remote is enabled in the config — on a
 * live hosting deployment of this same plugin, every task refuses to run.
 */
class FTPSyncPlugin extends Plugin
{
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        $this->registerAutoload();

        if (!$this->isAdmin()) {
            return;
        }

        $this->enable([
            'onAdminMenu' => ['onAdminMenu', 0],
            'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
            'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
        ]);
    }

    public function onTwigInitialized(): void
    {
        $this->grav['twig']->twig()->addFunction(
            new \Twig\TwigFunction('ftp_sync_is_local', [$this, 'isLocalEnvironment'])
        );
        $this->grav['twig']->twig()->addFunction(
            new \Twig\TwigFunction('ftp_sync_is_enabled', [$this, 'isEnabled'])
        );
        $this->grav['twig']->twig()->addFunction(
            new \Twig\TwigFunction('ftp_sync_backup_path', [$this, 'backupPath'])
        );
    }

    /** Đường dẫn thư mục backup, tương đối so với gốc project (để copy dán vào File Explorer). */
    public function backupPath(): string
    {
        return 'user/data/ftp-sync/backups';
    }

    private function registerAutoload(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'Grav\\Plugin\\FtpSync\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $file = __DIR__ . '/classes/' . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    public function onAdminMenu(): void
    {
        if (!$this->canManage()) {
            return;
        }

        $this->grav['twig']->plugins_hooked_nav['FTP Sync'] = [
            'route' => 'ftp-sync',
            'icon' => 'fa-exchange',
            'authorize' => ['admin.super'],
            'priority' => 90,
        ];
    }

    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/admin/templates';
        $event['paths'] = $paths;
    }

    public function onAdminTaskExecute(Event $event): void
    {
        $controller = $event['controller'];
        $task = $controller->task ?? '';

        if ($task === 'ftpsynccheckdiff') {
            $event->stopPropagation();
            $this->handleCheckDiff($controller->post ?? []);
        } elseif ($task === 'ftpsyncsyncnow') {
            $event->stopPropagation();
            $this->handleSyncNow($controller->post ?? []);
        } elseif ($task === 'ftpsyncforcepushall') {
            $event->stopPropagation();
            $this->handleForcePushAll($controller->post ?? []);
        } elseif ($task === 'ftpsynclistbackups') {
            $event->stopPropagation();
            $this->handleListBackups();
        } elseif ($task === 'ftpsyncdeletebackup') {
            $event->stopPropagation();
            $this->handleDeleteBackup($controller->post ?? []);
        }
    }

    private function handleCheckDiff(array $post): void
    {
        if (!$this->guardRequest()) {
            return;
        }

        $kinds = array_values((array) ($post['kinds'] ?? []));

        try {
            $result = $this->syncManager()->checkDiff($kinds);
            $this->grav['admin']->json_response = ['status' => 'success'] + $result;
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage());
        }
    }

    private function handleSyncNow(array $post): void
    {
        if (!$this->guardRequest()) {
            return;
        }

        $resolutions = (array) ($post['resolutions'] ?? []);

        try {
            $result = $this->syncManager()->syncNow($resolutions);
            $this->grav['admin']->json_response = ['status' => 'success'] + $result;
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * Confirmation token the client sends after the user clicks "Yes" in the
     * modal — checked again on the server to make sure the request actually
     * went through the UI confirmation step, rather than calling the task
     * directly.
     */
    private const FORCE_PUSH_CONFIRM_PHRASE = 'CONFIRMED';

    private function handleForcePushAll(array $post): void
    {
        if (!$this->guardRequest()) {
            return;
        }

        $confirm = trim((string) ($post['confirm'] ?? ''));
        if ($confirm !== self::FORCE_PUSH_CONFIRM_PHRASE) {
            $this->jsonError('Confirmation mismatch — upload cancelled for safety.');
            return;
        }

        $kinds = array_values((array) ($post['kinds'] ?? []));

        try {
            $result = $this->syncManager()->forcePushAll($kinds);
            $this->grav['admin']->json_response = ['status' => 'success'] + $result;
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage());
        }
    }

    private function handleListBackups(): void
    {
        if (!$this->guardRequest()) {
            return;
        }

        try {
            $backups = $this->syncManager()->listBackups();
            $this->grav['admin']->json_response = ['status' => 'success', 'backups' => $backups];
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage());
        }
    }

    private function handleDeleteBackup(array $post): void
    {
        if (!$this->guardRequest()) {
            return;
        }

        $name = (string) ($post['name'] ?? '');

        try {
            $this->syncManager()->deleteBackup($name);
            $this->grav['admin']->json_response = ['status' => 'success'];
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * Common gate for every task: must be an authorized admin AND the
     * plugin must be "enabled" (local environment, or force_allow_remote).
     * On a live hosting copy of this plugin, every single task refuses to
     * run — the FTP credentials are never exercised from the production
     * server itself.
     */
    private function guardRequest(): bool
    {
        if (!$this->canManage()) {
            $this->jsonError('Not authorized.');
            return false;
        }

        if (!$this->isEnabled()) {
            $this->jsonError('FTP Sync is disabled: this is not a local development environment.');
            return false;
        }

        return true;
    }

    private function syncManager(): SyncManager
    {
        $config = (array) $this->config->get('plugins.ftp-sync');
        // The theme to sync is ALWAYS the site's active theme
        // (system.pages.theme), never configured manually — change the
        // active theme anywhere, the plugin follows automatically.
        $config['active_theme'] = (string) $this->config->get('system.pages.theme', '');
        return new SyncManager($config, DATA_DIR . 'ftp-sync');
    }

    private function jsonError(string $message): void
    {
        $this->grav['admin']->json_response = ['status' => 'error', 'message' => $message];
    }

    /** Detects local by checking for a .ddev/ folder at the webroot. */
    public function isLocalEnvironment(): bool
    {
        return is_dir(GRAV_ROOT . '/.ddev');
    }

    /** Whether the whole plugin is allowed to run at all: local, or explicitly forced on. */
    public function isEnabled(): bool
    {
        return $this->isLocalEnvironment() || (bool) $this->config->get('plugins.ftp-sync.force_allow_remote', false);
    }

    private function canManage(): bool
    {
        $user = $this->grav['user'] ?? null;
        if (!$user || !$user->authenticated) {
            return false;
        }
        return $user->authorize('admin.super') === true;
    }
}
