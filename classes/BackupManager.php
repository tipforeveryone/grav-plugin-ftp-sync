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
    private bool $hasEntries = false;

    public function __construct(string $backupDir)
    {
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $this->zipPath = rtrim($backupDir, '/') . '/' . date('Y-m-d_His') . '.zip';
        $this->zip = new \ZipArchive();
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

    /** Đóng zip; xoá file zip rỗng nếu cuối cùng không backup gì cả. */
    public function close(): ?string
    {
        $this->zip->close();

        if (!$this->hasEntries) {
            @unlink($this->zipPath);
            return null;
        }

        return $this->zipPath;
    }
}
