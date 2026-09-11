# 10. Báo cáo xác minh — 07/09/2026

[← Mục lục](README.md)

## Cơ sở

Code gốc đã đối chiếu từ `main` tại commit `73811c39a8ab58f9fdd00b7193e80560d2243fe7`. Bản cập nhật này bổ sung bridge v2 và tài liệu tiếng Việt; không đổi plugin version tổng thể `1.0.0-rc21`.

Phân biệt ba nguồn: code đọc trực tiếp từ GitHub; kết quả connector đọc thật trong phiên làm việc; báo cáo của developer VPS do chủ dự án cung cấp. Không dùng báo cáo cũ thay cho một lần test bản mới.

## Baseline đã gọi thực từ ChatGPT

Discovery category Design Core trả `success=true`, `count=35`. Đã execute `design-core__get-site-status` thành công, không chỉ discover.

| Trường | Kết quả tại lần đọc |
|---|---|
| Owner API | available/enabled/owner_claimed = true |
| Số business operations | 42 |
| WordPress | 7.1 |
| Elementor / Elementor Pro | 4.2.4 / 4.2.1 |
| Editor mode | v3 |
| Môi trường Design Core | staging |
| Remote writes | enabled |
| Figma configured | false |
| Browser analysis | false |
| Visual correction supported | true, là báo cáo capability chứ không phải E2E pass |
| Production readiness | production-candidate, không phải production-ready |

Chưa xác minh topology/container/IP/cấu hình proxy trực tiếp trên VPS trong phiên này. Domain do chủ dự án cung cấp không được dùng để tự suy ra môi trường hoặc đường dẫn repo.

## Thay đổi code

- `core/mcp-ability-bridge.php`: map 42 operations; owner/capability checks ở permission và execution; schema validation, giới hạn 2 MB; concrete route IDs; scopes/audit actor phía server; metadata thận trọng; `mcp_bridge` diagnostics.
- `core/wordpress-mcp-compatibility.php`: giữ 5 tên cũ, kiểm tra cùng owner/capability trước đọc/preview.
- `core/agent-gateway.php`: giữ 3 meta-abilities nhưng chặn legacy generic mutation qua ability. Đường REST/admin execute hiện có không bị viết lại.
- Tests standalone, script đọc runtime, generator API docs, checker links và workflow CI mới.
- Bộ hướng dẫn tiếng Việt, catalogue tham số và kế hoạch triển khai/xác minh.

Không sửa core plugin miniOrange, không đổi OAuth/NHI grants/tokens/roles, không đổi `.env`, Docker, DNS, firewall hoặc database website. Không apply/publish/trash trang trên website trong phiên này.

## Kiểm thử thực sự đã chạy ở môi trường làm việc

Runtime test local: PHP CLI **8.4.23**. Các source dependency chép để chạy test đã đối chiếu Git blob SHA với snapshot GitHub, không thay bằng API giả rồi nhận là source thật.

| Kiểm tra | Kết quả |
|---|---|
| PHP lint các file PHP được chuẩn bị | PASS |
| `php tests/mcp-abilities/run.php` | 1.749 assertions, 0 failures |
| Generator JSON | 42 operation, 42 tên canonical |
| `php tools/export-mcp-contract.php --check` | PASS |
| Link checker tiếng Việt | PASS: 12 tệp, 36 liên kết nội bộ, 0 lỗi |

Tests dùng WordPress/service doubles. Một số integration assertions gọi controller, Idempotency Store và Write Guard thật nhưng storage/service nghiệp vụ giả lập. Chúng không chứng minh SQL, render Elementor, browser, OAuth hoặc concurrent traffic thực.

Con số 79/64/523 assertions trong báo cáo developer trước thuộc lần triển khai trước. Không tính lại thành kết quả pass của lần này. Full project regression, PHP matrix trên GitHub Actions và runtime đọc/ghi bản mới phải được kiểm tra riêng trên checkout/môi trường đầy đủ.

## Trạng thái sau chuẩn bị code

| Hạng mục | Trạng thái bằng chứng |
|---|---|
| Code canonical parity 42/42 | Đã kiểm thử standalone |
| 42 canonical + 8 compatibility registration | Đã kiểm thử standalone |
| Owner-only ở các Design Core abilities đã sửa | Đã kiểm thử permission/execute bằng doubles |
| Baseline ChatGPT discovery35 và site-status | Đã gọi thật trước deploy bản mới |
| VPS chạy bridge v2 | Chưa xác minh |
| NHI cấp thêm 15 operation | Chưa thực hiện; cần operator chọn phạm vi |
| ChatGPT discovery/execute bản mới | Chưa xác minh |
| WordPress/Elementor write E2E bản mới | Chưa chạy |
| Visual/Figma E2E bản mới | Chưa chạy |
| Toàn bộ website “chỉ owner truy cập” | Không đưa kết luận này |

## Rủi ro và việc tiếp theo

Owner-lock mới có thể chặn connection đang dùng một admin khác với owner đã claim. Phải đối chiếu identity trước deploy; không tự chuyển owner hoặc nâng role.

Scopes của in-process MCP lấy từ user WordPress, không phải token `dcapi_*`. Expiry/rate/resource của OAuth do connector quản lý; không tự kế thừa rate/environment token-binding của Bearer API. Các plugin/REST/MCP tools khác trên website vẫn có policy riêng.

NHI previously reported 256 tools không đồng nghĩa tất cả đều thuộc Design Core hoặc đã được bản này kiểm soát. Không tuyên bố an toàn toàn site chỉ từ 42 canonical operations. Media SSRF, CORS, concurrent idempotency và rollback tất cả WordPress mutations không được audit đầy đủ trong phạm vi này.

Operator triển khai theo [02](02-cai-dat-vps.md), chạy [07](07-kiem-thu-phat-hanh.md), rồi thực hiện discovery/execute thật từ ChatGPT. Ghi SHA deploy và kết quả mới bổ sung vào báo cáo; không đổi trạng thái “chưa chạy” thành “pass” vì code đã push.
