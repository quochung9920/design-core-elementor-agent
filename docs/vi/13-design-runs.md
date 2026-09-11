# Design Runs – truy vết toàn bộ quy trình tạo giao diện

Design Runs là lớp provenance của Design Core. Mỗi lần ChatGPT, Developer AI hoặc một client được cấp quyền bắt đầu một lần phân tích/dựng giao diện, client tạo một `run_id`. Từ thời điểm đó, Design Core ghi lại các operation MCP/REST đi qua Agent Protocol và Semantic Planning V2, các mutation được ghi vào Change Ledger, cùng các decision/evidence event công khai mà client gửi chủ động.

Design Runs **không đọc và không lưu private chain-of-thought của mô hình**. Phần “ChatGPT làm gì” được biểu diễn bằng hành động có thể audit: component đang xử lý, requirement, candidate đã xét, lý do loại/chọn, control mapping, artifact hash, QA, correction và promotion. Credential, Authorization header, bearer token, API key, password, cookie, SSH/private key và secret bị redact trước khi persist.

## Vòng đời

`source → source inventory → component graph → semantic planning → widget selection → control coverage → control mapping → validation → compile → preview → draft write → read-back → browser render → visual QA → interaction QA → correction → final verification → promotion`

Status chuẩn: `pending`, `running`, `pass`, `warning`, `fail`, `blocked`, `not_verified`, `skipped`, `cancelled`, `completed`, `stale`.

Mỗi event có `event_id`, sequence, timestamp, phase, status, actor/client, channel, operation, component ID/type, summary, public rationale, duration, input/output hash, artifact hash, decision summary, metrics, evidence references, warnings/errors và bounded metadata. Các event được nối bằng `previous_event_hash → event_hash`; trang run kiểm tra hash-chain để phát hiện chuỗi provenance bị sửa hoặc đứt. Automatic operation events không lưu raw HTML/CSS/tree/control payload; chúng chỉ giữ hash và metadata cần thiết để truy vết.

## Artifact và QA staleness

QA luôn được gắn với artifact hash. Nếu một correction tạo artifact mới, visual/interaction/semantic evidence PASS của artifact cũ không được dùng như evidence cho artifact mới và summary sẽ hiện `stale` cho tới khi QA được chạy lại.

`promotion_ready` trong Design Run yêu cầu tối thiểu source identity, latest artifact và semantic/structural/visual/interaction QA đều PASS cho đúng evidence hiện hành. Đây là chỉ báo provenance; các guard của Agent Protocol/Promotion vẫn tiếp tục là nguồn enforcement chính.

## MCP abilities

Design Runs đăng ký 10 ability additive:

- `design-core/agent-run-start`
- `design-core/agent-run-activate`
- `design-core/agent-run-event`
- `design-core/agent-run-get`
- `design-core/agent-run-list`
- `design-core/agent-run-events`
- `design-core/agent-run-components`
- `design-core/agent-run-artifacts`
- `design-core/agent-run-compare`
- `design-core/agent-run-finish`

`agent-run-event` chỉ dùng cho concise public reasoning summary/evidence. Không gửi private chain-of-thought hoặc credential vào trường rationale/decision/metadata.

Sau `agent-run-start`, run trở thành active cho principal hiện tại. Các ability `design-core/agent-*` và `design-core/agent-semantic-*` được gọi qua MCP tự ghi operation event. REST Agent Protocol cũng tự trace với actor `api-client`. Change Ledger update trong cùng active run được nối thành mutation event với `history_entry_id`, before/after hash và object identity.

## Admin UI

Trang **Design Core → Design Runs** nằm sau Registry và trước History. Danh sách hiển thị run, page, phase hiện tại, Semantic/Structural/Visual/Interaction QA và trạng thái. Chi tiết run gồm:

- Timeline
- Components
- Widget Decisions
- Controls
- Artifacts
- QA
- Mutations
- Raw Events

Progress bar cho phép nhìn toàn bộ pipeline; Artifact tab cho biết QA nào thuộc artifact nào; Mutations liên kết provenance với Change Ledger/History.

## Giới hạn

Design Core chỉ có thể tự động biết operation/action đi qua hệ thống của nó. Một click hoặc thao tác UI nội bộ của ChatGPT Web không gửi tới Design Core thì plugin không thể quan sát. Client phải phát một `agent-run-event` nếu quyết định đó cần xuất hiện trong provenance. Browser screenshots/interaction evidence vẫn phụ thuộc browser QA subsystem; Design Runs chỉ lưu evidence/ref khi subsystem đó cung cấp kết quả.
