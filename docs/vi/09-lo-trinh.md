# 09. Phạm vi và lộ trình

[← Mục lục](README.md)

## Đã có trong code của lần cập nhật này

Bridge v2 map đủ 42 operation Owner REST API sang 42 canonical WordPress abilities, giữ 8 tên compatibility, tái sử dụng controller/services, kiểm tra owner/capability/input tại execution, thêm báo cáo parity và các tests transport.

Đây là **parity với API hiện có**, không phải thêm 42 khả năng nghiệp vụ hoàn toàn mới. API giữ nguyên namespace/version; bridge có version riêng. Việc sử dụng được qua connection thật còn phụ thuộc deploy, owner, NHI grant và dependency.

Tài liệu danh mục/schema được sinh từ code và có kiểm tra drift. Tài liệu vận hành bổ sung hướng dẫn VPS, OAuth, giới hạn bảo mật và quy trình bàn giao.

## Điều cần hoàn thành ở môi trường triển khai

Đọc [báo cáo xác minh](10-bao-cao-xac-minh.md) để biết phần đã có bằng chứng. Tại lần đọc baseline ngày 07/09/2026, site báo Figma chưa cấu hình và browser analysis chưa bật. Đây là trạng thái quan sát được, không phải lỗi mặc định của mọi installation.

Ưu tiên: triển khai code đã review → kiểm tra owner connection → cấp grant có chọn lọc → runtime read → real connector read → disposable write/rollback → visual gate khi browser/reference sẵn sàng.

Không tự mở publish hoặc tất cả abilities ngoài Design Core chỉ để đạt đủ tổng số tool.

## Những chức năng chưa được bổ sung trong lần này

Theme Builder header/footer/single/archive; tạo/quản lý template và điều kiện hiển thị; Popup lifecycle; Forms/submissions; Global Widgets; Loop Item lifecycle; WooCommerce templates; CPT/taxonomy schema; ACF schema/options; chỉnh cây Elementor theo element ID như một API công khai riêng.

Điều đó **không khẳng định toàn repo không có bất kỳ mã hỗ trợ nào** cho các khái niệm trên. Repo có adapter, dynamic/runtime intelligence và có thể có workstream/branch riêng. Nhưng danh mục 42 hiện tại không phải hợp đồng quản trị đầy đủ các module đó; không tài liệu hóa chúng như đã hoàn thiện hoặc đã kiểm thử.

## Lộ trình đề xuất, chưa phải cam kết đã triển khai

### A. Quan sát và quyền hạn

Bổ sung diagnostics/recent-errors đã scrub, capability matrix theo môi trường, kiểm tra cấu hình connector riêng, policy giới hạn thao tác theo từng identity. Cần phân biệt “user có capability” với “OAuth/NHI grant cho phép hành động cụ thể”.

Đặc biệt, generic WordPress create/update có thể chứa trạng thái publish. Muốn policy “connector tuyệt đối không publish”, phải kiểm soát cả nhánh này và khả năng sửa nội dung đã public, không chỉ ẩn một tên tool.

### B. Elementor structure và Theme Builder

Thiết kế read tree/patch theo ID, snapshot/hash/optimistic locking, ownership và public native APIs. Tạo template/điều kiện hiển thị trên draft/sandbox, bảo toàn tài nguyên không do Design Core tạo. Không giải quyết bằng ghi private Elementor storage.

### C. Modules Pro và dữ liệu động

Tách schemas cho Forms, Popup, Loop và WooCommerce; read-only discovery trước, write bounded sau. Gắn quyền/kiểm thử cho dữ liệu nhạy cảm như submission, khách hàng và đơn hàng. ACF cần hợp đồng field-family/provider rõ ràng, không coi repeater như scalar tag.

### D. Độ trung thực hình ảnh và vận hành

Mở rộng benchmark multi-viewport, kiểm thử browser sandbox/network, import asset có kiểm soát, V4 public capability proof. Đo hiệu năng catalog/history/registry trước khi đổi storage. Kiểm thử rate/idempotency race thay vì chỉ sequential replay.

## Điều kiện thêm một operation

Phải có nghiệp vụ cụ thể và phạm vi ghi rõ ràng; schema bounded; capability; controller chung; mapping bridge; translation catalog; tests permission/negative/idempotency phù hợp; runtime evidence và hướng rollback. Không thêm arbitrary PHP/SQL/shell/meta/options để tránh thiết kế API.

Khi chỉ thêm code chưa có runtime test, ghi đúng trạng thái “implemented, chưa kiểm chứng runtime”. Đây là yêu cầu chất lượng, không phải sự thay thế bằng lời hứa.
