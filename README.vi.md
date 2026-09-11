# Design Core Elementor — tài liệu tiếng Việt

Design Core Elementor là lớp phân tích và biên dịch thiết kế cho WordPress/Elementor. Mục tiêu là chuyển HTML/CSS, Design IR, nguồn Figma và các mẫu section thành trang Elementor có thể chỉnh sửa, đồng thời giữ khả năng tái sử dụng, kiểm tra kết quả và hoàn tác.

**Bắt đầu tại [mục lục tài liệu tiếng Việt](docs/vi/README.md).** Tài liệu tiếng Anh và các báo cáo trước đây vẫn được giữ nguyên trong repository; không coi mọi mô tả lịch sử là trạng thái triển khai hiện tại.

## Phiên bản và phạm vi

Plugin đang dùng nhánh phiên bản `1.0.0-rc21`. Đợt cập nhật này nâng MCP Ability Bridge lên **version 2**, phủ **42/42 Owner API operations**, bổ sung kiểm tra owner cho abilities, kiểm thử transport và tài liệu tiếng Việt. Tám ability cũ vẫn được đăng ký để giữ tên tương thích; generic dispatcher không còn được dùng để ghi dữ liệu.

Đây không phải tuyên bố đã hoàn thành mọi chức năng của WordPress hoặc Elementor Pro. Quản lý Theme Builder, Forms, Popup, WooCommerce, ACF và sửa từng element cần hợp đồng riêng khi chưa có trong catalog.

## Ba cách truy cập cần phân biệt

| Giao diện | Xác thực | Vai trò |
|---|---|---|
| Owner REST API `design-core/v1` | Bearer `dcapi_*` của owner | 42 operation cho client REST |
| WordPress abilities qua MCP connector | OAuth/identity của connector, user WordPress và owner-lock trong bridge | Cùng nghiệp vụ, không truyền `dcapi_*` cho model |
| REST/Agent/MCP cũ | Cơ chế quyền riêng của lớp cũ | Tương thích; không tự động trở thành owner-only trên toàn site |

Không cần sửa mã nguồn miniOrange để thêm nghiệp vụ Design Core: plugin Design Core đăng ký abilities. Connector chịu trách nhiệm xác thực và cấp quyền công cụ. Đăng ký ability không đồng nghĩa tự cấp quyền NHI.

## Quy trình thiết kế

```text
Đọc site và widget schema thực tế
  → lập kế hoạch
  → tạo trang nháp khi cần
  → preview
  → người dùng duyệt
  → apply đúng preview_id + plan_hash
  → verify
  → so sánh hình ảnh nếu đủ công cụ
  → hoàn tác khi cần
  → chỉ publish khi được yêu cầu rõ ràng
```

Không ghi trực tiếp `_elementor_data`, không đoán control và không thay thế trang Elementor bằng generic `post_content`.

## Các tài liệu chính

- [Kiến trúc và mã nguồn](docs/vi/01-kien-truc.md)
- [Vận hành trên VPS/Docker hiện hữu](docs/vi/02-cai-dat-vps.md)
- [Danh mục đủ 42 API/abilities](docs/vi/03-api-mcp.md)
- [Kết nối MCP, OAuth và NHI](docs/vi/04-ket-noi-mcp.md)
- [Bảo mật và ranh giới quyền](docs/vi/05-bao-mat.md)
- [Quy trình dựng và chỉnh trang](docs/vi/06-quy-trinh-thiet-ke.md)
- [Kiểm thử và phát hành](docs/vi/07-kiem-thu-phat-hanh.md)
- [Chẩn đoán lỗi](docs/vi/08-xu-ly-loi.md)
- [Khả năng còn thiếu và lộ trình](docs/vi/09-lo-trinh.md)
- [Bằng chứng của đợt cập nhật](docs/vi/10-bao-cao-xac-minh.md)

## Phát triển

```bash
php tests/mcp-abilities/run.php
php tools/export-mcp-contract.php --check
php tools/export-mcp-contract.php --json
```

Bộ test trên là test contract/transport với các thành phần WordPress được giả lập; không thay thế kiểm thử OAuth thật, Elementor thật hoặc kiểm thử ghi dữ liệu trên VPS.

Sau khi sửa catalog, sinh lại tài liệu bằng:

```bash
php tools/export-mcp-contract.php --write
```

Không đưa token, cookie, khóa SSH, mật khẩu database hoặc nội dung `.env` vào Git, chat hay báo cáo. Commit trên GitHub không tự chứng minh VPS đã nhận bản mới.
