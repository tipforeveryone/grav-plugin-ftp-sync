<?php

namespace Grav\Plugin\FtpSync;

/**
 * So sánh map local / remote hiện tại với baseline (giá trị mtime+size của
 * MỖI BÊN tại lần sync thành công gần nhất — không phải 1 giá trị chung,
 * vì sau khi push, mtime của file trên remote do server tự đặt lúc upload,
 * không thể ép bằng mtime local qua FTP thường).
 *
 * Không dùng hash (xem plan) — chỉ mtime+size, nên lần chạy đầu (chưa có
 * baseline) mọi path tồn tại ở cả 2 bên mà khác nhau đều coi là 'conflict'
 * để bắt người dùng xác nhận tay 1 lần; từ lần 2 mới suy luận được hướng
 * thay đổi dựa trên baseline.
 */
class DiffEngine
{
    /**
     * @param array<string,array{mtime:int,size:int}> $local
     * @param array<string,array{mtime:int,size:int}> $remote
     * @param array<string,array{local:?array,remote:?array}> $baseline
     * @return array<string, array{type:string}> relPath => ['type' => push|pull|conflict|deleted_local|deleted_remote]
     */
    public function diff(array $local, array $remote, array $baseline): array
    {
        $result = [];
        $paths = array_unique(array_merge(array_keys($local), array_keys($remote), array_keys($baseline)));

        foreach ($paths as $path) {
            $cl = $local[$path] ?? null;
            $cr = $remote[$path] ?? null;
            $bl = $baseline[$path]['local'] ?? null;
            $br = $baseline[$path]['remote'] ?? null;

            $localDeleted = $bl !== null && $cl === null;
            $remoteDeleted = $br !== null && $cr === null;

            if ($localDeleted && $remoteDeleted) {
                continue; // đã xoá cả 2 bên từ trước, baseline biết rồi -> không có gì để báo
            }
            if ($localDeleted) {
                $result[$path] = ['type' => 'deleted_local'];
                continue;
            }
            if ($remoteDeleted) {
                $result[$path] = ['type' => 'deleted_remote'];
                continue;
            }

            $localChanged = !$this->same($cl, $bl);
            $remoteChanged = !$this->same($cr, $br);

            if (!$localChanged && !$remoteChanged) {
                continue;
            }
            if ($localChanged && $remoteChanged) {
                $result[$path] = ['type' => 'conflict'];
            } elseif ($localChanged) {
                $result[$path] = ['type' => 'push'];
            } else {
                $result[$path] = ['type' => 'pull'];
            }
        }

        return $result;
    }

    private function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return $a['size'] === $b['size'] && $a['mtime'] === $b['mtime'];
    }
}
