# 07. Kiểm thử và phát hành

[← Mục lục](README.md)

## Các tầng bằng chứng

| Tầng | Chứng minh | Không chứng minh |
|---|---|---|
| PHP lint | Cú pháp hợp lệ ở phiên bản PHP chạy lệnh | Logic đúng, tương thích mọi phiên bản |
| Standalone contract/transport | Mapping, schema, quyền, request và một số guard | WordPress DB, Elementor render, OAuth thực |
| WordPress runtime read | Plugin load, registry và callback đọc trong WP thật | NHI/OAuth/public proxy/ChatGPT |
| Connector read E2E | ChatGPT → OAuth → NHI → ability đọc thật | Write/publish/rollback đúng |
| Disposable write E2E | Luồng ghi đã thử trên bản nháp cụ thể | Mọi widget, mọi trạng thái đồng thời |
| Visual gate | Kết quả đo tại viewport/reference cụ thể | “Hoàn hảo” với mọi thiết kế |

Không đổi tên “mock pass” thành “runtime pass”. HTTP 200 có thể chứa `isError: true`; phải kiểm tra cả payload.

## Lệnh standalone

Tại thư mục gốc repository:

```bash
find core tests/mcp-abilities tools -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/mcp-abilities/run.php
php tools/export-mcp-contract.php --check
python3 tools/check-vietnamese-docs.py
```

Bộ mới dùng `tests/mcp-abilities/bootstrap.php` riêng. Nó sử dụng controller Owner, Idempotency Store và Write Guard thật, nhưng WordPress storage, schema-validator subset và service nghiệp vụ được thay bằng test doubles. Không cần WordPress hoặc database cho bộ này.

Các nội dung kiểm tra gồm 42 operation, method/path/callback thực tế, schema/alias, 27 tên cũ, khả năng giả mạo principal, owner/capability/disabled, kích thước input, confirm, idempotency route chứa ID thật, publish capability truyền xuống service và chặn generic legacy write.

`tools/export-mcp-contract.php` có ba chế độ:

```bash
php tools/export-mcp-contract.php --json
php tools/export-mcp-contract.php --write
php tools/export-mcp-contract.php --check
```

`--write` chỉ sinh lại `docs/vi/03-api-mcp.md`. Không ghi dữ liệu WordPress. Sau khi đổi catalog phải cập nhật mô tả tiếng Việt cho operation mới, regenerate và commit tài liệu. CI phải thất bại khi mapping/schema/docs lệch nhau.

Chạy thêm những bộ có sẵn phù hợp với thay đổi, ví dụ:

```bash
php tests/gpt-actions-api/run.php
php tests/api-platform/run.php
php tests/reference-integration/run.php
```

Các command này là yêu cầu regression cho checkout đầy đủ. Không coi chúng đã pass chỉ vì tài liệu có liệt kê hoặc báo cáo cũ từng pass.

## CI

Workflow `owner-mcp-parity.yml` chạy lint, standalone bridge tests, kiểm tra tài liệu sinh tự động và liên kết Markdown nội bộ. Matrix PHP 8.1/8.4 giúp kiểm tra baseline và runtime mới hơn.

CI xanh không có nghĩa VPS đã pull hoặc NHI đã nhận quyền mới. Job chưa được runner khởi động là **chưa chạy**, không phải pass hay lỗi assertion.

## Kiểm tra đọc trên WordPress thật

Chỉ chạy trong môi trường đã xác định đúng repo/container và dùng đúng API owner. Ví dụ cấu trúc lệnh, thay ID thật:

```bash
wp --user=OWNER_USER_ID eval-file \
  wp-content/plugins/design-core-elementor/tests/mcp-abilities/runtime-read.php
```

Trong Docker, đặt lệnh tương ứng trong đúng service/container có WP-CLI và volume của WordPress. Không tạo service identity mới hoặc sửa quyền để làm test xanh.

Script gọi lazy ability registry, kiểm tra đủ 42 tên và thực thi một số read callbacks qua `WP_Ability::execute()`. Chỉ xuất count/status/error code, không dump nội dung site. Kết quả luôn phân biệt `connector_e2e_verified=false` và `write_e2e_verified=false`.

## Connector E2E sau deploy

Sau khi deploy code, operator kiểm tra NHI của connection thực tế. Chỉ cấp thêm tên ability nằm trong phạm vi đã được chủ website đồng ý; không tự grant mọi operation hoặc đổi role thành administrator.

Từ ChatGPT thực hiện discovery, đọc manifest/status rồi site map/catalog. Cần kiểm tra:

- `mcp_bridge.version=2`, `parity=true`, mapped count=42;
- dữ liệu trả về đúng site/environment;
- các tên mới có trong connection nếu đã được cấp grant;
- không có lỗi bên trong `data`, `content` hoặc `structuredContent`.

**50 là số tên Design Core có thể được đăng ký nếu giữ 8 tên cũ; không bắt buộc mọi NHI phải nhìn thấy đủ 50.** Quyền tối thiểu có thể cố ý làm discovery ít hơn.

## Write E2E riêng trên draft

Trước test cần backup/rollback path, explicit approval, tài khoản phù hợp, remote-write switch và danh sách trang thử. Không sửa trang chủ/trang đang dùng để thử.

Test tạo draft → preview → apply với ticket/hash → verify → history → rollback → verify lại. Tạo payload lỗi để kiểm tra missing confirm, wrong ticket/hash, thiếu capability, key lặp/payload khác. Mô phỏng một concurrent edit riêng để kiểm tra stale preview; không chỉ thử rollback hai lần.

Với WordPress management mới, test trên tài nguyên thử riêng: create/update/Trash/restore content, menu item trong menu thử, media không quan trọng. Không mặc định Elementor History bao phủ chúng. Ghi kết quả từng endpoint; trường hợp dependency thiếu phải SKIPPED/BLOCKED có lý do.

## Phát hành và rollback code

Ghi SHA trước/sau, kiểm tra worktree và code runtime (bind mount hay image), backup dữ liệu trước thay đổi quan trọng. Deploy bằng quy trình VPS hiện có; không chạy Compose local mẫu để thay stack thật.

Sau deploy kiểm tra plugin/load/logs, Owner REST anonymous vẫn bị chặn, connector read và global write guard. Chỉ sau đó test disposable write. Nếu lỗi permission do owner không khớp, sửa connection bằng quy trình operator, không nới kiểm tra owner.

Rollback code phải giữ dữ liệu người dùng, không dùng `git reset --hard` hoặc `docker compose down -v` để “sửa nhanh”. Việc tắt Owner API trong bridge v2 chặn cả canonical abilities và các compatibility abilities đã gia cố; muốn dừng ghi nhưng tiếp tục đọc, dùng remote-write switch.
