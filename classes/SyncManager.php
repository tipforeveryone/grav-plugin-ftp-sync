<?php

namespace Grav\Plugin\FtpSync;

/**
 * Orchestrator chính của plugin: gom local/remote map theo từng "group"
 * (pages + mỗi theme cấu hình), diff với baseline, áp dụng hành động khi
 * sync, và lưu lại state (baseline.json) để lần sau diff chính xác hơn.
 *
 * Mỗi path trong state nội bộ có tiền tố group, VD "pages/01.home/home.md"
 * hoặc "themes/phuongmailaw/css/x.css", để 1 baseline duy nhất gom được
 * tất cả group mà không đụng tên.
 */
class SyncManager
{
    private array $config;
    private string $dataDir;

    public function __construct(array $config, string $dataDir)
    {
        $this->config = $config;
        $this->dataDir = rtrim($dataDir, '/');
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * Liệt kê các file backup .zip đã tạo (mới nhất trước), kèm tên file,
     * kích thước và thời điểm tạo.
     *
     * @return array<int, array{name:string, size:int, created:int}>
     */
    public function listBackups(): array
    {
        $files = glob($this->dataDir . '/backups/*.zip') ?: [];
        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'name' => basename($file),
                'size' => filesize($file) ?: 0,
                'created' => filemtime($file) ?: 0,
            ];
        }
        usort($backups, fn ($a, $b) => $b['created'] <=> $a['created']);
        return $backups;
    }

    /** Xoá 1 file backup theo tên (basename only, chặn path traversal). */
    public function deleteBackup(string $name): void
    {
        $name = basename($name);
        if ($name === '' || !str_ends_with($name, '.zip')) {
            throw new \RuntimeException('Invalid backup file name.');
        }

        $path = $this->dataDir . '/backups/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException('Backup file not found.');
        }

        if (!@unlink($path)) {
            throw new \RuntimeException('Could not delete backup file.');
        }
    }

    /**
     * Quét local + remote, diff với baseline, lưu kết quả vào last-diff.json
     * để taskSyncNow dùng lại (không cần quét lại từ đầu).
     *
     * @param string[] $kinds Lọc theo nhóm muốn đồng bộ: 'pages'|'themes'|'plugins'|'config'|'accounts'.
     *                        Rỗng = tất cả.
     * @return array{groups: array<string,string>, rows: array<string, array{type:string}>, cold_start: bool}
     */
    public function checkDiff(array $kinds = []): array
    {
        $groups = $this->resolveGroups($kinds);
        if (empty($groups)) {
            throw new \RuntimeException('No content selected to sync.');
        }

        $ignorePatterns = $this->ignorePatterns();
        $scanner = new FileScanner($ignorePatterns);

        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $local = [];
        $remote = [];
        try {
            foreach ($groups as $groupKey => $group) {
                foreach ($scanner->scan($group['local']) as $relPath => $stat) {
                    $local[$groupKey . '/' . $relPath] = $stat;
                }
                foreach ($ftp->scan($group['remote'], [$scanner, 'isIgnored']) as $relPath => $stat) {
                    $remote[$groupKey . '/' . $relPath] = $stat;
                }
            }
        } finally {
            $ftp->close();
        }

        $baseline = $this->loadBaseline();
        $rows = (new DiffEngine())->diff($local, $remote, $baseline);

        // Với row 'conflict', đánh dấu bên nào có mtime mới hơn để UI tô màu
        // riêng (bên mới hơn = nổi bật, bên cũ hơn = "Xung đột").
        foreach ($rows as $path => &$row) {
            if ($row['type'] !== 'conflict') {
                continue;
            }
            $localMtime = $local[$path]['mtime'] ?? 0;
            $remoteMtime = $remote[$path]['mtime'] ?? 0;
            if ($localMtime > $remoteMtime) {
                $row['newer'] = 'local';
            } elseif ($remoteMtime > $localMtime) {
                $row['newer'] = 'remote';
            } else {
                $row['newer'] = null;
            }
        }
        unset($row);

        $state = [
            'groups' => $groups,
            'local' => $local,
            'remote' => $remote,
            'baseline' => $baseline,
            'rows' => $rows,
            'checked_at' => time(),
        ];
        $this->saveJson($this->dataDir . '/last-diff.json', $state);

        $groupLabels = [];
        foreach ($groups as $key => $group) {
            $groupLabels[$key] = $group['label'];
        }

        return [
            'groups' => $groupLabels,
            'rows' => $rows,
            'cold_start' => empty($baseline),
        ];
    }

    /**
     * Force-push: XOÁ TOÀN BỘ nội dung hiện có trên hosting (trong các
     * group thuộc $kinds), backup lại trước khi xoá, rồi upload lại TOÀN
     * BỘ file từ local lên. Không quan tâm diff/baseline cũ — coi local là
     * nguồn chân lý tuyệt đối cho lần này. Dùng khi hosting bị lỗi/rác và
     * muốn "làm mới" hoàn toàn từ local.
     *
     * @param string[] $kinds Rỗng = tất cả group đang cấu hình.
     * @return array{uploaded:int, deleted:int, groups:int, backup:?string}
     */
    public function forcePushAll(array $kinds = []): array
    {
        $groups = $this->resolveGroups($kinds);
        if (empty($groups)) {
            throw new \RuntimeException('No content selected to upload.');
        }

        $ignorePatterns = $this->ignorePatterns();
        $scanner = new FileScanner($ignorePatterns);

        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $backup = ($this->config['backup_enabled'] ?? true)
            ? new BackupManager($this->dataDir . '/backups')
            : null;

        $baseline = $this->loadBaseline();
        $uploaded = 0;
        $deleted = 0;

        try {
            foreach ($groups as $groupKey => $group) {
                $remoteBase = rtrim($group['remote'], '/');
                $localBase = rtrim($group['local'], '/');

                // 1) Backup toàn bộ remote hiện có trước khi xoá.
                $remoteFiles = $ftp->scan($remoteBase, [$scanner, 'isIgnored']);
                foreach ($remoteFiles as $relPath => $stat) {
                    if ($backup) {
                        $this->backupRemote($backup, $ftp, $relPath, $remoteBase . '/' . $relPath);
                    }
                    $deleted++;
                }

                // 2) Xoá toàn bộ remote (sau khi đã backup xong).
                $ftp->deleteTree($remoteBase);

                // 3) Upload lại toàn bộ local.
                $localFiles = $scanner->scan($localBase);
                foreach ($localFiles as $relPath => $stat) {
                    $path = $groupKey . '/' . $relPath;
                    $ftp->upload($localBase . '/' . $relPath, $remoteBase . '/' . $relPath);
                    $baseline[$path] = [
                        'local' => $stat,
                        'remote' => $this->statRemote($ftp, $remoteBase . '/' . $relPath),
                    ];
                    $uploaded++;
                }

                // Baseline cũ của path không còn ở local (đã bị xoá khỏi remote, không upload lại) -> bỏ.
                $prefix = $groupKey . '/';
                foreach (array_keys($baseline) as $path) {
                    if (str_starts_with($path, $prefix) && !isset($localFiles[substr($path, strlen($prefix))])) {
                        unset($baseline[$path]);
                    }
                }
            }
        } finally {
            $ftp->close();
        }

        $this->saveJson($this->dataDir . '/baseline.json', $baseline);

        return [
            'uploaded' => $uploaded,
            'deleted' => $deleted,
            'groups' => count($groups),
            'backup' => $backup ? basename((string) $backup->close()) : null,
        ];
    }

    /**
     * Áp dụng kết quả diff đã lưu ở checkDiff(). $resolutions: relPath (đã
     * có tiền tố group) => 'local'|'remote'|'delete_local'|'delete_remote'
     * (rỗng/thiếu = bỏ qua). Vocabulary này áp dụng đồng nhất cho MỌI loại
     * row (push/pull/conflict/deleted_*) — 'local' luôn nghĩa là "đẩy bản
     * local lên hosting", 'remote' luôn là "kéo bản hosting về local",
     * không phụ thuộc row đang ở type gì.
     *
     * @return array{applied:int, skipped:int, backup:?string, errors:array<string,string>}
     */
    public function syncNow(array $resolutions): array
    {
        $state = $this->loadJson($this->dataDir . '/last-diff.json');
        if ($state === null) {
            throw new \RuntimeException('No diff data yet — click "Check differences" first.');
        }

        /** @var array<string,array{local:string,remote:string,label:string}> $groups */
        $groups = $state['groups'];
        $local = $state['local'];
        $remote = $state['remote'];
        $baseline = $state['baseline'];
        $rows = $state['rows'];

        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $backup = ($this->config['backup_enabled'] ?? true)
            ? new BackupManager($this->dataDir . '/backups')
            : null;

        $applied = 0;
        $skipped = 0;
        $errors = [];

        try {
            foreach ($rows as $path => $row) {
                $action = $this->resolveAction($resolutions[$path] ?? null);
                if ($action === null) {
                    $skipped++;
                    continue;
                }

                [$groupKey, $relPath] = $this->splitGroupPath($path, $groups);
                if ($groupKey === null) {
                    $skipped++;
                    continue;
                }
                $group = $groups[$groupKey];
                $localFile = rtrim($group['local'], '/') . '/' . $relPath;
                $remoteFile = rtrim($group['remote'], '/') . '/' . $relPath;

                try {
                    if ($action === 'push') {
                        $this->backupRemote($backup, $ftp, $relPath, $remoteFile);
                        $ftp->upload($localFile, $remoteFile);
                        $baseline[$path] = [
                            'local' => $local[$path] ?? $this->statLocal($localFile),
                            'remote' => $this->statRemote($ftp, $remoteFile),
                        ];
                    } elseif ($action === 'pull') {
                        $backup?->addLocalFile($path, $localFile);
                        $ftp->download($remoteFile, $localFile);
                        $baseline[$path] = [
                            'local' => $this->statLocal($localFile),
                            'remote' => $remote[$path] ?? $this->statRemote($ftp, $remoteFile),
                        ];
                    } elseif ($action === 'delete_remote') {
                        $this->backupRemote($backup, $ftp, $relPath, $remoteFile);
                        $ftp->delete($remoteFile);
                        unset($baseline[$path]);
                    } elseif ($action === 'delete_local') {
                        $backup?->addLocalFile($path, $localFile);
                        @unlink($localFile);
                        unset($baseline[$path]);
                    }

                    $applied++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[$path] = $e->getMessage();
                }
            }
        } finally {
            $ftp->close();
        }

        $this->saveJson($this->dataDir . '/baseline.json', $baseline);

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'errors' => $errors,
            'backup' => $backup ? basename((string) $backup->close()) : null,
        ];
    }

    /** Map resolution người dùng chọn ('local'|'remote'|'delete_local'|'delete_remote') -> hành động. */
    private function resolveAction(?string $resolution): ?string
    {
        return match ($resolution) {
            'local' => 'push',
            'remote' => 'pull',
            'delete_local' => 'delete_local',
            'delete_remote' => 'delete_remote',
            default => null,
        };
    }

    private function backupRemote(?BackupManager $backup, FtpClient $ftp, string $relPath, string $remoteFile): void
    {
        if (!$backup || !$ftp->exists($remoteFile)) {
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ftp-sync-');
        try {
            $ftp->download($remoteFile, $tmp);
            $backup->addRemoteContent($relPath, (string) file_get_contents($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    private function statLocal(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        return ['mtime' => filemtime($path) ?: 0, 'size' => filesize($path) ?: 0];
    }

    private function statRemote(FtpClient $ftp, string $remotePath): ?array
    {
        if (!$ftp->exists($remotePath)) {
            return null;
        }
        return ['mtime' => $ftp->modifiedTime($remotePath), 'size' => $ftp->sizeOf($remotePath)];
    }

    /** @return array{0:?string,1:?string} */
    private function splitGroupPath(string $path, array $groups): array
    {
        foreach (array_keys($groups) as $groupKey) {
            $prefix = $groupKey . '/';
            if (str_starts_with($path, $prefix)) {
                return [$groupKey, substr($path, strlen($prefix))];
            }
        }
        return [null, null];
    }

    /**
     * @param string[] $kinds Lọc theo 'pages'|'themes'|'plugins'|'config'|'accounts'. Rỗng = tất cả.
     * @return array<string, array{local:string,remote:string,label:string}>
     */
    private function resolveGroups(array $kinds = []): array
    {
        $kinds = empty($kinds) ? ['pages', 'themes', 'plugins', 'config', 'accounts'] : $kinds;
        $remoteBase = rtrim($this->config['remote_base_path'] ?? '/', '/');
        $groups = [];

        if (in_array('pages', $kinds, true)) {
            $groups['pages'] = [
                'local' => PAGES_DIR,
                'remote' => $remoteBase . '/user/pages',
                'label' => 'user/pages',
            ];
        }

        if (in_array('config', $kinds, true)) {
            $groups['config'] = [
                'local' => USER_DIR . 'config',
                'remote' => $remoteBase . '/user/config',
                'label' => 'user/config',
            ];
        }

        if (in_array('accounts', $kinds, true)) {
            $groups['accounts'] = [
                'local' => ACCOUNTS_DIR,
                'remote' => $remoteBase . '/user/accounts',
                'label' => 'user/accounts',
            ];
        }

        // Chỉ đồng bộ ĐÚNG theme đang active của Grav (system.pages.theme),
        // không phải danh sách theme tự khai báo — theme nào không active sẽ
        // không bao giờ bị upload/download qua plugin này.
        if (in_array('themes', $kinds, true) && ($this->config['active_theme'] ?? '') !== '') {
            $theme = $this->config['active_theme'];
            $groups['theme:' . $theme] = [
                'local' => THEMES_DIR . $theme,
                'remote' => $remoteBase . '/user/themes/' . $theme,
                'label' => 'user/themes/' . $theme . ' (theme đang active)',
            ];
        }

        if (in_array('plugins', $kinds, true)) {
            // Trống = chưa khai báo gì (cài mới) -> tự quét TOÀN BỘ plugin
            // đang có trong user/plugins/. Có khai báo -> chỉ đúng danh sách đó.
            $pluginNames = $this->configList('sync_plugins');
            if (empty($pluginNames)) {
                $pluginNames = $this->listLocalPluginNames();
            }
            foreach ($pluginNames as $plugin) {
                $groups['plugin:' . $plugin] = [
                    'local' => PLUGINS_DIR . $plugin,
                    'remote' => $remoteBase . '/user/plugins/' . $plugin,
                    'label' => 'user/plugins/' . $plugin,
                ];
            }
        }

        return $groups;
    }

    /** @return string[] Tên các thư mục con trực tiếp trong PLUGINS_DIR, đã sort. */
    private function listLocalPluginNames(): array
    {
        $entries = @scandir(PLUGINS_DIR) ?: [];
        $names = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir(PLUGINS_DIR . $entry)) {
                $names[] = $entry;
            }
        }
        sort($names);
        return $names;
    }

    /** Đọc 1 field config dạng list (mảng hoặc chuỗi phân tách dấu phẩy), trim + bỏ rỗng. */
    private function configList(string $key): array
    {
        $raw = $this->config[$key] ?? [];
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);

        $list = [];
        foreach ($raw as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $list[] = $item;
            }
        }
        return $list;
    }

    private function ignorePatterns(): array
    {
        return $this->configList('ignore_patterns');
    }

    private function connectFtp(FtpClient $ftp): void
    {
        $ftpConfig = $this->config['ftp'] ?? [];
        $ftp->connect(
            (string) ($ftpConfig['host'] ?? ''),
            (int) ($ftpConfig['port'] ?? 21),
            (string) ($ftpConfig['username'] ?? ''),
            (string) ($ftpConfig['password'] ?? ''),
            (bool) ($ftpConfig['ssl'] ?? false),
            (bool) ($ftpConfig['passive'] ?? true)
        );
    }

    private function loadBaseline(): array
    {
        return $this->loadJson($this->dataDir . '/baseline.json') ?? [];
    }

    private function loadJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function saveJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
