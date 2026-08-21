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
    /** Số file xử lý mỗi batch (mỗi request AJAX) khi upload/sync — để UI vẽ progress bar theo tiến trình thật. */
    private const BATCH_SIZE = 25;

    /**
     * "Full deploy" bỏ qua hoàn toàn checkbox/sync_plugins — tự quét TOÀN
     * BỘ GRAV_ROOT rồi loại trừ đúng những gì không ảnh hưởng tới việc site
     * chạy được. Danh sách này chỉ loại trừ khi tên khớp NGAY CẤP GỐC
     * (GRAV_ROOT/<tên>), tránh lặp lại lỗi cũ (loại "cache" theo segment ở
     * MỌI cấp từng xoá nhầm vendor/doctrine/cache/...).
     */
    private const FULL_DEPLOY_TOP_LEVEL_EXCLUDE = [
        '.ddev' => true, '.dependencies' => true, '.editorconfig' => true, '.env' => true,
        '.env.example' => true, '.git' => true, '.github' => true, '.gitignore' => true,
        '.gitmodules' => true, '.phan' => true, '.vscode' => true, '.idea' => true,
        'CHANGELOG.md' => true, 'CLAUDE.md' => true, 'CODE_OF_CONDUCT.md' => true, 'CONTRIBUTING.md' => true,
        'LICENSE' => true, 'LICENSE.txt' => true, 'LICENSE.md' => true, 'README.md' => true, 'SECURITY.md' => true,
        'codeception.yml' => true, 'composer.json' => true, 'composer.lock' => true, 'now.json' => true,
        'backup' => true, 'cache' => true, 'logs' => true, 'tmp' => true, 'tests' => true,
        'webserver-configs' => true, 'bin' => true, 'node_modules' => true,
        // assets/ và images/ ở GỐC là cache pipeline CSS/JS + cache resize ảnh của Grav (auto sinh lại khi
        // chạy) — khác với system/assets/ hay assets/ bên trong theme, những cái đó KHÔNG bị loại vì đây
        // chỉ khớp đúng cấp gốc.
        'assets' => true, 'images' => true,
    ];

    /**
     * Loại trừ theo TÊN THƯ MỤC ở BẤT KỲ cấp nào (kể cả sâu trong vendor/
     * system) — CHỈ dùng cho tên chắc chắn không bao giờ trùng với tên 1
     * gói Composer thật (org hoặc package name), vì khớp theo tên đơn lẻ
     * này không phân biệt được "thư mục dev artefact" với "thư mục gói
     * cần cho runtime". Từng có 'phpstan' ở đây — dùng để loại
     * tests/phpstan/ (đã bị loại ở cấp gốc qua 'tests' rồi nên hoàn toàn
     * dư thừa), nhưng lại vô tình khớp luôn vendor/phpstan/ — namespace
     * Composer thật của gói phpstan/phpstan, gói này khai báo bootstrap.php
     * là "files" autoload nên bị Composer require() ở MỌI request bất kể
     * có dùng PHPStan hay không → thiếu file là sập toàn site (đã xảy ra
     * trên hosting). Cân nhắc kỹ trước khi thêm tên mới vào đây.
     */
    private const FULL_DEPLOY_SUBTREE_EXCLUDE = ['.git', '.ddev', 'node_modules', 'tests', 'test', 'docs', 'doc', 'examples', 'example', '.github'];

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
     * Bước 1/2 của "Replace local sync to hosting": quét local + remote
     * (trong các group thuộc $kinds, theo checkbox người dùng chọn) và xếp
     * thành 2 hàng đợi (xoá remote cũ, upload local mới), lưu vào
     * push-job.json. Không xoá/upload gì ở bước này — gọi stepPushJob()
     * lặp lại nhiều lần (mỗi lần 1 batch) để UI vẽ được progress bar theo
     * tiến trình thật thay vì 1 request treo rất lâu.
     *
     * @param string[] $kinds Rỗng = tất cả group đang cấu hình.
     * @return array{job_id:string, total:int}
     */
    public function startPushJob(array $kinds = []): array
    {
        $groups = $this->resolveGroups($kinds);
        if (empty($groups)) {
            throw new \RuntimeException('No content selected to upload.');
        }

        $scanner = new FileScanner($this->ignorePatterns());
        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $deleteOps = [];
        $uploadOps = [];

        try {
            foreach ($groups as $groupKey => $group) {
                foreach (array_keys($ftp->scan($group['remote'], [$scanner, 'isIgnored'])) as $relPath) {
                    $deleteOps[] = [$groupKey, $relPath];
                }
                foreach (array_keys($scanner->scan($group['local'])) as $relPath) {
                    $uploadOps[] = [$groupKey, $relPath];
                }
            }
        } finally {
            $ftp->close();
        }

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'id' => $jobId,
            'groups' => $groups,
            'delete_ops' => $deleteOps,
            'upload_ops' => $uploadOps,
            'total' => count($deleteOps) + count($uploadOps),
            'done' => 0,
            'uploaded' => 0,
            'deleted' => 0,
            'baseline' => $this->loadBaseline(),
            'backup_zip' => null,
            'backup_has_entries' => false,
        ];
        $this->saveJson($this->dataDir . '/push-job.json', $job);

        return ['job_id' => $jobId, 'total' => $job['total']];
    }

    /**
     * Bước 2/2: xử lý 1 batch (tối đa BATCH_SIZE thao tác) của job đã tạo
     * bởi startPushJob() — trước hết rút cạn hàng đợi xoá (backup rồi xoá
     * từng file remote), sau đó tới hàng đợi upload. Gọi lặp lại tới khi
     * finished=true.
     *
     * @return array{done:int,total:int,uploaded:int,deleted:int,finished:bool,backup:?string,groups:int}
     */
    public function stepPushJob(string $jobId, int $batchSize = self::BATCH_SIZE): array
    {
        $job = $this->loadJson($this->dataDir . '/push-job.json');
        if ($job === null || ($job['id'] ?? null) !== $jobId) {
            throw new \RuntimeException('No such upload job (it may have finished, been cancelled, or expired).');
        }

        $groups = $job['groups'];
        $baseline = $job['baseline'];

        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $backup = $this->openBackupForJob($job);

        $processed = 0;

        try {
            while ($processed < $batchSize && !empty($job['delete_ops'])) {
                [$groupKey, $relPath] = array_shift($job['delete_ops']);
                $group = $groups[$groupKey];
                $remoteFile = rtrim($group['remote'], '/') . '/' . $relPath;

                $this->backupRemote($backup, $ftp, $groupKey . '/' . $relPath, $remoteFile);
                $ftp->delete($remoteFile);

                $job['deleted']++;
                $job['done']++;
                $processed++;
            }

            while ($processed < $batchSize && !empty($job['upload_ops'])) {
                [$groupKey, $relPath] = array_shift($job['upload_ops']);
                $group = $groups[$groupKey];
                $localFile = rtrim($group['local'], '/') . '/' . $relPath;
                $remoteFile = rtrim($group['remote'], '/') . '/' . $relPath;

                $ftp->upload($localFile, $remoteFile);
                $baseline[$groupKey . '/' . $relPath] = [
                    'local' => $this->statLocal($localFile),
                    'remote' => $this->statRemote($ftp, $remoteFile),
                ];

                $job['uploaded']++;
                $job['done']++;
                $processed++;
            }
        } finally {
            $ftp->close();
        }

        $finished = empty($job['delete_ops']) && empty($job['upload_ops']);
        $backupResult = $this->closeBackupForJob($backup, $job, $finished);

        $job['baseline'] = $baseline;

        if ($finished) {
            $this->saveJson($this->dataDir . '/baseline.json', $baseline);
            @unlink($this->dataDir . '/push-job.json');
        } else {
            $this->saveJson($this->dataDir . '/push-job.json', $job);
        }

        return [
            'done' => $job['done'],
            'total' => $job['total'],
            'uploaded' => $job['uploaded'],
            'deleted' => $job['deleted'],
            'finished' => $finished,
            'backup' => $backupResult ? basename($backupResult) : null,
            'groups' => count($groups),
        ];
    }

    /**
     * Bước 1/2 của "Full deploy to hosting": BỎ QUA hoàn toàn checkbox và
     * sync_plugins — tự quét TOÀN BỘ GRAV_ROOT, loại trừ đúng những gì
     * không ảnh hưởng tới việc site chạy được (xem FULL_DEPLOY_*_EXCLUDE).
     * Xoá sạch nội dung tương ứng hiện có trên hosting, rồi nén phần còn
     * lại thành 1 file .zip DUY NHẤT và upload lên gốc remote_base_path —
     * KHÔNG tự giải nén, người dùng tự giải nén thủ công trên hosting.
     *
     * @return array{job_id:string, total:int}
     */
    /**
     * "Full deploy" ở đây CHỈ còn nén site thành 1 file .zip local — xoá
     * file cũ trên hosting và upload lên hosting nay do người dùng tự làm
     * thủ công (không còn động tới FTP ở bước này), nên không cần xác
     * nhận nguy hiểm nữa.
     */
    public function startFullDeployJob(): array
    {
        $localFiles = $this->scanFullDeployLocal();
        if (empty($localFiles)) {
            throw new \RuntimeException('Nothing found to deploy.');
        }

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'id' => $jobId,
            'zip_ops' => array_keys($localFiles),
            'zip_total' => count($localFiles),
            'zip_path' => null,
            'zip_built' => false,
            'done' => 0,
        ];
        $this->saveJson($this->dataDir . '/full-deploy-job.json', $job);

        return ['job_id' => $jobId, 'total' => $job['zip_total']];
    }

    /**
     * Bước 2/2: xử lý 1 batch nén — mỗi lượt gọi thêm tối đa $batchSize
     * file vào .zip local (xem addBatchToFullDeployZip()), để UI vẽ
     * progress real-time thay vì đứng hình khi nén hàng nghìn file cùng
     * lúc. Khi nén xong hoàn toàn (zip_built=true), đổi tên file .zip
     * tạm sang tên cuối cùng có mốc thời gian rồi báo finished=true kèm
     * đường dẫn — người dùng tự tìm file này trong user/data/ftp-sync/
     * để tự upload lên hosting. Gọi lặp lại tới khi finished=true.
     *
     * @return array{done:int,total:int,finished:bool,zip_file:?string}
     */
    public function stepFullDeployJob(string $jobId, int $batchSize = self::BATCH_SIZE): array
    {
        $job = $this->loadJson($this->dataDir . '/full-deploy-job.json');
        if ($job === null || ($job['id'] ?? null) !== $jobId) {
            throw new \RuntimeException('No such deploy job (it may have finished, been cancelled, or expired).');
        }

        if (!$job['zip_built']) {
            $this->addBatchToFullDeployZip($job, $batchSize);
        }

        $finished = $job['zip_built'];

        if ($finished && !empty($job['zip_path'])) {
            $finalPath = $this->dataDir . '/full-deploy-' . date('Y-m-d_His') . '.zip';
            if (@rename($job['zip_path'], $finalPath)) {
                $job['zip_path'] = $finalPath;
            }
        }

        if ($finished) {
            @unlink($this->dataDir . '/full-deploy-job.json');
        } else {
            $this->saveJson($this->dataDir . '/full-deploy-job.json', $job);
        }

        return [
            'done' => $job['done'],
            'total' => $job['zip_total'],
            'finished' => $finished,
            'zip_file' => $finished ? basename($job['zip_path']) : null,
        ];
    }

    /**
     * Bước 1/2: xây hàng đợi thao tác từ kết quả diff đã lưu ở checkDiff()
     * + lựa chọn resolution của người dùng, lưu vào sync-job.json.
     * $resolutions: relPath (đã có tiền tố group) =>
     * 'local'|'remote'|'delete_local'|'delete_remote' (rỗng/thiếu = bỏ
     * qua). Vocabulary áp dụng đồng nhất cho MỌI loại row (push/pull/
     * conflict/deleted_*) — 'local' luôn nghĩa là "đẩy bản local lên
     * hosting", 'remote' luôn là "kéo bản hosting về local".
     *
     * @return array{job_id:string, total:int}
     */
    public function startSyncJob(array $resolutions): array
    {
        $state = $this->loadJson($this->dataDir . '/last-diff.json');
        if ($state === null) {
            throw new \RuntimeException('No diff data yet — click "Check differences" first.');
        }

        $rows = $state['rows'];
        $ops = [];
        $skipped = 0;
        foreach ($rows as $path => $row) {
            $action = $this->resolveAction($resolutions[$path] ?? null);
            if ($action === null) {
                $skipped++;
                continue;
            }
            $ops[] = [$path, $action];
        }

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'id' => $jobId,
            'groups' => $state['groups'],
            'local' => $state['local'],
            'remote' => $state['remote'],
            'ops' => $ops,
            'total' => count($ops),
            'skipped' => $skipped,
            'applied' => 0,
            'errors' => [],
            'baseline' => $state['baseline'],
            'backup_zip' => null,
            'backup_has_entries' => false,
        ];
        $this->saveJson($this->dataDir . '/sync-job.json', $job);

        return ['job_id' => $jobId, 'total' => $job['total']];
    }

    /**
     * Bước 2/2: xử lý 1 batch (tối đa BATCH_SIZE thao tác) của job đã tạo
     * bởi startSyncJob(). Gọi lặp lại tới khi finished=true.
     *
     * @return array{done:int,total:int,applied:int,skipped:int,errors:array<string,string>,finished:bool,backup:?string}
     */
    public function stepSyncJob(string $jobId, int $batchSize = self::BATCH_SIZE): array
    {
        $job = $this->loadJson($this->dataDir . '/sync-job.json');
        if ($job === null || ($job['id'] ?? null) !== $jobId) {
            throw new \RuntimeException('No such sync job (it may have finished, been cancelled, or expired).');
        }

        $groups = $job['groups'];
        $local = $job['local'];
        $remote = $job['remote'];
        $baseline = $job['baseline'];

        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $backup = $this->openBackupForJob($job);

        $processed = 0;

        try {
            while ($processed < $batchSize && !empty($job['ops'])) {
                [$path, $action] = array_shift($job['ops']);
                $processed++;

                [$groupKey, $relPath] = $this->splitGroupPath($path, $groups);
                if ($groupKey === null) {
                    $job['skipped']++;
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

                    $job['applied']++;
                } catch (\Throwable $e) {
                    $job['skipped']++;
                    $job['errors'][$path] = $e->getMessage();
                }
            }
        } finally {
            $ftp->close();
        }

        $finished = empty($job['ops']);
        $backupResult = $this->closeBackupForJob($backup, $job, $finished);

        $job['baseline'] = $baseline;

        if ($finished) {
            $this->saveJson($this->dataDir . '/baseline.json', $baseline);
            @unlink($this->dataDir . '/sync-job.json');
        } else {
            $this->saveJson($this->dataDir . '/sync-job.json', $job);
        }

        return [
            'done' => $job['total'] - count($job['ops']),
            'total' => $job['total'],
            'applied' => $job['applied'],
            'skipped' => $job['skipped'],
            'errors' => $finished ? $job['errors'] : [],
            'finished' => $finished,
            'backup' => $backupResult ? basename($backupResult) : null,
        ];
    }

    /**
     * Mở BackupManager cho 1 batch của job: nếu job đã có backup_zip từ
     * batch trước (nghĩa là đã thực sự có entry, file zip chắc chắn tồn
     * tại) thì mở lại để nối thêm; nếu chưa, tạo mới. KHÔNG được lưu
     * backup_zip vào job khi zip đang rỗng — ZipArchive::close() không ghi
     * file vật lý nào cả khi không có entry, nên mở lại 1 zip "rỗng" như
     * vậy sẽ ném lỗi "Invalid or uninitialized Zip object".
     */
    private function openBackupForJob(array $job): ?BackupManager
    {
        if (!($this->config['backup_enabled'] ?? true)) {
            return null;
        }

        return $job['backup_zip']
            ? new BackupManager($this->dataDir . '/backups', $job['backup_zip'], $job['backup_has_entries'])
            : new BackupManager($this->dataDir . '/backups');
    }

    /**
     * Đóng BackupManager sau 1 batch. Chỉ ghi backup_zip vào $job (để batch
     * sau mở lại) một khi zip đã thực sự có entry — xem giải thích ở
     * openBackupForJob(). Trả về đường dẫn zip cuối cùng khi $finished (đã
     * xử lý xong toàn bộ job), null nếu chưa xong hoặc không có gì backup.
     */
    private function closeBackupForJob(?BackupManager $backup, array &$job, bool $finished): ?string
    {
        if (!$backup) {
            return null;
        }

        if ($finished) {
            return $backup->finish();
        }

        $backup->flush();
        if ($backup->hasEntries()) {
            $job['backup_zip'] = $backup->zipPath();
            $job['backup_has_entries'] = true;
        }
        return null;
    }

    /**
     * Grav tự kiểm tra các thư mục này PHẢI TỒN TẠI (rỗng cũng được, miễn
     * ghi được) mới chạy — xem EssentialFolders::process() của plugin
     * "problems". Nội dung bên trong bị loại khỏi deploy (chỉ là cache
     * runtime), nhưng bản thân THƯ MỤC vẫn phải có mặt trong zip, nếu
     * không zip sẽ không tạo ra thư mục nào cả khi giải nén.
     */
    private const FULL_DEPLOY_REQUIRED_EMPTY_DIRS = ['cache', 'logs', 'tmp', 'backup', 'images', 'assets'];

    /**
     * Thêm tối đa $limit file từ đầu $job['zip_ops'] (relPath tương đối
     * GRAV_ROOT, VD "system/src/.../Client.php", "user/pages/...",
     * "index.php") vào file .zip local của job, rồi cập nhật $job theo
     * tham chiếu (done/zip_ops/zip_path/zip_built). File zip được mở lại
     * bằng CREATE (không OVERWRITE) ở mỗi lượt gọi để ghi tiếp vào đúng
     * file đã có — cho phép nén rải qua nhiều request thay vì 1 lần duy
     * nhất cho hàng nghìn file (dễ chạm max_execution_time trên hosting
     * rẻ, tạo ra zip dở dang). Khi $job['zip_ops'] rút cạn ở lượt gọi này,
     * bổ sung luôn các thư mục rỗng bắt buộc rồi đánh dấu zip_built=true.
     *
     * @return int Số file thực sự đã thêm vào zip ở lượt gọi này.
     */
    private function addBatchToFullDeployZip(array &$job, int $limit): int
    {
        if ($job['zip_path'] === null) {
            $job['zip_path'] = $this->dataDir . '/deploy-' . $job['id'] . '.zip';
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($job['zip_path'], \ZipArchive::CREATE);
        if ($openResult !== true) {
            throw new \RuntimeException('Could not open deploy zip for writing (code ' . $openResult . ').');
        }

        $added = 0;
        while ($added < $limit && !empty($job['zip_ops'])) {
            $relPath = array_shift($job['zip_ops']);
            $zip->addFile(GRAV_ROOT . '/' . $relPath, $relPath);
            $job['done']++;
            $added++;
        }

        if (empty($job['zip_ops'])) {
            foreach (self::FULL_DEPLOY_REQUIRED_EMPTY_DIRS as $dir) {
                $zip->addEmptyDir($dir);
            }
            $job['zip_built'] = true;
        }

        $zip->close();

        return $added;
    }

    /**
     * Quét TOÀN BỘ GRAV_ROOT ở local cho "Full deploy", áp dụng
     * FULL_DEPLOY_TOP_LEVEL_EXCLUDE + FULL_DEPLOY_SUBTREE_EXCLUDE +
     * ignore_patterns của người dùng. Trả về relPath (tương đối GRAV_ROOT,
     * dùng '/') => stat — relPath này CHÍNH LÀ đường dẫn thật trên webroot.
     *
     * @return array<string, array{mtime:int,size:int}>
     */
    private function scanFullDeployLocal(): array
    {
        $result = [];
        $this->walkFullDeployLocal(GRAV_ROOT, '', $result);
        return $result;
    }

    private function walkFullDeployLocal(string $baseDir, string $relDir, array &$result): void
    {
        $fullDir = $relDir !== '' ? $baseDir . '/' . $relDir : $baseDir;
        $items = @scandir($fullDir) ?: [];

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relPath = $relDir !== '' ? $relDir . '/' . $name : $name;
            if ($this->isFullDeployIgnored($relPath)) {
                continue;
            }

            $fullPath = $fullDir . '/' . $name;
            if (is_dir($fullPath)) {
                $this->walkFullDeployLocal($baseDir, $relPath, $result);
            } else {
                $result[$relPath] = ['mtime' => filemtime($fullPath) ?: 0, 'size' => filesize($fullPath) ?: 0];
            }
        }
    }

    /**
     * Logic loại trừ dùng chung cho cả quét local lẫn remote của Full
     * Deploy: khớp TOP_LEVEL_EXCLUDE chỉ ở segment đầu tiên (đúng cấp gốc),
     * loại riêng user/data/ftp-sync (dữ liệu runtime của chính plugin này),
     * rồi khớp SUBTREE_EXCLUDE + ignore_patterns người dùng ở BẤT KỲ
     * segment nào.
     */
    public function isFullDeployIgnored(string $relPath): bool
    {
        $segments = explode('/', $relPath);

        if (isset(self::FULL_DEPLOY_TOP_LEVEL_EXCLUDE[$segments[0]])) {
            return true;
        }

        if ($relPath === 'user/data/ftp-sync' || str_starts_with($relPath, 'user/data/ftp-sync/')) {
            return true;
        }

        $subtreeExclude = array_merge(self::FULL_DEPLOY_SUBTREE_EXCLUDE, $this->ignorePatterns());
        foreach ($segments as $segment) {
            foreach ($subtreeExclude as $pattern) {
                $pattern = trim($pattern);
                if ($pattern !== '' && fnmatch($pattern, $segment)) {
                    return true;
                }
            }
        }

        return false;
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
