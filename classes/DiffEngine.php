<?php

namespace Grav\Plugin\FtpSync;

/**
 * So sánh trực tiếp map local / remote hiện tại (không qua baseline nữa).
 *
 * - Path chỉ tồn tại ở 1 bên -> 'missing_remote' (chưa có trên hosting)
 *   hoặc 'missing_local' (chưa có ở local).
 * - Path tồn tại ở cả 2 bên -> trước tiên xét `size`: khác nhau thì luôn
 *   coi là 'changed' ngay (không cần đọc nội dung). Bằng nhau thì KHÔNG
 *   còn tự động bỏ qua nữa — size bằng nhau không đảm bảo nội dung giống
 *   nhau (vd đổi "admin.super" -> "admin.pages", 2 chuỗi cùng 11 ký tự,
 *   tổng size file không đổi dù nội dung khác hẳn). Gọi tiếp
 *   $sameSizeDiffers($path) (nếu có) để xác nhận qua hash nội dung; chỉ
 *   khi hook đó xác nhận có khác mới coi là 'changed'. Không truyền hook
 *   (null) thì giữ nguyên hành vi cũ (size bằng nhau -> bỏ qua), dùng cho
 *   nơi chưa cần/không có khả năng đọc nội dung để hash.
 * - Khi coi là 'changed', mtime chỉ dùng để suy ra bên nào mới hơn
 *   ('newer', để UI tô xanh) chứ không quyết định có đổi hay không. Nếu
 *   mtime cũng bằng nhau (hiếm) -> 'newer' = null.
 */
class DiffEngine
{
    /**
     * @param array<string,array{mtime:int,size:int}> $local
     * @param array<string,array{mtime:int,size:int}> $remote
     * @param (callable(string $path): bool)|null $sameSizeDiffers Gọi CHỈ khi
     *        size local/remote bằng nhau, để xác nhận qua hash nội dung xem
     *        có thực sự khác nhau không (size bằng nhau không đảm bảo nội
     *        dung giống nhau). Trả về true nếu khác. Bỏ qua (null) để giữ
     *        hành vi cũ: size bằng nhau -> luôn coi là không đổi.
     * @return array<string, array{type:string, newer?:?string}> relPath => ['type' => missing_remote|missing_local|changed, 'newer'? => local|remote|null]
     */
    public function diff(array $local, array $remote, ?callable $sameSizeDiffers = null): array
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

            if ($cl['size'] === $cr['size']) {
                if ($sameSizeDiffers === null || !$sameSizeDiffers($path)) {
                    continue;
                }
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
