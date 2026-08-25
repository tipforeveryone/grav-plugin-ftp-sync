<?php

namespace Grav\Plugin\FtpSync;

/**
 * Quét đệ quy 1 thư mục local, trả về map relPath => [mtime, size].
 * relPath dùng '/' (không phụ thuộc OS) và không có dấu '/' ở đầu.
 */
class FileScanner
{
    /** @var string[] mẫu glob để bỏ qua, áp dụng theo từng segment của path */
    private array $ignorePatterns;

    public function __construct(array $ignorePatterns)
    {
        $this->ignorePatterns = $ignorePatterns;
    }

    /**
     * @return array<string, array{mtime:int,size:int}>
     */
    public function scan(string $baseDir): array
    {
        $baseDir = rtrim($baseDir, '/');
        if (!is_dir($baseDir)) {
            return [];
        }

        $result = [];
        $this->scanDir($baseDir, '', $result);
        return $result;
    }

    private function scanDir(string $baseDir, string $relDir, array &$result): void
    {
        $fullDir = $relDir !== '' ? $baseDir . '/' . $relDir : $baseDir;
        $items = @scandir($fullDir) ?: [];

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relPath = $relDir !== '' ? $relDir . '/' . $name : $name;
            if ($this->isIgnored($relPath)) {
                continue;
            }

            $fullPath = $fullDir . '/' . $name;
            if (is_dir($fullPath)) {
                $this->scanDir($baseDir, $relPath, $result);
            } else {
                $result[$relPath] = [
                    'mtime' => filemtime($fullPath) ?: 0,
                    'size'  => filesize($fullPath) ?: 0,
                ];
            }
        }
    }

    /**
     * Mẫu chứa '/' được khớp với TOÀN BỘ relPath (VD "user/config/security.yaml"
     * chỉ loại đúng file đó, không đụng tới "system/config/security.yaml").
     * Mẫu không có '/' vẫn khớp theo từng segment như cũ (hỗ trợ '*' qua fnmatch).
     */
    public function isIgnored(string $relPath): bool
    {
        $segments = explode('/', $relPath);
        foreach ($this->ignorePatterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '/')) {
                if (fnmatch($pattern, $relPath)) {
                    return true;
                }
                continue;
            }

            foreach ($segments as $segment) {
                if (fnmatch($pattern, $segment)) {
                    return true;
                }
            }
        }
        return false;
    }
}
