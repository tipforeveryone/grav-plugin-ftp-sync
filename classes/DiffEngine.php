<?php

namespace Grav\Plugin\FtpSync;

/**
 * So sánh trực tiếp map local / remote hiện tại (không qua baseline nữa).
 *
 * - Path chỉ tồn tại ở 1 bên -> 'missing_remote' (chưa có trên hosting)
 *   hoặc 'missing_local' (chưa có ở local).
 * - Path tồn tại ở cả 2 bên -> so `size` trước (ưu tiên); nếu size bằng
 *   nhau mới xét tới `mtime`. Khác 1 trong 2 -> 'changed', kèm 'newer' =
 *   bên có mtime lớn hơn (để UI tô xanh bên mới hơn). Nếu mtime bằng nhau
 *   (không suy luận được bên nào mới hơn) -> 'newer' = null.
 */
class DiffEngine
{
    /**
     * @param array<string,array{mtime:int,size:int}> $local
     * @param array<string,array{mtime:int,size:int}> $remote
     * @return array<string, array{type:string, newer?:?string}> relPath => ['type' => missing_remote|missing_local|changed, 'newer'? => local|remote|null]
     */
    public function diff(array $local, array $remote): array
    {
        $result = [];
        $paths = array_unique(array_merge(array_keys($local), array_keys($remote)));

        foreach ($paths as $path) {
            $cl = $local[$path] ?? null;
            $cr = $remote[$path] ?? null;

            if ($cl === null) {
                $result[$path] = ['type' => 'missing_local'];
                continue;
            }
            if ($cr === null) {
                $result[$path] = ['type' => 'missing_remote'];
                continue;
            }

            if ($cl['size'] === $cr['size'] && $cl['mtime'] === $cr['mtime']) {
                continue; // không khác gì -> không cần báo
            }

            $result[$path] = ['type' => 'changed', 'newer' => $this->newerSide($cl, $cr)];
        }

        return $result;
    }

    /** @param array{mtime:int,size:int} $local @param array{mtime:int,size:int} $remote */
    private function newerSide(array $local, array $remote): ?string
    {
        if ($local['mtime'] > $remote['mtime']) {
            return 'local';
        }
        if ($remote['mtime'] > $local['mtime']) {
            return 'remote';
        }
        return null;
    }
}
