# 08. Xử lý lỗi

[← Mục lục](README.md)

## Discovery rỗng

`success: true, count: 0` chỉ cho biết lớp discovery trả một danh sách rỗng. Không đủ để kết luận site không có plugin, user sai role hoặc bridge bị hỏng.

Kiểm tra theo thứ tự: đúng site/endpoint → OAuth/resource → NHI thực tế → quyền được cấp → registry runtime → permission của ability. So sánh timestamp, request ID, endpoint, ID nội bộ không nhạy cảm, user/role và số grant; không log Authorization/token/cookie.

Báo cáo triển khai trước có hai vấn đề ở những giai đoạn khác nhau: NHI lưu snapshot chưa bao gồm abilities mới và OAuth resource cũ khi đổi permalink. Không mặc định một nguyên nhân giải thích mọi lần `count=0`.

`wp_get_abilities()` có thể kích hoạt đăng ký lazy của framework; không gọi `do_action('wp_abilities_api_init')` tùy tiện nhiều lần để chữa triệu chứng.

## Có 35 thay vì 50 tên

Có thể VPS vẫn chạy bridge v1 hoặc grant vẫn ở snapshot cũ. Đọc `get-site-status`/`get-manifest` để kiểm tra `mcp_bridge`. GitHub có commit mới không chứng minh VPS đã cập nhật.

Sau bridge v2, code map 42 operation. Giữ 8 ability tương thích tạo 50 tên đăng ký tiềm năng. NHI giới hạn quyền có thể trả ít hơn là đúng thiết kế. Không tự nâng quyền chỉ để đủ count.

## Các lỗi thường gặp

| Lỗi/tình huống | Hướng kiểm tra |
|---|---|
| `design_core_ability_auth_required` | Connector chưa resolve user WordPress đã xác thực |
| `design_core_api_disabled` | Owner API đang tắt; v2 bridge cũng bị chặn |
| `design_core_api_owner_unclaimed` | Chưa claim bằng tài khoản owner dự kiến |
| `design_core_api_owner_required` | User của connection không phải owner đã claim |
| `design_core_ability_scope_forbidden` | Owner thiếu capability Design Core cần thiết |
| `design_core_remote_writes_disabled` | Kill switch đang chặn ghi; không phải lỗi token |
| `design_core_legacy_write_disabled` | Đang dùng generic `call-tool` để ghi; chuyển sang scoped ability |
| Input/schema error | Kiểm tra alias, field bắt buộc, boolean thật, range/unknown fields |
| `design_core_idempotency_conflict` | Cùng key nhưng route/object/payload khác; đọc trạng thái và tạo thao tác mới |
| In-progress idempotency | Request trước chưa kết thúc; không bắn lặp nhiều key để ép chạy |
| Preview/hash/conflict error | Ticket sai/hết hạn/trang đã thay đổi; đọc lại rồi preview mới |
| `401` ở protected REST | Kiểm tra Bearer hợp lệ và header được forward; không fallback cookie |
| `429` | Hạn mức ở lớp tương ứng; không giả định rate limit Bearer áp dụng cho OAuth |

Status HTTP cụ thể và mã lỗi phải lấy từ runtime. Những lỗi nghiệp vụ trả đúng không chứng minh chức năng thành công: ví dụ preview Figma báo thiếu cấu hình là validation đúng nhưng tác vụ chưa hoàn thành.

## REST pretty URL và OAuth

So sánh đường `/wp-json/design-core/v1/openapi` với `/?rest_route=/design-core/v1/openapi`. Nếu chỉ query route hoạt động, kiểm tra WordPress rewrite/permalink và reverse proxy trước khi thay code auth.

Endpoint MCP hiện được operator báo là `/wp-json/mosmcp/v1/mcp`; verify URL cấu hình thực tế. Đổi resource/permalink có thể khiến token cũ không còn khớp. Dùng reconnect/authorize chính thức khi cần; không bỏ kiểm tra audience, forge token hoặc chép token giữa client.

Discovery metadata đúng chưa chứng minh token đang dùng được cấp cho resource đó. Thu hồi token ảnh hưởng connection đang hoạt động; cần được operator cho phép và có kế hoạch authorize lại.

## Widget/schema thiếu

Kiểm tra plugin Pro active, version runtime, experiment/editor mode và widget thực tế. Một widget không đăng ký trong runtime không được bổ sung “schema giả” để làm preview chạy. `source`, `q`, `limit`, `detail` phải đúng kiểu theo catalog.

Container Elementor không nhất thiết là widget. Không gọi widget schema với tên suy đoán rồi coi lỗi là toàn bộ Elementor không hoạt động.

## Figma và browser

`figma_configured=false`: cấu hình credential phía server bằng cơ chế dự án hỗ trợ; không gửi vào chat. Connector Figma của ChatGPT không tự chia sẻ secret cho WordPress.

`browser_analysis=false`: xác minh toolchain/browser và sandbox runtime; đọc `core/browser-analysis-service.php` và tài liệu fidelity hiện có trước cấu hình. Không cài browser/process vào VPS mù hoặc mở outbound mạng không giới hạn.

Không có browser evidence thì chưa chứng minh visual correction end-to-end, dù code report `visual_correction_supported=true`.

## Media import/Trash

URL bị chặn có thể đúng chính sách chống SSRF. Không thay URL thành localhost, disable TLS hoặc bỏ private-IP guard. Với redirect/MIME/size errors, kiểm tra nguồn công khai, giới hạn và quyền media.

Trash không đồng nghĩa force-delete. Khi runtime không hỗ trợ workflow mong muốn, báo hạn chế thay vì chuyển sang API xóa vĩnh viễn.

## Hướng dẫn báo lỗi

Báo commit đang chạy, plugin version, site/environment, ability hoặc operationId, request ID/time, status/error code, payload đã loại nội dung nhạy cảm và bước tái hiện. Ghi phần nào đã thực hiện có khả năng ghi dữ liệu. Không đính kèm `.env`, token hash, access/refresh token, cookie, DB dump hoặc toàn bộ logs chứa secrets.
