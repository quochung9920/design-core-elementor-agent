# 04. Kết nối MCP, OAuth và NHI

## Kiến trúc đang dùng

```text
ChatGPT → MCP Server For WordPress
        → OAuth và cấp quyền của connector
        → WordPress Abilities API
        → Design Core MCP bridge
        → Owner REST controller / services
```

Mở rộng diễn ra trong `design-core-elementor`, không cần fork miniOrange. Không thêm endpoint chạy PHP, shell, SQL, đọc `.env` hoặc ghi meta tùy ý.

## Registry, grant và thực thi là ba việc khác nhau

Design Core đăng ký abilities qua hook của WordPress. Registry có thể khởi tạo lười; dùng public API `wp_get_abilities()` để đọc/khởi tạo khi kiểm tra, không kết luận “chưa đăng ký” chỉ vì hook chưa chạy ở thời điểm quan sát sớm.

Theo báo cáo deployment đã cung cấp, miniOrange lưu snapshot quyền theo role trong NHI. Vì vậy ability mới không tự xuất hiện trong grant cũ. Xác minh behavior của phiên bản plugin đang chạy; cập nhật qua giao diện hoặc store API chính thức, không sửa DB thô.

Bản bridge mới có **42 canonical abilities**, cộng **8 legacy abilities** vẫn giữ tên. Registry dự kiến có 50 tên Design Core khi cả ba lớp đăng ký đều hoạt động. Danh sách client nhìn thấy phụ thuộc quyền NHI và chính sách connector; không bắt buộc client read-only nhìn thấy cả 50.

## Các ability bổ sung so với bridge 27 operation

Bổ sung manifest, sáu operation content, năm operation settings/menu và ba operation media. Tổng cộng 15 operation mới; danh sách chính xác nằm trong [catalog tự sinh](03-api-mcp.md).

Không tự cấp quyền tất cả operation mới khi nâng cấp plugin. Owner phải xem xét grant; publish và thiết lập site có rủi ro cao hơn đọc dữ liệu.

## Thiết lập kết nối

Xác minh plugin active, owner đã claim, Owner API bật và user OAuth đúng owner. Kiểm tra các capability `design_core_*` của user, không tự nâng role hoặc cấp administrator để giải quyết lỗi.

Sau đó kiểm tra endpoint MCP và OAuth discovery hiện tại của connector. Báo cáo xử lý sự cố trước đây của deployment nêu resource mới `/wp-json/mosmcp/v1/mcp`. Đây là thông tin triển khai, không phải đường dẫn do Design Core hard-code và không phải URL Owner REST API.

Trong ChatGPT, mở phần Apps/Plugins của Settings, chọn đúng kết nối. Dùng Reconnect khi có; nếu nhà cung cấp yêu cầu ngắt rồi nối lại, xem cảnh báo và authorize lại đúng account. Không uninstall hàng loạt app và không đưa access/refresh token vào chat.

Tài liệu chính thức về quản lý kết nối: [OpenAI — Connecting and managing app accounts](https://help.openai.com/en/articles/20001494-connecting-and-managing-app-accounts-in-chatgpt).

## Khi permalink hoặc resource thay đổi

Đối chiếu resource/audience trong metadata với endpoint thực tế và OAuth grant. Một token cấp cho resource khác không nên được chấp nhận bằng cách tắt audience validation.

Không tự forge token. Khi cần authorize lại, thao tác qua flow chính thức của connector và thu hồi token cũ theo chính sách vận hành. Đổi NHI role không sửa được một lỗi resource/audience không khớp.

## Kiểm tra từ ChatGPT

Trình tự là:

```text
discover_abilities(category="design-core")
→ lấy tên đúng từ kết quả
→ get_ability_info(tên)
→ execute_ability(tên, parameters)
```

WordPress đăng ký `design-core/get-site-status`; connector đã từng hiển thị `design-core__get-site-status`. Đây là tên trên hai lớp khác nhau. Không tự đoán tên tool chưa xuất hiện trong discovery.

Các lệnh đọc đầu tiên: `get-site-status`, `get-site-map`, `get-site-design-system`, `get-elementor-capabilities`, `get-elementor-catalog`. Sau khi triển khai bridge 2, `get-manifest` và kết quả status qua bridge có thêm `mcp_bridge`.

`mcp_bridge.parity=true` chứng minh catalog/method mapping ở bản code đó khớp; `connector_grants_verified=false` nhắc rằng kết quả này không tự kiểm tra NHI/OAuth.

## Đọc kết quả đúng cách

Một wrapper có `success=true` vẫn có thể chứa lỗi MCP trong `data.isError` hoặc nội dung lỗi của tool. Kiểm tra cả lớp ngoài và kết quả nghiệp vụ; không chỉ nhìn một cờ success.

`count > 0` chỉ chứng minh discovery. Chỉ sau một `execute_ability` trả dữ liệu thực tế mới xác nhận quyền execute của operation đó. Chưa gọi write không được tuyên bố publish/rollback đã hoạt động qua client.

## Các tên legacy

Năm ability read/preview cũ và ba meta-abilities vẫn được đăng ký. Bridge mới yêu cầu owner cho đường ability tương thích. Generic `call-tool` chỉ còn được dùng cho công việc đọc/preview được cho phép; các đường như `convert-html`, `visual-correct`, `history-rollback` bị chặn ở lớp ability và yêu cầu dùng operation scoped tương ứng.

Đây là thay đổi bảo mật có chủ đích. Luồng REST/admin gateway cũ là giao diện riêng; cần audit riêng nếu vẫn được sử dụng.

## Thông tin được phép đưa vào báo cáo

Ghi hostname/endpoint, thời điểm, request ID không nhạy cảm, số ability, mã lỗi và phiên bản. Không ghi Authorization header, token, cookie, client secret hoặc raw `.env`. Không gửi thông tin bí mật cho model để xử lý sự cố kết nối.
