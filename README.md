# FTP Sync

Plugin Grav cung cấp 1 trang riêng trong Admin Panel để đồng bộ nội dung site giữa môi trường local (DDEV) và hosting qua FTP/FTPS — không cần Git/SSH trên hosting, phù hợp cho các gói hosting shared chỉ có FTP.

## Tính năng

- **Check differences**: quét local + hosting, so khớp theo `mtime` + `size`, phát hiện file mới/đã xoá/xung đột (sửa ở cả 2 bên) theo từng nhóm nội dung (Pages, Themes, Plugins, Config, Accounts).
- **Sync now**: áp dụng lựa chọn của bạn cho từng file (giữ bản Local / giữ bản Hosting / xoá 1 trong 2 bên) sau khi đã Check differences.
- **Replace local sync to hosting**: xoá toàn bộ file hiện có trên hosting (trong các nhóm đã chọn) rồi upload lại toàn bộ từ local — dùng khi muốn "làm mới" hosting theo đúng local.
- **Full deploy to hosting**: bỏ qua hoàn toàn checkbox/cấu hình chọn lọc — tự quét **toàn bộ site** (`system/`, `vendor/`, mọi nội dung `user/`, file gốc `index.php`/`.htaccess`...), loại trừ đúng những gì không ảnh hưởng đến việc site chạy được (file dev/test/docs, cache runtime, `.git`...). Xoá file tương ứng trên hosting, nén toàn bộ phần còn lại thành **1 file `.zip` duy nhất**, upload lên gốc hosting. Dùng khi deploy site lên hosting mới hoàn toàn hoặc khi hosting đã "hỏng/rác" cần làm mới sạch từ đầu. **Bạn cần tự giải nén file zip này trên hosting** (File Manager / SSH) — plugin không tự động giải nén.
- **Backup tự động**: trước khi ghi đè hoặc xoá bất kỳ file nào trên hosting, bản cũ được tự động nén vào `user/data/ftp-sync/backups/*.zip`. Có thể xem/xoá các bản backup ngay trong trang Admin.
- **Progress bar theo tiến trình thật**: các thao tác upload/sync nhiều file được chia thành nhiều batch nhỏ (AJAX tuần tự), không bị timeout với site lớn.
- **Tự phát hiện môi trường local**: chỉ cho phép chạy khi phát hiện thư mục `.ddev/` ở gốc project — trên bản deploy thật ở hosting, mọi thao tác của plugin tự động bị khoá (không bao giờ dùng thông tin FTP từ chính server production).

## Yêu cầu

- Grav >= 1.7.0, kèm plugin **Admin**.
- PHP extension `ftp` (bật sẵn trong hầu hết cấu hình PHP).
- Tài khoản FTP/FTPS còn hiệu lực trên hosting đích.
- Chạy trên môi trường local có `.ddev/` (hoặc bật `force_allow_remote` nếu thực sự muốn bỏ qua kiểm tra này — không khuyến khích).
- Quyền `admin.super` trên tài khoản Admin đang đăng nhập.

## Cài đặt

Plugin nằm sẵn trong `user/plugins/ftp-sync/` của repo này (không qua GPM). Sau khi kéo code về, chỉ cần đảm bảo plugin đang **Enabled** trong Admin Panel (`Admin > Plugins > FTP Sync`).

## Cấu hình

Vào `Admin > Plugins > FTP Sync`, điền các mục:

| Field | Mô tả |
|---|---|
| **Plugin status** | Bật/tắt toàn bộ plugin |
| **Allow running even when not detected as local** | Bỏ qua kiểm tra môi trường local — **không khuyến khích**, chỉ bật nếu bạn hiểu rõ rủi ro |
| **Auto-backup before overwriting** | Bật/tắt cơ chế backup tự động trước khi ghi đè/xoá |
| **Root directory on hosting** | Đường dẫn FTP tuyệt đối trùng với webroot của site trên hosting, vd `/public_html/eznotary` |
| **Plugins to sync** | Danh sách plugin (trong `user/plugins/`) muốn đồng bộ qua "Sync now"/"Replace local sync" — để trống = tự động đồng bộ TẤT CẢ plugin. Không ảnh hưởng "Full deploy" (luôn lấy hết). |
| **File/folder patterns to skip when syncing** | Danh sách pattern loại trừ, hỗ trợ `*`, áp dụng cho "Check differences"/"Sync now"/"Replace local sync" và cũng được "Full deploy" tôn trọng thêm |
| **FTP Host / Port / Username / Password** | Thông tin kết nối FTP |
| **Use FTPS** | Bật nếu hosting yêu cầu FTP over SSL |
| **Passive mode** | Hầu hết hosting shared cần bật passive mode |

## Cách dùng

1. Vào **Admin > FTP Sync** (menu bên trái, icon trao đổi ⇄).
2. Chọn các nhóm nội dung muốn thao tác ở khung bên trái: **Pages / Themes / Plugins / Config / Accounts**.
3. Chọn hành động ở khung bên phải:
   - **Check differences** → xem danh sách file khác biệt → chọn hành động cho từng dòng (hoặc bulk-apply theo nhóm chọn) → **Sync now**.
   - **Replace local sync to hosting** → xác nhận trong hộp thoại cảnh báo → hosting sẽ khớp 100% với local (trong các nhóm đã chọn).
   - **Full deploy to hosting** → xác nhận trong hộp thoại cảnh báo → toàn bộ site được đóng gói thành 1 file `.zip` và upload lên gốc hosting → **bạn tự giải nén** file đó trên hosting.
4. **Show backups** → xem/xoá các bản backup đã tạo tự động.

### Lưu ý khi dùng "Full deploy to hosting"

- Rất phù hợp cho lần deploy đầu tiên lên hosting mới, hoặc khi cần "reset" hosting về đúng trạng thái local.
- File `.zip` được đặt tên `deploy-<ngày giờ>.zip` ngay tại thư mục gốc hosting — giải nén đè lên (không cần tạo thư mục con), rồi xoá file zip đi.
- Đảm bảo hosting hỗ trợ PHP tương thích (khuyến nghị PHP >= 8.1, khớp với môi trường build local) và có sẵn extension `zip` để tự giải nén được.
- Sau khi giải nén, vào `/admin` kiểm tra trang "Essential Folders" (nếu có plugin `problems`) để chắc các thư mục `cache/logs/tmp/backup/images/assets` tồn tại và ghi được.

## Bảo mật

- Mật khẩu FTP lưu dạng plaintext trong config (`user/config/plugins/ftp-sync.yaml`) — file này **luôn bị loại trừ** khỏi mọi lần sync/deploy để tránh vô tình đẩy lên hosting.
- Các file nhạy cảm khác (`security.yaml`, `security-private.php`, `versions.yaml`) cũng bị loại trừ mặc định.
- Mọi thao tác ghi (upload/xoá) đều bị khoá hoàn toàn nếu plugin phát hiện đang chạy trên môi trường không phải local (`.ddev/`) — trừ khi chủ động bật `force_allow_remote`.

## Tác giả

**tipforeveryone** — MIT License.
