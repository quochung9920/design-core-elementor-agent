# 01. Kiến trúc và bản đồ mã nguồn

## Mục tiêu và nguyên tắc

Design Core không phải bộ chuyển HTML trực tiếp thành `_elementor_data`. Nó phân tích đầu vào, tạo biểu diễn trung gian, lựa chọn cách dựng, tạo preview và chỉ ghi qua lớp persistence có kiểm tra.

Ưu tiên phần tử Elementor native trước custom/HTML; tái sử dụng trước tạo lại; control runtime trước CSS phụ trợ; giữ nội dung/media của instance tách khỏi danh tính reusable master. Một widget tồn tại không đồng nghĩa mọi control của widget đó được phép ghi ở mọi phiên bản.

## Luồng biên dịch

```text
Design tokens / HTML / CSS / Figma / Section Recipe / Page Shell
    ↓
Token Pipeline / CSS AST / Figma Normalization
    ↓
Canonical Design IR v4
    ↓
Responsive normalization và Layout Intelligence
    ↓
Section Intelligence, fingerprint và registry
    ↓
BuildPlan → preview và mô phỏng
    ↓
Elementor adapter → Runtime Setting Governor
    ↓
Persistence: save → invalidate → reload → render → verify
    ↓
Page Manifest / Snapshot / Visual Feedback / History
```

**Design IR** giữ cấu trúc, nội dung, bố cục và bằng chứng thiết kế. Nó không phải hợp đồng dữ liệu riêng của Elementor V4/Atomic.

**Section Registry** phân biệt tạo mới, tái sử dụng và biến thể. Preview phải báo các lựa chọn và điều kiện an toàn. Không giả định mọi kế hoạch tái sử dụng đều đã có cùng mức fidelity trong mọi đường apply.

**BuildPlan Simulator** ước lượng cấu trúc Elementor theo chiến lược, không chỉ đếm một node IR thành một widget.

**Persistence Service** là ranh giới lưu V3 có kiểm soát: chụp trạng thái trước, lưu qua Elementor Document API, làm mới cache, đọc lại, render, kiểm tra và ghi history khi thành công. V4/Atomic phải có public capability phù hợp; không đoán định dạng private.

**Visual Feedback/Correction** sử dụng ảnh và bằng chứng DOM/style khi môi trường hỗ trợ. Có lớp correction trong code không đồng nghĩa browser tooling đã hoạt động. Chỉ sửa control được runtime xác nhận.

## Các lớp truy cập

```text
Client REST
  → Owner API Bearer authentication
  → capability / owner / credential checks
  → Owner REST controller
  → nghiệp vụ Design Core

ChatGPT
  → MCP connector OAuth và quyền NHI
  → WordPress ability
  → MCP bridge owner + capability + schema checks
  → cùng Owner REST controller
  → cùng nghiệp vụ Design Core
```

Bridge tạo `WP_REST_Request` nội bộ; nó không gọi HTTP loopback, không giữ token `dcapi_*`, không lặp lại nghiệp vụ CRUD, BuildPlan hoặc persistence. Vì không đi qua REST permission callback, nó phải tự kiểm tra user/owner/capability trước khi gọi controller.

Bridge version 2 đưa đúng ID vào route nội bộ, tách path/query/body và tạo principal từ user thực tế. Client không được cung cấp principal, scopes hoặc owner ID. Đây cũng là điều kiện để fingerprint idempotency phân biệt hai đối tượng khác nhau.

## Bản đồ mã nguồn chính

| Nhóm | Điểm đọc đầu tiên |
|---|---|
| Bootstrap, load order, hook | `design-core-elementor.php`, `includes/class-plugin.php` |
| Canonical Owner API | `core/gpt-actions-api.php` |
| Route và điều phối Owner API | `core/gpt-actions-rest-controller.php` |
| Claim/bật tắt Owner API | `core/api-access-settings.php` |
| Bearer credentials và kiểm tra token | `core/api-credential-registry.php`, `core/api-credential-auth.php` |
| WordPress abilities | `core/mcp-ability-bridge.php` |
| Các ability tương thích | `core/wordpress-mcp-compatibility.php`, `core/agent-gateway.php` |
| Quyền, remote write, idempotency | `core/design-core-capabilities.php`, `core/remote-write-guard.php`, `core/idempotency-store.php` |
| Site Intelligence | `core/site-intelligence-rest-controller.php` |
| Design Intelligence | `core/design-intelligence-rest-controller.php` |
| WordPress CRUD/menu/media | `core/wordpress-owner-service.php` |
| Ghi Elementor và lịch sử | `core/persistence-service.php`, `core/change-ledger.php` |
| Kiến thức control thực tế | `core/control-schema-registry.php` |
| Test bridge | `tests/mcp-abilities/` |

Các điểm vào này phục vụ đọc code; không phải danh sách toàn bộ class trong dự án.

## Thành phần tùy chọn

Elementor Pro mở thêm widget tùy runtime và giấy phép. Figma cần cấu hình transport/token riêng. CSS AST ưu tiên dependency Composer Sabberworm khi có; checkout không có vendor dùng đường fallback giới hạn theo code.

WordPress Abilities API được phát hiện tại runtime. Connector miniOrange và official WordPress MCP Adapter là hai thành phần khác nhau; cài một thành phần không chứng minh thành phần còn lại đã active.

Node MCP server trong `mcp-server/` là đường tương thích riêng của dự án. Không cần dựng thêm nó chỉ để WordPress connector gọi abilities đã có.

## Giao diện quản trị

Các màn hình hiện có bao gồm dashboard, import HTML, Build Preview, Widget Inspector, Page Intelligence, Design Intelligence, registry, history, agent bridge, Remote Access, Settings và Production Readiness. Owner API có màn hình API Access để claim owner và quản lý credentials.

Dùng Widget Inspector để đối chiếu control trước khi sửa. Dùng API Access và Remote Access đúng vai trò: khóa Owner API và khóa remote writes là hai trạng thái khác nhau.

## Phạm vi của bản cập nhật

Bản này hoàn thiện transport parity và tài liệu, không đổi schema Design IR, không tự migrate database, không thay reverse proxy và không thay phương thức lưu Elementor. Các API quản trị Theme Builder/Forms/Popup/ACF chưa có không được “bù” bằng ghi meta tùy ý.
