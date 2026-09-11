# 05. Bảo mật và ranh giới quyền

## Không đồng nhất hai cơ chế xác thực

| Thuộc tính | Owner REST API | WordPress MCP bridge version 2 |
|---|---|---|
| Danh tính đầu vào | Bearer `dcapi_*` | User WordPress do connector xác thực |
| Owner-lock | Credential thuộc owner đã claim | User hiện tại phải đúng owner, còn `manage_options` |
| API bật/tắt | Kiểm tra Owner API enabled | Cũng kiểm tra trạng thái này |
| Quyền nghiệp vụ | Scope của credential | Capability thực tế của user |
| Token hết hạn/thu hồi | Registry `dcapi_*` | OAuth của connector, không dùng registry `dcapi_*` |
| Environment binding | Kiểm tra environment của credential | Không có credential `dcapi_*`; user principal không đi qua kiểm tra binding dành cho credential |
| Rate limit | Lớp credential auth | Cần kiểm tra/ràng buộc riêng ở connector/proxy |
| Ghi dữ liệu | Controller và các guard nghiệp vụ | Gọi lại cùng controller và các guard nghiệp vụ |

Không được mô tả MCP như thể tự thừa hưởng expiry/revocation/rate-limit của Bearer API. OAuth endpoint/resource, môi trường site và grant connector phải được xác minh độc lập.

## Owner lock

Owner API bắt đầu chưa claim và disabled. Việc claim yêu cầu administrator thật. Sau khi claim, user khác không được dùng giao diện quản lý API để tự chiếm quyền owner.

Bridge version 2 kiểm tra owner ở permission callback và lặp lại ở `execute()`, tránh việc một callback PHP trực tiếp bỏ qua lớp WordPress ability. Các ability legacy cũng được siết owner, thay vì cho mọi administrator đi qua.

Owner lock là ranh giới của giao diện ứng dụng, không chống được người có quyền sửa PHP, database, VPS hoặc tài khoản administrator đã bị chiếm. Không tuyên bố “chỉ duy nhất một con người có thể truy cập” khi token/OAuth/session có thể bị đánh cắp.

## Sáu capability

`design_core_read` đọc dữ liệu; `design_core_preview` tạo phân tích/preview; `design_core_build` tạo draft/import media; `design_core_modify` sửa nội dung được phép; `design_core_publish` xuất bản và thay thiết lập site; `design_core_rollback` hoàn tác Design Core history.

REST scope không đồng nghĩa capability WordPress user và NHI grant không đồng nghĩa cả hai. Cần kiểm tra từng lớp.

**Lưu ý về publish:** loại `publish-page` khỏi NHI không tự chứng minh mọi đường xuất bản bị khóa. Các operation tạo/sửa WordPress content có thể yêu cầu `status=publish`; controller còn kiểm tra quyền publish của principal. Sửa nội dung đang published cũng có thể làm thay đổi trang công khai ngay lập tức. Cấu hình quyền và quy trình phê duyệt phải tính đến các đường này.

Không cấp các generic tool WordPress nguy hiểm cho cùng NHI rồi cho rằng owner-lock Design Core bảo vệ được chúng. Các tool ngoài namespace Design Core phải được đánh giá và cấp quyền riêng.

## Bảo vệ đầu vào

Bridge chỉ nhận thuộc tính nằm trong schema, giới hạn payload 2 MB, kiểm tra required/type/range và không nhận principal/scopes/owner/environment từ caller. Path/query/body được tách theo contract; path chứa ID cụ thể để fingerprint không nhầm đối tượng.

Các object nghiệp vụ mở như Design IR được phép giữ cấu trúc mở theo schema; điều đó không cho phép ghi meta tùy ý. Validator và service của nghiệp vụ vẫn là ranh giới tiếp theo.

Metadata `public` của MCP là tín hiệu discovery, không phải bỏ permission. `readOnlyHint`, `destructiveHint` và các annotation khác chỉ là gợi ý cho client, không phải cơ chế cấp quyền.

Nguồn nền tảng: [WordPress wp_register_ability](https://developer.wordpress.org/reference/functions/wp_register_ability/).

## Bảo vệ thao tác ghi

Mọi operation ghi trong Owner API yêu cầu `confirm: true`. Elementor apply còn yêu cầu ticket `preview_id` và `plan_hash` nguyên gốc, kiểm tra trạng thái live và persistence/verify theo code hiện có.

`confirm=true` là trường do client gửi; bản thân nó không chứng minh người dùng thật đã bấm duyệt. Client cần trình bày kế hoạch và chỉ gửi xác nhận sau khi người dùng chấp thuận.

Dùng `idempotency_key` cho từng tác vụ. Key đang tùy chọn trong contract; vì vậy metadata của operation ghi không được tự khẳng định luôn idempotent. Test transport kiểm tra replay và conflict tuần tự; không thay thế stress test cạnh tranh nhiều worker.

Tắt remote writes phải chặn các đường ghi trước khi gọi service. Không dùng generic `call-tool` để vượt gate: các mutation legacy qua ability bị chặn trong bản này.

## Bearer credentials và bí mật

Credential REST được sinh ngẫu nhiên, lưu hash của secret, có owner, scope, environment, thời hạn và revoke. Chỉ xem plaintext khi tạo/rotate theo cơ chế hiện tại. Token bị lộ phải thu hồi/rotate qua quy trình chính thức.

Không lưu token trong repository, tài liệu, ảnh chụp cấu hình, log debug, shell history hoặc prompt. Connector OAuth không yêu cầu đưa `dcapi_*` vào cuộc chat. Không in `wp-config.php`, toàn bộ Docker environment hoặc credential registry để báo cáo.

Ví dụ test REST trên máy quản trị nên dùng cơ chế secret của môi trường và tránh shell tracing (`set -x`). Không đặt token vào URL/query string vì dễ lọt access log.

## Media, mạng và proxy

Media import tiếp tục dùng WordPress Owner Service; bridge không tạo một đường download thay thế. Kiểm thử SSRF, redirect vào mạng riêng, DNS rebinding, MIME và size-limit phải thực hiện trên môi trường kiểm thử có kiểm soát. Việc tái dùng service không phải bằng chứng rằng mọi tình huống mạng đã được audit trong đợt này.

HTTPS, Authorization forwarding, DNS và TLS là trách nhiệm deployment. CORS không phải cơ chế thay thế xác thực và không phải lý do mở tất cả origin cho một client server-to-server.

Không public database hoặc Docker socket. Giới hạn đường dẫn trên API hostname không khóa được các hostname khác hoặc các API WordPress khác.

## Rollback và phạm vi đảm bảo

History/rollback của Design Core chủ yếu bảo vệ các thay đổi đi qua luồng có ledger và snapshot phù hợp. Không tuyên bố mọi thay đổi menu, settings, media hay mọi plugin WordPress đều có transactional rollback như Elementor.

Rollback phải từ chối ghi đè thay đổi mới hơn. Một phép thử rollback hai lần bị chặn chưa chứng minh đầy đủ xung đột chỉnh sửa đồng thời; cần trường hợp test riêng.

## Những việc đợt này không tự thực hiện

Không tự claim owner, không cấp thêm role/admin, không đổi NHI grant, không tắt kiểm tra OAuth audience, không thu hồi token của người dùng và không sửa firewall/VPS. Các thao tác đó thuộc bước triển khai có quyền quản trị và cần bằng chứng runtime riêng.
