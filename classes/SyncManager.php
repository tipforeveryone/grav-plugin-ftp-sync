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
        // 'bin' KHÔNG được loại — Grav\Common\GPM\Installer::isGravInstance()
        // (dùng bởi self-upgrade Grav qua Admin/admin2) đòi hỏi bin/ phải tồn
        // tại trên đích mới coi là "Grav instance hợp lệ", nên thiếu nó khiến
        // "GPM self-upgrade" trên hosting báo "Target directory is not a
        // valid Grav instance." dù site vẫn chạy bình thường qua HTTP (đã
        // xảy ra thật — bin/ chưa từng lên hosting qua Full Deploy).
        'webserver-configs' => true, 'node_modules' => true,
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
     * Bước 1/3 của "Check differences": chỉ quét LOCAL (nhanh, đọc
     * filesystem tại chỗ) cho mọi group, lưu vào check-job.json. KHÔNG quét
     * remote ở đây — quét FTP đệ quy từng thư mục là phần THẬT SỰ chậm
     * (round-trip mạng cho mỗi thư mục con), nên phải tách ra thành từng
     * bước ở stepCheckDiffJob() (1 group/lần gọi) để UI vẽ được progress
     * bar theo tiến trình thật ngay từ pha quét, thay vì đứng hình "Checking..."
     * cho tới khi TOÀN BỘ group quét xong mới có phản hồi đầu tiên.
     *
     * @param string[] $kinds Lọc theo nhóm muốn đồng bộ: 'pages'|'themes'|'plugins'|'config'|'accounts'.
     *                        Rỗng = tất cả.
     * @return array{job_id:string, total:int, label:string}
     */
    public function startCheckDiffJob(array $kinds = []): array
    {
        $groups = $this->resolveGroups($kinds);
        if (empty($groups)) {
            throw new \RuntimeException('No content selected to sync.');
        }

        $scanner = new FileScanner($this->ignorePatterns());

        $local = [];
        $files = [];
        foreach ($groups as $groupKey => $group) {
            foreach ($scanner->scan($group['local']) as $relPath => $stat) {
                $path = $groupKey . '/' . $relPath;
                $local[$path] = $stat;
                $files[$path] = [
                    'local' => rtrim($group['local'], '/') . '/' . $relPath,
                    'remote' => rtrim($group['remote'], '/') . '/' . $relPath,
                ];
            }
        }

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'id' => $jobId,
            'phase' => 'scan',
            'groups' => $groups,
            'scan_queue' => array_keys($groups),
            'scan_total' => count($groups),
            'scan_done' => 0,
            'local' => $local,
            'remote' => [],
            'files' => $files,
            'paths' => [],
            'compare_total' => 0,
            'compare_done' => 0,
            'rows' => [],
            'baseline' => $this->loadBaseline(),
        ];
        $this->saveJson($this->dataDir . '/check-job.json', $job);

        return ['job_id' => $jobId, 'total' => $job['scan_total'], 'label' => 'Scanning'];
    }

    /**
     * Bước 2-3/3: 1 lần gọi xử lý ĐÚNG 1 đơn vị việc — hoặc quét remote 1
     * group (pha 'scan'), hoặc so sánh 1 batch path (pha 'compare', sau khi
     * pha scan xong). Gọi lặp lại tới khi finished=true. Trả kèm 'label' để
     * UI đổi nhãn progress bar đúng theo pha đang chạy (Scanning -> Comparing).
     *
     * @return array{done:int,total:int,finished:bool,label:string,groups?:array<string,string>,rows?:array<string,array{type:string}>,cold_start?:bool}
     */
    public function stepCheckDiffJob(string $jobId, int $batchSize = self::BATCH_SIZE): array
    {
        $job = $this->loadJson($this->dataDir . '/check-job.json');
        if ($job === null || ($job['id'] ?? null) !== $jobId) {
            throw new \RuntimeException('No such check job (it may have finished, been cancelled, or expired).');
        }

        if ($job['phase'] === 'scan') {
            return $this->stepCheckDiffScan($job);
        }

        return $this->stepCheckDiffCompare($job, $batchSize);
    }

    /** Quét remote của ĐÚNG 1 group còn lại trong scan_queue, gộp kết quả vào job['remote']/job['files']. */
    private function stepCheckDiffScan(array $job): array
    {
        $groupKey = array_shift($job['scan_queue']);
        $group = $job['groups'][$groupKey];

        $scanner = new FileScanner($this->ignorePatterns());
        $ftp = new FtpClient();
        $this->connectFtp($ftp);
        try {
            foreach ($ftp->scan($group['remote'], [$scanner, 'isIgnored']) as $relPath => $stat) {
                $path = $groupKey . '/' . $relPath;
                $job['remote'][$path] = $stat;
                $job['files'][$path] = $job['files'][$path] ?? [
                    'local' => rtrim($group['local'], '/') . '/' . $relPath,
                    'remote' => rtrim($group['remote'], '/') . '/' . $relPath,
                ];
            }
        } finally {
            $ftp->close();
        }

        $job['scan_done']++;

        if (empty($job['scan_queue'])) {
            $job['phase'] = 'compare';
            $job['paths'] = array_values(array_unique(array_merge(array_keys($job['local']), array_keys($job['remote']))));
            $job['compare_total'] = count($job['paths']);
        }

        $this->saveJson($this->dataDir . '/check-job.json', $job);

        return [
            'done' => $job['scan_done'],
            'total' => $job['scan_total'],
            'finished' => false,
            'label' => 'Scanning',
        ];
    }

    /**
     * So sánh 1 batch path (tối đa $batchSize) từ hàng đợi đã xây khi pha
     * scan xong — tái dùng DiffEngine::diff() trên đúng lát cắt local/remote
     * của batch này, kèm hook sameSizeContentDiffers() (tải nội dung remote
     * để hash khi size trùng). Khi hàng đợi rút cạn, lưu kết quả gộp vào
     * last-diff.json (để startSyncJob dùng lại) và xoá check-job.json.
     */
    private function stepCheckDiffCompare(array $job, int $batchSize): array
    {
        $files = $job['files'];
        $rows = $job['rows'];

        $batchPaths = array_splice($job['paths'], 0, $batchSize);

        if (!empty($batchPaths)) {
            $wanted = array_flip($batchPaths);
            $localBatch = array_intersect_key($job['local'], $wanted);
            $remoteBatch = array_intersect_key($job['remote'], $wanted);

            $ftp = new FtpClient();
            $this->connectFtp($ftp);
            try {
                $rows += (new DiffEngine())->diff($localBatch, $remoteBatch, function (string $path) use ($files, $ftp): bool {
                    return $this->sameSizeContentDiffers($files[$path]['local'], $files[$path]['remote'], $ftp);
                });
            } finally {
                $ftp->close();
            }

            $job['compare_done'] += count($batchPaths);
        }

        $job['rows'] = $rows;
        $finished = empty($job['paths']);

        if ($finished) {
            $state = [
                'groups' => $job['groups'],
                'local' => $job['local'],
                'remote' => $job['remote'],
                'baseline' => $job['baseline'],
                'rows' => $rows,
                'checked_at' => time(),
            ];
            $this->saveJson($this->dataDir . '/last-diff.json', $state);
            @unlink($this->dataDir . '/check-job.json');

            $groupLabels = [];
            foreach ($job['groups'] as $key => $group) {
                $groupLabels[$key] = $group['label'];
            }

            return [
                'done' => $job['compare_done'],
                'total' => $job['compare_total'],
                'finished' => true,
                'label' => 'Comparing',
                'groups' => $groupLabels,
                'rows' => $rows,
                'cold_start' => empty($job['baseline']),
            ];
        }

        $this->saveJson($this->dataDir . '/check-job.json', $job);

        return [
            'done' => $job['compare_done'],
            'total' => $job['compare_total'],
            'finished' => false,
            'label' => 'Comparing',
        ];
    }


    /**
     * Bước 1/2 của "Push from Local": chỉ quét LOCAL (như startCheckDiffJob),
     * nhưng lọc NGAY TẠI ĐÂY theo mtime > $sinceMtime — trước khi biết gì về
     * remote. Mục đích: thay vì quét đệ quy TOÀN BỘ remote rồi so hết mọi
     * path như "Check differences", chỉ hỏi FTP về ĐÚNG những file local vừa
     * lọc được (xem stepPushFromLocalJob(), dùng statRemote() cho từng file
     * thay vì FtpClient::scan() đệ quy cả thư mục), giảm hẳn số round-trip
     * FTP khi chỉ có vài file vừa sửa trong 1 cây thư mục lớn.
     *
     * File chỉ tồn tại trên remote (chưa từng có ở local) KHÔNG BAO GIỜ xuất
     * hiện trong kết quả — tính năng này chỉ quan tâm "đẩy cái gì từ local
     * lên", không phát hiện "cái gì hosting có mà local thiếu".
     *
     * @param string[] $kinds Giống startCheckDiffJob().
     * @param int $sinceMtime Unix timestamp — chỉ giữ file local có mtime > mốc này.
     * @return array{job_id:string, total:int, label:string}
     */
    public function startPushFromLocalJob(array $kinds, int $sinceMtime): array
    {
        $groups = $this->resolveGroups($kinds);
        if (empty($groups)) {
            throw new \RuntimeException('No content selected to sync.');
        }

        $scanner = new FileScanner($this->ignorePatterns());

        $local = [];
        $files = [];
        foreach ($groups as $groupKey => $group) {
            foreach ($scanner->scan($group['local']) as $relPath => $stat) {
                if ($stat['mtime'] <= $sinceMtime) {
                    continue;
                }
                $path = $groupKey . '/' . $relPath;
                $local[$path] = $stat;
                $files[$path] = [
                    'local' => rtrim($group['local'], '/') . '/' . $relPath,
                    'remote' => rtrim($group['remote'], '/') . '/' . $relPath,
                ];
            }
        }

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'id' => $jobId,
            'groups' => $groups,
            'local' => $local,
            'remote' => [],
            'files' => $files,
            'paths' => array_keys($local),
            'compare_total' => count($local),
            'compare_done' => 0,
            'rows' => [],
            'baseline' => $this->loadBaseline(),
        ];
        $this->saveJson($this->dataDir . '/push-local-job.json', $job);

        return ['job_id' => $jobId, 'total' => $job['compare_total'], 'label' => 'Comparing'];
    }

    /**
     * Bước 2/2 của "Push from Local": với mỗi path còn lại trong batch, hỏi
     * FTP TRỰC TIẾP (exists/mdtm/size cho đúng 1 file qua statRemote(), KHÔNG
     * quét đệ quy cả thư mục remote như stepCheckDiffScan()) rồi diff ngay.
     * Gọi lặp lại tới khi finished=true; khi xong, ghi kết quả vào
     * last-diff.json giống hệt "Check differences" để nút "Sync now" hiện
     * có tái sử dụng được nguyên vẹn (không cần sửa gì ở luồng sync).
     *
     * @return array{done:int,total:int,finished:bool,label:string,groups?:array<string,string>,rows?:array<string,array{type:string}>}
     */
    public function stepPushFromLocalJob(string $jobId, int $batchSize = self::BATCH_SIZE): array
    {
        $job = $this->loadJson($this->dataDir . '/push-local-job.json');
        if ($job === null || ($job['id'] ?? null) !== $jobId) {
            throw new \RuntimeException('No such push job (it may have finished, been cancelled, or expired).');
        }

        $files = $job['files'];
        $rows = $job['rows'];
        $remote = $job['remote'];

        $batchPaths = array_splice($job['paths'], 0, $batchSize);

        if (!empty($batchPaths)) {
            $ftp = new FtpClient();
            $this->connectFtp($ftp);
            try {
                $localBatch = [];
                $remoteBatch = [];
                foreach ($batchPaths as $path) {
                    $localBatch[$path] = $job['local'][$path];
                    $stat = $this->statRemote($ftp, $files[$path]['remote']);
                    if ($stat !== null) {
                        $remoteBatch[$path] = $stat;
                        $remote[$path] = $stat;
                    }
                }

                $rows += (new DiffEngine())->diff($localBatch, $remoteBatch, function (string $path) use ($files, $ftp): bool {
                    return $this->sameSizeContentDiffers($files[$path]['local'], $files[$path]['remote'], $ftp);
                });
            } finally {
                $ftp->close();
            }

            $job['compare_done'] += count($batchPaths);
        }

        $job['rows'] = $rows;
        $job['remote'] = $remote;
        $finished = empty($job['paths']);

        if ($finished) {
            $state = [
                'groups' => $job['groups'],
                'local' => $job['local'],
                'remote' => $remote,
                'baseline' => $job['baseline'],
                'rows' => $rows,
                'checked_at' => time(),
            ];
            $this->saveJson($this->dataDir . '/last-diff.json', $state);
            @unlink($this->dataDir . '/push-local-job.json');

            $groupLabels = [];
            foreach ($job['groups'] as $key => $group) {
                $groupLabels[$key] = $group['label'];
            }

            return [
                'done' => $job['compare_done'],
                'total' => $job['compare_total'],
                'finished' => true,
                'label' => 'Comparing',
                'groups' => $groupLabels,
                'rows' => $rows,
            ];
        }

        $this->saveJson($this->dataDir . '/push-local-job.json', $job);

        return [
            'done' => $job['compare_done'],
            'total' => $job['compare_total'],
            'finished' => false,
            'label' => 'Comparing',
        ];
    }

    /**
     * Bước 1/2 của "Cleanup Hosting": chuẩn bị hàng đợi group để quét REMOTE
     * (giống pha 'scan' của "Check differences") — không quét local trước
     * như "Push from Local" vì tính năng này đi NGƯỢC HƯỚNG: tìm file có
     * trên HOSTING nhưng KHÔNG (còn) có ở local, để dọn rác trên hosting
     * (file cũ còn sót lại sau khi đổi tên/xoá ở local mà chưa từng dọn).
     * Không cần quét đệ quy local vì việc "có tồn tại ở local hay không"
     * chỉ cần 1 lần file_exists() cho mỗi path tìm thấy trên remote — xem
     * stepCleanupHostingJob().
     *
     * Luôn chỉ áp dụng cho group 'pages' — bất kể Category nào đang được tick
     * trên UI — vì đây là thao tác xoá vĩnh viễn trên hosting và Pages là nơi
     * duy nhất có lưới an toàn dọn rác tự động đi kèm (pruneMarkdownlessPagesDirs()
     * + pruneEmptyDirs() trong stepSyncJob()); các group khác (Themes/Plugins/
     * Config/Accounts) không có lưới an toàn đó nên bị loại khỏi thao tác này.
     *
     * @param int $sinceMtime Unix timestamp — chỉ xét file HOSTING có mtime > mốc này
     *                        (tránh phải liệt kê toàn bộ cây hosting mỗi lần, và tránh
     *                        báo nhầm file vừa mới upload xong ở thao tác khác).
     * @return array{job_id:string, total:int, label:string}
     */
    public function startCleanupHostingJob(int $sinceMtime): array
    {
        $groups = $this->resolveGroups(['pages']);
        if (empty($groups)) {
            throw new \RuntimeException('No content selected to sync.');
        }

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'id' => $jobId,
            'groups' => $groups,
            'scan_queue' => array_keys($groups),
            'scan_total' => count($groups),
            'scan_done' => 0,
            'since_mtime' => $sinceMtime,
            'remote' => [],
            'rows' => [],
            'baseline' => $this->loadBaseline(),
        ];
        $this->saveJson($this->dataDir . '/cleanup-hosting-job.json', $job);

        return ['job_id' => $jobId, 'total' => $job['scan_total'], 'label' => 'Scanning'];
    }

    /**
     * Bước 2/2 của "Cleanup Hosting": quét REMOTE đệ quy ĐÚNG 1 group còn
     * lại trong scan_queue (network round-trip, giống stepCheckDiffScan()),
     * giữ lại CHỈ những path thoả cả 2 điều kiện: mtime hosting > since_mtime
     * VÀ không tồn tại ở local (file_exists() trực tiếp trên path tương ứng —
     * không cần quét local vì chỉ cần biết có/không, không cần mtime/size
     * local). Gọi lặp lại tới khi finished=true; khi xong, ghi kết quả vào
     * last-diff.json (rows toàn 'missing_local') để nút "Sync now" tái dùng
     * nguyên vẹn, UI sẽ tự forceResolution='delete_remote' cho mọi dòng.
     *
     * @return array{done:int,total:int,finished:bool,label:string,groups?:array<string,string>,rows?:array<string,array{type:string}>}
     */
    public function stepCleanupHostingJob(string $jobId, int $batchSize = self::BATCH_SIZE): array
    {
        $job = $this->loadJson($this->dataDir . '/cleanup-hosting-job.json');
        if ($job === null || ($job['id'] ?? null) !== $jobId) {
            throw new \RuntimeException('No such cleanup job (it may have finished, been cancelled, or expired).');
        }

        $groupKey = array_shift($job['scan_queue']);
        $group = $job['groups'][$groupKey];

        $scanner = new FileScanner($this->ignorePatterns());
        $ftp = new FtpClient();
        $this->connectFtp($ftp);
        try {
            $remoteFiles = $ftp->scan($group['remote'], [$scanner, 'isIgnored']);

            foreach ($remoteFiles as $relPath => $stat) {
                if ($stat['mtime'] <= $job['since_mtime']) {
                    continue;
                }
                if (file_exists(rtrim($group['local'], '/') . '/' . $relPath)) {
                    continue;
                }

                $path = $groupKey . '/' . $relPath;
                $job['remote'][$path] = $stat;
                $job['rows'][$path] = ['type' => 'missing_local'];
            }
        } finally {
            $ftp->close();
        }

        $job['scan_done']++;
        $finished = empty($job['scan_queue']);

        if ($finished) {
            $state = [
                'groups' => $job['groups'],
                'local' => [],
                'remote' => $job['remote'],
                'baseline' => $job['baseline'],
                'rows' => $job['rows'],
                'checked_at' => time(),
            ];
            $this->saveJson($this->dataDir . '/last-diff.json', $state);
            @unlink($this->dataDir . '/cleanup-hosting-job.json');

            $groupLabels = [];
            foreach ($job['groups'] as $key => $group) {
                $groupLabels[$key] = $group['label'];
            }

            return [
                'done' => $job['scan_done'],
                'total' => $job['scan_total'],
                'finished' => true,
                'label' => 'Scanning',
                'groups' => $groupLabels,
                'rows' => $job['rows'],
            ];
        }

        $this->saveJson($this->dataDir . '/cleanup-hosting-job.json', $job);

        return [
            'done' => $job['scan_done'],
            'total' => $job['scan_total'],
            'finished' => false,
            'label' => 'Scanning',
        ];
    }

    /**
     * Bước 1/2 của "Pull from Hosting": chuẩn bị hàng đợi group để quét
     * REMOTE (giống pha 'scan' của "Cleanup Hosting") — luôn chỉ áp dụng cho
     * group 'pages' (bất kể Category nào đang được tick trên UI), vì đây là
     * tính năng dành riêng cho Pages theo yêu cầu, đối xứng với "Cleanup
     * Hosting" nhưng NGƯỢC HƯỚNG: tìm file có trên HOSTING để kéo VỀ local,
     * thay vì tìm file thừa trên hosting để xoá.
     *
     * @param int $sinceMtime Unix timestamp — chỉ xét file HOSTING có mtime > mốc này
     *                        (tránh phải liệt kê toàn bộ cây hosting mỗi lần).
     * @return array{job_id:string, total:int, label:string}
     */
    public function startPullHostingJob(int $sinceMtime): array
    {
        $groups = $this->resolveGroups(['pages']);
        if (empty($groups)) {
            throw new \RuntimeException('No content selected to sync.');
        }

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'id' => $jobId,
            'groups' => $groups,
            'scan_queue' => array_keys($groups),
            'scan_total' => count($groups),
            'scan_done' => 0,
            'since_mtime' => $sinceMtime,
            'local' => [],
            'remote' => [],
            'files' => [],
            'rows' => [],
            'baseline' => $this->loadBaseline(),
        ];
        $this->saveJson($this->dataDir . '/pull-hosting-job.json', $job);

        return ['job_id' => $jobId, 'total' => $job['scan_total'], 'label' => 'Scanning'];
    }

    /**
     * Bước 2/2 của "Pull from Hosting": quét REMOTE đệ quy ĐÚNG 1 group còn
     * lại trong scan_queue (network round-trip, giống stepCleanupHostingJob()),
     * giữ path có mtime hosting > since_mtime, rồi stat LOCAL cho từng path đó
     * (rẻ — cùng máy, không cần round-trip FTP) và diff ngay bằng DiffEngine
     * (giống stepPushFromLocalJob(), nhưng theo chiều ngược lại: nguồn liệt kê
     * là remote, không phải local). Vì mọi path xét ở đây đều lấy từ remote đã
     * quét được, kết quả chỉ có thể là 'missing_local' (hosting có, local
     * chưa có) hoặc 'changed' — không bao giờ có 'missing_remote'.
     *
     * Gọi lặp lại tới khi finished=true; khi xong, ghi kết quả vào
     * last-diff.json giống hệt "Cleanup Hosting"/"Push from Local" để nút
     * "Sync now" hiện có tái sử dụng được nguyên vẹn — mỗi dòng mặc định
     * chọn "Use Hosting version" ở UI (forceResolution='remote'), nhưng vẫn
     * có thể đổi từng dòng như bảng "Check differences" thông thường.
     *
     * @return array{done:int,total:int,finished:bool,label:string,groups?:array<string,string>,rows?:array<string,array{type:string}>}
     */
    public function stepPullHostingJob(string $jobId, int $batchSize = self::BATCH_SIZE): array
    {
        $job = $this->loadJson($this->dataDir . '/pull-hosting-job.json');
        if ($job === null || ($job['id'] ?? null) !== $jobId) {
            throw new \RuntimeException('No such pull job (it may have finished, been cancelled, or expired).');
        }

        $groupKey = array_shift($job['scan_queue']);
        $group = $job['groups'][$groupKey];

        $scanner = new FileScanner($this->ignorePatterns());
        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $local = $job['local'];
        $remote = $job['remote'];
        $files = $job['files'];
        $rows = $job['rows'];

        try {
            $remoteFiles = $ftp->scan($group['remote'], [$scanner, 'isIgnored']);

            $localBatch = [];
            $remoteBatch = [];
            foreach ($remoteFiles as $relPath => $stat) {
                if ($stat['mtime'] <= $job['since_mtime']) {
                    continue;
                }

                $path = $groupKey . '/' . $relPath;
                $localFile = rtrim($group['local'], '/') . '/' . $relPath;
                $remoteFile = rtrim($group['remote'], '/') . '/' . $relPath;
                $files[$path] = ['local' => $localFile, 'remote' => $remoteFile];

                $remote[$path] = $stat;
                $remoteBatch[$path] = $stat;

                $localStat = $this->statLocal($localFile);
                if ($localStat !== null) {
                    $local[$path] = $localStat;
                    $localBatch[$path] = $localStat;
                }
            }

            $rows += (new DiffEngine())->diff($localBatch, $remoteBatch, function (string $path) use ($files, $ftp): bool {
                return $this->sameSizeContentDiffers($files[$path]['local'], $files[$path]['remote'], $ftp);
            });
        } finally {
            $ftp->close();
        }

        $job['local'] = $local;
        $job['remote'] = $remote;
        $job['files'] = $files;
        $job['rows'] = $rows;
        $job['scan_done']++;
        $finished = empty($job['scan_queue']);

        if ($finished) {
            $state = [
                'groups' => $job['groups'],
                'local' => $local,
                'remote' => $remote,
                'baseline' => $job['baseline'],
                'rows' => $rows,
                'checked_at' => time(),
            ];
            $this->saveJson($this->dataDir . '/last-diff.json', $state);
            @unlink($this->dataDir . '/pull-hosting-job.json');

            $groupLabels = [];
            foreach ($job['groups'] as $key => $group) {
                $groupLabels[$key] = $group['label'];
            }

            return [
                'done' => $job['scan_done'],
                'total' => $job['scan_total'],
                'finished' => true,
                'label' => 'Scanning',
                'groups' => $groupLabels,
                'rows' => $rows,
            ];
        }

        $this->saveJson($this->dataDir . '/pull-hosting-job.json', $job);

        return [
            'done' => $job['scan_done'],
            'total' => $job['scan_total'],
            'finished' => false,
            'label' => 'Scanning',
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
     * Huỷ 1 job "Full deploy" đang nén dở (nút "Cancel compressing"): xoá
     * file .zip tạm đang ghi (nếu có) và bỏ job state, để không để lại rác
     * trong user/data/ftp-sync/. Không đụng gì tới FTP/hosting — full
     * deploy chỉ nén cục bộ, chưa từng upload gì ở bước này.
     */
    public function cancelFullDeployJob(string $jobId): void
    {
        $job = $this->loadJson($this->dataDir . '/full-deploy-job.json');
        if ($job === null || ($job['id'] ?? null) !== $jobId) {
            throw new \RuntimeException('No such deploy job (it may have already finished or been cancelled).');
        }

        if (!empty($job['zip_path']) && is_file($job['zip_path'])) {
            @unlink($job['zip_path']);
        }

        @unlink($this->dataDir . '/full-deploy-job.json');
    }

    /**
     * Gọi sau khi người dùng đã TỰ TAY upload + giải nén file .zip của
     * "Full deploy" lên hosting — đặt lại baseline cho các group vẫn được
     * "Check differences" theo dõi (pages/config/accounts/theme đang
     * active/plugins).
     *
     * QUAN TRỌNG: phải kết nối FTP và đọc mtime/size THẬT của remote sau
     * khi giải nén — KHÔNG được tự gán remote = local, vì giải nén trên
     * hosting hầu như luôn đặt mtime = giờ giải nén (khác giờ mtime local),
     * dù nội dung file không đổi. Nếu baseline["remote"] chứa 1 giá trị bịa
     * (không khớp mtime FTP trả về), lần "Check differences" kế tiếp vẫn
     * sẽ thấy remote "đổi" so với baseline và báo pull giả y như cũ — đây
     * chính là lỗi của bản trước.
     *
     * Baseline cũ của các group này bị THAY THẾ HOÀN TOÀN cho những path
     * có mặt ở local (không giữ lại giá trị cũ); path chỉ tồn tại 1 bên
     * (chưa deploy lên hosting, hoặc file lạ chỉ có trên hosting) CỐ Ý
     * không được baseline ở đây — để lần check kế tiếp vẫn báo đúng thực
     * trạng (push/pull) thay vì bị che giấu.
     *
     * @return array{groups:int, files:int}
     */
    public function markFullDeploySynced(): array
    {
        $groups = $this->resolveGroups();
        if (empty($groups)) {
            throw new \RuntimeException('No content selected to sync.');
        }

        $scanner = new FileScanner($this->ignorePatterns());
        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $baseline = $this->loadBaseline();
        foreach (array_keys($baseline) as $path) {
            foreach (array_keys($groups) as $groupKey) {
                if (str_starts_with($path, $groupKey . '/')) {
                    unset($baseline[$path]);
                    break;
                }
            }
        }

        $fileCount = 0;
        try {
            foreach ($groups as $groupKey => $group) {
                $local = $scanner->scan($group['local']);
                $remote = $ftp->scan($group['remote'], [$scanner, 'isIgnored']);

                foreach ($local as $relPath => $localStat) {
                    $baseline[$groupKey . '/' . $relPath] = [
                        'local' => $localStat,
                        'remote' => $remote[$relPath] ?? null,
                    ];
                    $fileCount++;
                }
            }
        } finally {
            $ftp->close();
        }

        $this->saveJson($this->dataDir . '/baseline.json', $baseline);

        return ['groups' => count($groups), 'files' => $fileCount];
    }

    /**
     * Bước 1/2: xây hàng đợi thao tác từ kết quả diff đã lưu ở
     * stepCheckDiffJob() (khi finished) + lựa chọn resolution của người
     * dùng, lưu vào sync-job.json.
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
            // Luôn dọn rác Pages ở batch cuối MIỄN LÀ Pages có mặt trong lượt
            // check/cleanup gần nhất — KHÔNG phụ thuộc việc có ops nào thực sự
            // xoá gì hay không, và KHÔNG bị lọc bởi mtime của "Cleanup
            // Hosting" (mtime chỉ ảnh hưởng tới việc 1 file có được LIỆT KÊ
            // thành dòng cho người dùng xem/chọn hay không, không ảnh hưởng
            // tới bước dọn rác này — pruneMarkdownlessPagesDirs() luôn quét
            // lại toàn bộ user/pages từ đầu). Trước đây gate bằng cờ
            // 'pages_dirty' (chỉ bật khi có action xoá pages thực sự) khiến
            // nhiều folder rác có mtime cũ hơn mốc đã chọn không bao giờ được
            // dọn, vì "Cleanup Hosting" không liệt kê được file nào trong đó
            // để tạo ra hành động xoá — nay bỏ hẳn phụ thuộc đó.
            'pages_cleanup_pending' => isset($state['groups']['pages']),
            'pages_cleanup_result' => null,
        ];
        $this->saveJson($this->dataDir . '/sync-job.json', $job);

        return [
            'job_id' => $jobId,
            'total' => $job['total'],
            'has_pages_cleanup' => $job['pages_cleanup_pending'],
        ];
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

            // Dọn "rác" dưới user/pages ngay TRONG batch cuối cùng, tái dùng
            // $ftp đang mở sẵn. Chạy MIỄN LÀ Pages có mặt trong lượt check/
            // cleanup gần nhất (job['groups']['pages']) — không cần biết có
            // ops nào thực sự xoá gì hay không, và không bị ảnh hưởng bởi
            // mtime của "Cleanup Hosting" (xem giải thích ở 'pages_cleanup_pending'
            // trong startSyncJob()). 2 bước, theo đúng thứ tự:
            // 1) pruneMarkdownlessPagesDirs(): thư mục không còn .md nào ở bất
            //    kỳ đâu bên trong (dù vẫn còn sót ảnh/asset khác) không còn là
            //    trang Grav hợp lệ nữa -> xoá nguyên cả cụm (có backup trước).
            // 2) pruneEmptyDirs(): dọn thư mục rỗng TUYỆT ĐỐI (0 file) — chạy
            //    SAU bước 1 vì xoá nguyên cụm ở bước 1 có thể khiến thư mục
            //    cha của nó trở nên rỗng hẳn; đồng thời đây cũng là lưới an
            //    toàn cho thư mục vốn đã rỗng từ trước (0 file thì bước 1
            //    không thấy được, vì nó chỉ lần theo danh sách FILE để suy ra
            //    thư mục cha nào có nội dung).
            if (empty($job['ops']) && !empty($job['pages_cleanup_pending']) && isset($groups['pages'])) {
                $deletedGarbageDirs = $this->pruneMarkdownlessPagesDirs($backup, $ftp, $groups['pages']['remote']);
                foreach ($deletedGarbageDirs as $relDir) {
                    $prefixedPath = 'pages/' . $relDir;
                    foreach (array_keys($baseline) as $baselineKey) {
                        if ($baselineKey === $prefixedPath || str_starts_with($baselineKey, $prefixedPath . '/')) {
                            unset($baseline[$baselineKey]);
                        }
                    }
                }
                $ftp->pruneEmptyDirs($groups['pages']['remote']);
                $job['pages_cleanup_result'] = ['deleted_dirs' => count($deletedGarbageDirs)];
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
            'pages_cleanup' => $finished ? $job['pages_cleanup_result'] : null,
        ];
    }

    /**
     * On-demand diff cho ĐÚNG 1 cặp thư mục (thường là 1 thư mục trang duy
     * nhất) — không qua job/batch vì thường chỉ có vài file, không cần
     * progress bar, và không đụng gì tới baseline.json (baseline chỉ dành
     * cho "Check differences" toàn site). Dùng bởi tính năng "Check FTP
     * Sync" theo từng dòng trong bảng content của plugin easy-content-manager.
     *
     * @return array<string, array{type:string, newer?:?string, local:?array{mtime:int,size:int}, remote:?array{mtime:int,size:int}}>
     */
    public function checkPathDiff(string $localDir, string $remoteDir): array
    {
        $scanner = new FileScanner($this->ignorePatterns());
        $local = $scanner->scan($localDir);

        $ftp = new FtpClient();
        $this->connectFtp($ftp);
        $remote = [];
        try {
            $remote = $ftp->scan($remoteDir, [$scanner, 'isIgnored']);

            $rows = (new DiffEngine())->diff($local, $remote, function (string $relPath) use ($localDir, $remoteDir, $ftp): bool {
                return $this->sameSizeContentDiffers($localDir . '/' . $relPath, $remoteDir . '/' . $relPath, $ftp);
            });
        } finally {
            $ftp->close();
        }

        foreach ($rows as $relPath => &$row) {
            $row['local'] = $local[$relPath] ?? null;
            $row['remote'] = $remote[$relPath] ?? null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Áp dụng resolution cho ĐÚNG 1 cặp thư mục (xem checkPathDiff()) — thực
     * thi ngay, đồng bộ, không job/batch. Cùng vocabulary với startSyncJob():
     * 'local'=>push, 'remote'=>pull, 'delete_local', 'delete_remote' (giá trị
     * khác/thiếu = bỏ qua). Có backup trước khi ghi đè/xoá, giống hệt
     * stepSyncJob(), nhưng KHÔNG cập nhật baseline.json — tính năng này
     * không liên quan tới baseline của "Check differences" toàn site.
     *
     * @param array<string,string> $resolutions relPath => local|remote|delete_local|delete_remote
     * @return array{applied:int, skipped:int, errors: array<string,string>, backup: ?string}
     */
    public function applyPathResolutions(string $localDir, string $remoteDir, array $resolutions): array
    {
        $ftp = new FtpClient();
        $this->connectFtp($ftp);

        $backup = ($this->config['backup_enabled'] ?? true) ? new BackupManager($this->dataDir . '/backups') : null;

        $applied = 0;
        $skipped = 0;
        $errors = [];

        try {
            foreach ($resolutions as $relPath => $resolution) {
                $action = $this->resolveAction(is_string($resolution) ? $resolution : null);
                if ($action === null) {
                    $skipped++;
                    continue;
                }

                $localFile = rtrim($localDir, '/') . '/' . $relPath;
                $remoteFile = rtrim($remoteDir, '/') . '/' . $relPath;

                try {
                    if ($action === 'push') {
                        $this->backupRemote($backup, $ftp, $relPath, $remoteFile);
                        $ftp->upload($localFile, $remoteFile);
                    } elseif ($action === 'pull') {
                        $backup?->addLocalFile($relPath, $localFile);
                        $ftp->download($remoteFile, $localFile);
                    } elseif ($action === 'delete_remote') {
                        $this->backupRemote($backup, $ftp, $relPath, $remoteFile);
                        $ftp->delete($remoteFile);
                    } elseif ($action === 'delete_local') {
                        $backup?->addLocalFile($relPath, $localFile);
                        @unlink($localFile);
                    }
                    $applied++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[$relPath] = $e->getMessage();
                }
            }
        } finally {
            $ftp->close();
        }

        $backupResult = $backup?->finish();

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'errors' => $errors,
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

    /**
     * Dọn "rác" trong user/pages trên hosting: bất kỳ thư mục nào KHÔNG CÒN
     * chứa file .md ở bất kỳ độ sâu nào bên trong nó — dù vẫn còn sót file
     * khác (ảnh, asset...) — không còn là 1 trang Grav hợp lệ nữa (Grav bắt
     * buộc mỗi trang phải có tối thiểu 1 file .md), nên xoá LUÔN CẢ CỤM thư
     * mục đó (kể cả file còn sót), có backup trước như mọi lượt xoá khác.
     * Mạnh hơn pruneEmptyDirs() (không đòi hỏi thư mục phải rỗng tuyệt đối),
     * nhưng KHÔNG thay thế được nó: 1 thư mục rỗng tuyệt đối (0 file) không
     * để lại dấu vết gì trong danh sách file quét được nên hàm này không
     * thấy được — vẫn cần gọi pruneEmptyDirs() sau đó (xem stepSyncJob()).
     *
     * Quét TOÀN BỘ user/pages 1 lần (relPath => stat), tách 2 tập hợp:
     * - $pageDirs: thư mục CHỨA TRỰC TIẾP ít nhất 1 file .md (thư mục trang
     *   thật). Chạm tới thư mục này khi đi từ gốc xuống nghĩa là mọi thứ bên
     *   dưới nó (kể cả lồng sâu hơn, VD "images/" chứa ảnh) đều là tài sản
     *   CỦA TRANG ĐÓ — phải dừng lại, coi là an toàn, không đi tiếp xuống
     *   dưới. Thiếu bước dừng này sẽ xoá NHẦM thư mục ảnh hợp lệ của 1 trang
     *   thật chỉ vì bản thân "images/" không tự chứa .md nào.
     * - $ancestorsOfPageDirs: thư mục là tổ tiên (ở bất kỳ độ sâu nào) của 1
     *   $pageDirs — nghĩa là bên trong nó (ở nhánh khác, sâu hơn) vẫn còn ít
     *   nhất 1 trang thật, nên KHÔNG được xoá nguyên cả cụm (sẽ xoá nhầm
     *   trang đó), phải đi tiếp xuống sâu hơn để tìm đúng nhánh rác cụ thể.
     *
     * Với mỗi file còn lại, đi từ thư mục gốc xuống (topmostDirWithoutMarkdown()):
     * gặp $pageDirs trước -> dừng, an toàn; gặp thư mục không nằm trong cả 2
     * tập trên trước -> đó là ranh giới "rác" cao nhất, xoá đúng 1 lần cho
     * cả cụm thay vì lặp lại cho từng file con bên trong nó.
     *
     * @return string[] Danh sách relPath (so với $pagesRemoteRoot) các thư mục đã bị xoá — để
     *         caller dọn nốt các key baseline liên quan (xem stepSyncJob()).
     */
    private function pruneMarkdownlessPagesDirs(?BackupManager $backup, FtpClient $ftp, string $pagesRemoteRoot): array
    {
        $scanner = new FileScanner($this->ignorePatterns());
        $allFiles = $ftp->scan($pagesRemoteRoot, [$scanner, 'isIgnored']);

        $pageDirs = [];
        $ancestorsOfPageDirs = [];
        foreach (array_keys($allFiles) as $relPath) {
            if (strtolower(substr($relPath, -3)) !== '.md') {
                continue;
            }
            $dir = dirname($relPath);
            if ($dir === '.' || $dir === '') {
                continue;
            }
            $pageDirs[$dir] = true;

            $ancestor = dirname($dir);
            while ($ancestor !== '.' && $ancestor !== '' && !isset($ancestorsOfPageDirs[$ancestor])) {
                $ancestorsOfPageDirs[$ancestor] = true;
                $ancestor = dirname($ancestor);
            }
        }

        $toDelete = [];
        foreach (array_keys($allFiles) as $relPath) {
            $dir = dirname($relPath);
            if ($dir === '.' || $dir === '') {
                continue;
            }
            $garbageDir = $this->topmostDirWithoutMarkdown($dir, $pageDirs, $ancestorsOfPageDirs);
            if ($garbageDir !== null) {
                $toDelete[$garbageDir] = true;
            }
        }

        $deleted = array_keys($toDelete);
        foreach ($deleted as $relDir) {
            $remoteDir = rtrim($pagesRemoteRoot, '/') . '/' . $relDir;
            $this->backupRemoteDir($backup, $ftp, $relDir, $remoteDir);
            $ftp->removeDirRecursive($remoteDir);
        }

        return $deleted;
    }

    /**
     * Đi từ thư mục cao nhất xuống $relDir: gặp $pageDirs trước -> null (an
     * toàn, đã chạm vào thư mục trang thật, mọi thứ sâu hơn thuộc về nó);
     * gặp thư mục không thuộc cả $pageDirs lẫn $ancestorsOfPageDirs trước ->
     * trả về đó (ranh giới "rác" nông nhất); ngược lại tiếp tục xuống sâu hơn.
     */
    private function topmostDirWithoutMarkdown(string $relDir, array $pageDirs, array $ancestorsOfPageDirs): ?string
    {
        $parts = explode('/', $relDir);
        $current = '';
        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            if (isset($pageDirs[$current])) {
                return null;
            }
            if (!isset($ancestorsOfPageDirs[$current])) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Backup TOÀN BỘ nội dung 1 thư mục remote trước khi xoá đệ quy cả thư
     * mục đó (dùng bởi pruneMarkdownlessPagesDirs()) — quét lại đệ quy
     * $remoteDir (network round-trip riêng, chấp nhận được vì đây là thao
     * tác xoá cả folder, ít xảy ra và luôn cần backup trước theo đúng cam
     * kết của plugin), rồi backup từng file con qua backupRemote() sẵn có.
     */
    private function backupRemoteDir(?BackupManager $backup, FtpClient $ftp, string $relDir, string $remoteDir): void
    {
        if (!$backup) {
            return;
        }
        $scanner = new FileScanner($this->ignorePatterns());
        foreach ($ftp->scan($remoteDir, [$scanner, 'isIgnored']) as $childRelPath => $stat) {
            $this->backupRemote($backup, $ftp, $relDir . '/' . $childRelPath, rtrim($remoteDir, '/') . '/' . $childRelPath);
        }
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

    /**
     * Chỉ được DiffEngine gọi khi local/remote đã cùng size — size bằng
     * nhau không đảm bảo nội dung giống nhau (vd đổi "admin.super" ->
     * "admin.pages", cùng 11 ký tự, tổng size file không đổi). Tải file
     * remote về tạm rồi so hash CRC32 với file local — chỉ tốn 1 lượt
     * download cho đúng những cặp "nghi ngờ" này (size trùng), không phải
     * toàn bộ cây file, để "Check differences" không biến thành tải hết
     * site về qua FTP.
     */
    private function sameSizeContentDiffers(string $localPath, string $remotePath, FtpClient $ftp): bool
    {
        if (!is_file($localPath)) {
            return false;
        }

        $localHash = hash_file('crc32b', $localPath);

        $tmp = tempnam(sys_get_temp_dir(), 'ftp-sync-hash-');
        try {
            $ftp->download($remotePath, $tmp);
            $remoteHash = hash_file('crc32b', $tmp);
        } catch (\Throwable $e) {
            // Không tải được (mất kết nối tạm thời, quyền đọc...) — thà báo
            // thừa 1 file "changed" (người dùng tự xem lại) còn hơn âm thầm
            // bỏ sót 1 thay đổi thật.
            return true;
        } finally {
            @unlink($tmp);
        }

        return $localHash !== $remoteHash;
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

    /**
     * Đọc 1 field config dạng list, hỗ trợ CẢ 2 shape Grav có thể lưu:
     * - commalist (VD ignore_patterns): mảng phẳng các chuỗi giá trị.
     * - checkboxes + "use: keys" (VD sync_plugins): map {optionKey =>
     *   true/false} cho MỌI option chứ KHÔNG PHẢI mảng phẳng tên đã chọn —
     *   xem comment tương tự ở SimpleMultiLanguageSitePlugin::multilang_templates
     *   (cùng field type). Trước đây hàm này chỉ xử lý shape đầu, khiến
     *   sync_plugins (sau khi đổi sang checkboxes) đọc nhầm value (true/false
     *   -> "1") thay vì key (tên plugin) — gộp toàn bộ plugin đã chọn thành
     *   1 group rác "plugin:1" trỏ tới thư mục không tồn tại, "Check
     *   differences" vì vậy luôn báo im lặng "không có gì khác biệt".
     */
    private function configList(string $key): array
    {
        $raw = $this->config[$key] ?? [];
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);

        $list = [];
        foreach ($raw as $optionKey => $item) {
            if (is_bool($item)) {
                if ($item) {
                    $list[] = trim((string) $optionKey);
                }
                continue;
            }
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
