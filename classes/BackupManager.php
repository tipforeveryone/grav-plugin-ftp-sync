<?php

namespace Grav\Plugin\FtpSync;

/**
 * Backup nội dung file TRƯỚC khi nó bị ghi đè/xoá, gói vào 1 file zip theo
 * timestamp của lượt sync. Mỗi file được thêm vào zip dưới dạng
 * "{side}/{relPath}" (side = local|remote) để biết bản nào đến từ đâu.
 */
class BackupManager
{
    private \ZipArchive $zip;
    private string $zipPath;
    private bool $hasEntries;

    /**
     * $existingZipPath/$hasEntries cho phép "mở lại" 1 zip đã tạo ở batch
     * trước (dùng cho sync/upload theo tiến trình nhiều request — mỗi
     * request phải flush() zip xuống đĩa rồi request sau mở lại đúng file
     * đó để nối thêm entry, vì ZipArchive không sống được qua nhiều request).
     */
    public function __construct(string $backupDir, ?string $existingZipPath = null, bool $hasEntries = false)
    {
        $this->zip = new \ZipArchive();

        if ($existingZipPath !== null) {
            $this->zipPath = $existingZipPath;
            $this->hasEntries = $hasEntries;
            $this->zip->open($this->zipPath);
            return;
        }

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $this->zipPath = rtrim($backupDir, '/') . '/' . date('Y-m-d_His') . '.zip';
        $this->hasEntries = false;
        $this->zip->open($this->zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    }

    public function addLocalFile(string $relPath, string $absolutePath): void
    {
        if (is_file($absolutePath)) {
            $this->zip->addFile($absolutePath, 'local/' . $relPath);
            $this->hasEntries = true;
        }
    }

    public function addRemoteContent(string $relPath, string $content): void
    {
        $this->zip->addFromString('remote/' . $relPath, $content);
        $this->hasEntries = true;
    }

    public function zipPath(): string
    {
        return $this->zipPath;
    }

    public function hasEntries(): bool
    {
        return $this->hasEntries;
    }

    /** Flush entries xuống đĩa mà KHÔNG quyết định giữ/xoá file — dùng giữa các batch. */
    public function flush(): void
    {
        $this->zip->close();
    }

    /** Đóng hẳn ở batch cuối; xoá file zip rỗng nếu từ đầu tới cuối không backup gì cả. */
    public function finish(): ?string
    {
        $this->zip->close();

        if (!$this->hasEntries) {
            @unlink($this->zipPath);
            return null;
        }

        return $this->zipPath;
    }
}
