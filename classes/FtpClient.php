<?php

namespace Grav\Plugin\FtpSync;

/**
 * Wrapper mỏng quanh ext-ftp native (ftp_*). Chỉ implement đúng những gì
 * SyncManager cần: connect, liệt kê đệ quy (mtime+size), get/put/delete,
 * tạo thư mục đệ quy.
 */
class FtpClient
{
    /** @var resource|\FTP\Connection|null */
    private $conn;

    public function connect(string $host, int $port, string $username, string $password, bool $ssl, bool $passive): void
    {
        $this->conn = $ssl
            ? @ftp_ssl_connect($host, $port, 10)
            : @ftp_connect($host, $port, 10);

        if (!$this->conn) {
            throw new \RuntimeException("Could not connect to FTP at {$host}:{$port}");
        }

        if (!@ftp_login($this->conn, $username, $password)) {
            throw new \RuntimeException('Wrong FTP username/password.');
        }

        ftp_pasv($this->conn, $passive);
    }

    public function close(): void
    {
        if ($this->conn) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }

    /**
     * Quét đệ quy 1 thư mục remote, trả về map relPath => ['mtime' => int, 'size' => int].
     * relPath dùng '/' và KHÔNG có dấu '/' ở đầu, tương tự FileScanner::scan().
     *
     * @param callable $isIgnored fn(string $relPath): bool
     * @return array<string, array{mtime:int,size:int}>
     */
    public function scan(string $remoteBase, callable $isIgnored): array
    {
        $remoteBase = rtrim($remoteBase, '/');
        $result = [];
        $this->scanDir($remoteBase, '', $isIgnored, $result);
        return $result;
    }

    private function scanDir(string $remoteBase, string $relDir, callable $isIgnored, array &$result): void
    {
        $remotePath = $remoteBase . ($relDir !== '' ? '/' . $relDir : '');
        $entries = @ftp_mlsd($this->conn, $remotePath);

        if ($entries === false) {
            // Server không hỗ trợ MLSD -> fallback NLIST + thử chdir để biết là file/dir.
            $entries = $this->fallbackList($remotePath);
        }

        foreach ($entries as $entry) {
            $name = $entry['name'];
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relPath = $relDir !== '' ? $relDir . '/' . $name : $name;
            if ($isIgnored($relPath)) {
                continue;
            }

            if ($entry['type'] === 'dir') {
                $this->scanDir($remoteBase, $relPath, $isIgnored, $result);
            } else {
                $result[$relPath] = [
                    'mtime' => $entry['mtime'] ?? $this->parseMlsdModify($entry['modify'] ?? ''),
                    'size'  => (int) ($entry['size'] ?? 0),
                ];
            }
        }
    }

    /** MLSD trả "modify" dạng "YYYYMMDDHHMMSS[.fff]" theo UTC (RFC 3659), không phải unix timestamp. */
    private function parseMlsdModify(string $modify): int
    {
        if ($modify === '') {
            return 0;
        }
        $date = \DateTime::createFromFormat('YmdHis', substr($modify, 0, 14), new \DateTimeZone('UTC'));
        return $date ? $date->getTimestamp() : 0;
    }

    /**
     * @return array<int, array{name:string,type:string,mtime:int,size:int}>
     */
    private function fallbackList(string $remotePath): array
    {
        $names = @ftp_nlist($this->conn, $remotePath) ?: [];
        $entries = [];
        foreach ($names as $fullName) {
            $name = basename($fullName);
            if ($name === '.' || $name === '..') {
                continue;
            }
            $size = @ftp_size($this->conn, $fullName);
            $isDir = $size === -1;
            $entries[] = [
                'name'  => $name,
                'type'  => $isDir ? 'dir' : 'file',
                'mtime' => $isDir ? 0 : (int) @ftp_mdtm($this->conn, $fullName),
                'size'  => $isDir ? 0 : (int) $size,
            ];
        }
        return $entries;
    }

    public function download(string $remotePath, string $localPath): void
    {
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!@ftp_get($this->conn, $localPath, $remotePath, FTP_BINARY)) {
            throw new \RuntimeException("Could not download remote file: {$remotePath}");
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->ensureRemoteDir(dirname($remotePath));

        error_clear_last();
        $ok = @ftp_put($this->conn, $remotePath, $localPath, FTP_BINARY);
        if (!$ok) {
            $reason = error_get_last()['message'] ?? 'unknown FTP error';
            throw new \RuntimeException("Could not upload file to remote: {$remotePath} ({$reason})");
        }
    }

    public function delete(string $remotePath): void
    {
        @ftp_delete($this->conn, $remotePath);
    }

    public function exists(string $remotePath): bool
    {
        return @ftp_size($this->conn, $remotePath) !== -1;
    }

    public function sizeOf(string $remotePath): int
    {
        $size = @ftp_size($this->conn, $remotePath);
        return $size === -1 ? 0 : $size;
    }

    public function modifiedTime(string $remotePath): int
    {
        $mtime = @ftp_mdtm($this->conn, $remotePath);
        return $mtime === -1 ? 0 : $mtime;
    }

    /**
     * Xoá đệ quy TOÀN BỘ nội dung bên trong $remoteDir (file + thư mục con),
     * nhưng KHÔNG xoá chính $remoteDir (để upload lại vào đó không cần tạo
     * lại thư mục gốc). Dùng cho "Upload toàn bộ Local lên Hosting" — gọi
     * trước khi upload để đảm bảo hosting không còn rác từ trạng thái cũ.
     */
    public function deleteTree(string $remoteDir): void
    {
        $remoteDir = rtrim($remoteDir, '/');
        $entries = @ftp_mlsd($this->conn, $remoteDir);
        if ($entries === false) {
            $entries = $this->fallbackList($remoteDir);
        }

        foreach ($entries as $entry) {
            $name = $entry['name'];
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $remoteDir . '/' . $name;
            if ($entry['type'] === 'dir') {
                $this->deleteTree($path);
                @ftp_rmdir($this->conn, $path);
            } else {
                @ftp_delete($this->conn, $path);
            }
        }
    }

    /**
     * Tạo thư mục remote đệ quy, bỏ qua nếu đã tồn tại. Nếu 1 segment vừa
     * không chdir được vừa không mkdir được (quyền, quota, tên không hợp
     * lệ...), ném lỗi rõ ràng ngay tại đây thay vì để nó âm thầm trôi tới
     * lúc ftp_put thất bại ở bước sau với thông báo mơ hồ.
     */
    public function ensureRemoteDir(string $remoteDir): void
    {
        $remoteDir = trim($remoteDir, '/');
        if ($remoteDir === '') {
            return;
        }

        $path = '';
        foreach (explode('/', $remoteDir) as $part) {
            $path .= '/' . $part;
            if (@ftp_chdir($this->conn, $path)) {
                continue;
            }

            error_clear_last();
            $mkdirOk = @ftp_mkdir($this->conn, $path);
            if ($mkdirOk === false && !@ftp_chdir($this->conn, $path)) {
                $reason = error_get_last()['message'] ?? 'unknown FTP error';
                throw new \RuntimeException("Could not create remote directory: {$path} ({$reason})");
            }
        }
    }
}
