# Tài liệu Design Core Elementor

Bộ tài liệu này mô tả kiến trúc, Owner API, WordPress abilities, Agent Protocol và quy trình thiết kế Elementor trên VPS/Docker.

## Đọc theo công việc

| Công việc | Tài liệu |
|---|---|
| Hiểu dự án, các lớp và trách nhiệm | [01. Kiến trúc](01-kien-truc.md) |
| Triển khai mà không làm hỏng stack đang chạy | [02. VPS/Docker](02-cai-dat-vps.md) |
| Tra operation, route, quyền, tên và tham số MCP | [03. API–MCP](03-api-mcp.md) |
| Kết nối ChatGPT, cấp quyền NHI, xử lý OAuth | [04. Kết nối MCP](04-ket-noi-mcp.md) |
| Xác định ai được đọc/ghi và giới hạn bảo vệ | [05. Bảo mật](05-bao-mat.md) |
| Dựng trang, chỉnh trang, media, menu và rollback | [06. Quy trình](06-quy-trinh-thiet-ke.md) |
| Chạy test, kiểm tra runtime, phát hành | [07. Kiểm thử](07-kiem-thu-phat-hanh.md) |
| Chẩn đoán theo triệu chứng, không đoán nguyên nhân | [08. Xử lý lỗi](08-xu-ly-loi.md) |
| Phân biệt đã có, phụ thuộc cấu hình và chưa có | [09. Lộ trình](09-lo-trinh.md) |
| Xem chính xác đợt cập nhật đã kiểm tra được gì | [10. Bằng chứng](10-bao-cao-xac-minh.md) |
| Đọc dữ liệu đầy đủ, preview/apply artifact qua ChatGPT | [11. Agent Protocol](11-agent-protocol.md) |
| Chọn widget/control theo semantics, behavior và runtime schema | [12. Semantic Planning V2](12-semantic-planning-v2.md) |
| Theo dõi toàn bộ quy trình ChatGPT/agent từ source đến QA/promotion | [13. Design Runs](13-design-runs.md) |
| Giữ source composition/CSS/media/form khi compile sang Elementor | [14. Fidelity Engine V1](14-fidelity-engine-v1.md) |

## Nguồn sự thật

Tên Owner API operation, method, path, capability và schema lấy từ contract trong code. Mapping callback và tên ability nằm ở bridge tương ứng. Runtime WordPress/Elementor và Control Schema Registry là nguồn sự thật về widget/control; tài liệu không thay thế runtime schema.

Semantic Planning V2 không hard-code một widget theo nhãn component. Nó dùng component contract để loại ứng viên sai chức năng trước, rồi đối chiếu widget/control đang thực sự đăng ký trên runtime. Khi không có widget đơn phù hợp, planner có thể chọn native composition hoặc purpose-built Design Core widget; không dùng layout HTML như fallback im lặng.

Fidelity Engine V1 bổ sung Component Graph và source-fidelity contract. Wrapper có nhiều atom độc lập được đánh dấu `preserve-children`; planner không được collapse chúng thành một widget chỉ vì semantic/reuse score cao. CSS nhúng, box spacing, grid/gap, form field identity và critical media/form atoms được giữ thành evidence trước khi build được xem là hợp lệ.

Design Runs là provenance layer: nó ghi các operation Design Core, public decision summaries, artifact/QA evidence và Change Ledger mutation vào một run có hash-chain. Design Runs không đọc private chain-of-thought và không lưu credential.

## Thuật ngữ

**Operation** là một thao tác nghiệp vụ. **Ability** là chức năng được đăng ký trong WordPress Abilities API. **MCP tool** là cách connector trình bày ability cho client; tên có thể đổi dấu `/` thành `__`.

**Owner** là user WordPress đã claim quyền sở hữu Owner API. **NHI** là danh tính máy và tập quyền của connector; không đồng nhất NHI với owner hay Bearer credential.

**Design IR** là biểu diễn thiết kế trung gian. **BuildPlan** là kế hoạch thực thi. **Preview artifact** là cây Elementor cụ thể đã được validate và hash. **Semantic evidence** chứng minh component decision khớp runtime contract; nó không thay thế visual/interaction QA. **Design Run** là provenance record nối source, component decision, control mapping, artifact, QA, correction và mutation bằng một `run_id`.

**Source fidelity contract** là hợp đồng về các atom/layout ownership của source phải sống sót qua compile. PASS của hợp đồng này không phải Pixel/Browser QA PASS.

## Cách đọc kết quả kiểm tra

“Có trong code”, “đã đăng ký trên WordPress”, “OAuth discover được”, “đã execute thật” và “đã kiểm thử write/rollback” là các mức bằng chứng khác nhau. Không thay thế một mức bằng mức khác.

`PASS` chỉ có ý nghĩa kèm phép thử và môi trường chạy. Semantic PASS không chứng minh giao diện đã giống nguồn; visual và interaction QA vẫn phải chạy trên trình duyệt thật.