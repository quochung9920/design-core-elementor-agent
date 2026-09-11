# 03. Danh mục Owner API và MCP

> Tệp được sinh tự động bằng `php tools/export-mcp-contract.php --write`. Không sửa bảng bằng tay.

Nguồn: `core/gpt-actions-api.php` và `core/mcp-ability-bridge.php`. Có **42 operation nghiệp vụ**; endpoint công khai `GET /openapi` là đường tải tài liệu riêng, không nằm trong con số này.

Base path REST: `/wp-json/design-core/v1`. Tên ability chuẩn dùng `design-core/`; connector có thể hiển thị dấu `/` thành `__`. Luôn dùng tên chính xác mà discovery trả về.

Scope trong bảng bỏ tiền tố `design_core_` để dễ đọc. Cột đầu vào dùng tên của **MCP**, không phải tên path parameter trong URL REST. Kiểu và ràng buộc đầy đủ có trong `get_ability_info` hoặc lệnh `php tools/export-mcp-contract.php --json`.

Các tác vụ preview không sửa nội dung trang nhưng có thể lưu ticket tạm, cache hoặc dữ liệu phục vụ kiểm tra. Có ability trong registry không có nghĩa NHI/OAuth đã được cấp quyền gọi.

| Operation | Ability | REST | Quyền | Chế độ |
|---|---|---|---|---|
| `getManifest` | `design-core/get-manifest` | `GET /manifest` | `read` | Đọc/preview |
| `getSiteStatus` | `design-core/get-site-status` | `GET /site/status` | `read` | Đọc/preview |
| `understandSite` | `design-core/understand-site` | `POST /understand` | `read` | Đọc/preview |
| `getSiteMap` | `design-core/get-site-map` | `GET /site/map` | `read` | Đọc/preview |
| `getSiteDesignSystem` | `design-core/get-site-design-system` | `GET /site/design-system` | `read` | Đọc/preview |
| `searchSiteContent` | `design-core/search-site-content` | `GET /site/search` | `read` | Đọc/preview |
| `getElementorCapabilities` | `design-core/get-elementor-capabilities` | `GET /elementor/capabilities` | `read` | Đọc/preview |
| `getElementorCatalog` | `design-core/get-elementor-catalog` | `GET /elementor/catalog` | `read` | Đọc/preview |
| `searchElementorWidgets` | `design-core/search-elementor-widgets` | `POST /elementor/widgets/search` | `read` | Đọc/preview |
| `getElementorWidgetSchema` | `design-core/get-elementor-widget-schema` | `GET /elementor/widgets/{widget}` | `read` | Đọc/preview |
| `getMediaLibrary` | `design-core/get-media-library` | `GET /media` | `read` | Đọc/preview |
| `planTask` | `design-core/plan-task` | `POST /tasks/plan` | `read` | Đọc/preview |
| `getDesignIntelligenceStatus` | `design-core/get-design-intelligence-status` | `GET /design/status` | `read` | Đọc/preview |
| `recommendDesign` | `design-core/recommend-design` | `POST /design/recommend` | `preview` | Đọc/preview |
| `previewDesignSystem` | `design-core/preview-design-system` | `POST /design/preview` | `preview` | Đọc/preview |
| `enrichDesignIR` | `design-core/enrich-design-ir` | `POST /design/enrich-ir` | `preview` | Đọc/preview |
| `auditPageUX` | `design-core/audit-page-ux` | `GET /pages/{id}/ux-audit` | `read` | Đọc/preview |
| `previewFigma` | `design-core/preview-figma` | `POST /figma/preview` | `preview` | Đọc/preview |
| `previewBuild` | `design-core/preview-build` | `POST /build/preview` | `preview` | Đọc/preview |
| `createDraftPage` | `design-core/create-draft-page` | `POST /pages` | `build` | Ghi, cần xác nhận |
| `getPageSnapshot` | `design-core/get-page-snapshot` | `GET /pages/{id}` | `read` | Đọc/preview |
| `applyPageBuild` | `design-core/apply-page-build` | `POST /pages/{id}/apply` | `modify` | Ghi, cần xác nhận |
| `verifyPage` | `design-core/verify-page` | `POST /pages/{id}/verify` | `read` | Đọc/preview |
| `visualFeedback` | `design-core/visual-feedback` | `POST /pages/{id}/visual-feedback` | `preview` | Đọc/preview |
| `autoCorrectPage` | `design-core/auto-correct-page` | `POST /pages/{id}/auto-correct` | `modify` | Ghi, cần xác nhận |
| `publishPage` | `design-core/publish-page` | `POST /pages/{id}/publish` | `publish` | Ghi, cần xác nhận |
| `getHistory` | `design-core/get-history` | `GET /history` | `read` | Đọc/preview |
| `rollbackHistory` | `design-core/rollback-history` | `POST /history/{entry}/rollback` | `rollback` | Ghi, cần xác nhận |
| `listWordPressContent` | `design-core/list-wordpress-content` | `GET /wordpress/content` | `read` | Đọc/preview |
| `getWordPressContent` | `design-core/get-wordpress-content` | `GET /wordpress/content/{id}` | `read` | Đọc/preview |
| `createWordPressContent` | `design-core/create-wordpress-content` | `POST /wordpress/content` | `build` | Ghi, cần xác nhận |
| `updateWordPressContent` | `design-core/update-wordpress-content` | `POST /wordpress/content/{id}` | `modify` | Ghi, cần xác nhận |
| `trashWordPressContent` | `design-core/trash-wordpress-content` | `POST /wordpress/content/{id}/trash` | `modify` | Ghi, cần xác nhận |
| `restoreWordPressContent` | `design-core/restore-wordpress-content` | `POST /wordpress/content/{id}/restore` | `modify` | Ghi, cần xác nhận |
| `getWordPressSettings` | `design-core/get-wordpress-settings` | `GET /wordpress/settings` | `read` | Đọc/preview |
| `updateWordPressSettings` | `design-core/update-wordpress-settings` | `POST /wordpress/settings` | `publish` | Ghi, cần xác nhận |
| `getWordPressMenus` | `design-core/get-wordpress-menus` | `GET /wordpress/menus` | `read` | Đọc/preview |
| `upsertWordPressMenuItem` | `design-core/upsert-wordpress-menu-item` | `POST /wordpress/menus/{id}/items` | `modify` | Ghi, cần xác nhận |
| `trashWordPressMenuItem` | `design-core/trash-wordpress-menu-item` | `POST /wordpress/menus/{id}/items/{item}` | `modify` | Ghi, cần xác nhận |
| `importWordPressMedia` | `design-core/import-wordpress-media` | `POST /wordpress/media/import` | `build` | Ghi, cần xác nhận |
| `updateWordPressMedia` | `design-core/update-wordpress-media` | `POST /wordpress/media/{id}` | `modify` | Ghi, cần xác nhận |
| `trashWordPressMedia` | `design-core/trash-wordpress-media` | `POST /wordpress/media/{id}/trash` | `modify` | Ghi, cần xác nhận |

## Đầu vào và mục đích từng operation

### `getManifest`

Đọc manifest và tình trạng đối chiếu API/MCP.

Không nhận tham số.

### `getSiteStatus`

Đọc trạng thái WordPress, Elementor, Pro và Design Core.

Không nhận tham số.

### `understandSite`

Tổng hợp ngữ cảnh site và kế hoạch theo yêu cầu.

`brief` (string, tối đa 16384 ký tự, **bắt buộc**); `page_id` (integer, ≥ 0, tùy chọn); `site_map_limit` (integer, ≥ 1, ≤ 200, tùy chọn).

### `getSiteMap`

Đọc cấu trúc trang, menu, template và registry.

`limit` (integer, ≥ 1, ≤ 200, tùy chọn).

### `getSiteDesignSystem`

Đọc token, Elementor Kit và breakpoint thực tế.

Không nhận tham số.

### `searchSiteContent`

Tìm nội dung WordPress và tài nguyên tái sử dụng.

`q` (string, **bắt buộc**); `types` (string, tùy chọn); `limit` (integer, ≥ 1, ≤ 50, tùy chọn).

### `getElementorCapabilities`

Đọc khả năng Elementor/Pro đang có.

Không nhận tham số.

### `getElementorCatalog`

Đọc danh mục widget từ runtime.

`source` (string, tùy chọn); `q` (string, tùy chọn); `limit` (integer, ≥ 1, ≤ 200, tùy chọn).

### `searchElementorWidgets`

Xếp hạng widget theo yêu cầu có cấu trúc.

`query` (string, tối đa 1000 ký tự, tùy chọn); `intent` (string, tùy chọn); `capabilities` (array, tùy chọn); `controls` (array, tùy chọn); `keywords` (array, tùy chọn); `limit` (integer, ≥ 1, ≤ 50, tùy chọn).

### `getElementorWidgetSchema`

Đọc control schema của một widget.

`widget` (string, **bắt buộc**); `detail` (boolean, tùy chọn).

### `getMediaLibrary`

Tìm metadata trong thư viện media.

`q` (string, tùy chọn); `mime` (string, tùy chọn); `limit` (integer, ≥ 1, ≤ 50, tùy chọn).

### `planTask`

Lập kế hoạch, không thay đổi nội dung.

`brief` (string, tối đa 16384 ký tự, **bắt buộc**); `page_id` (integer, ≥ 0, tùy chọn).

### `getDesignIntelligenceStatus`

Đọc tình trạng kho tri thức thiết kế.

Không nhận tham số.

### `recommendDesign`

Đề xuất hồ sơ thiết kế theo brief.

`brief` (string, tối đa 16384 ký tự, **bắt buộc**); `product_type` (string, tùy chọn); `mode` (string, tùy chọn); `variance` (integer, ≥ 0, tùy chọn); `motion` (integer, ≥ 0, tùy chọn); `density` (integer, ≥ 0, tùy chọn); `max_rules` (integer, ≥ 1, ≤ 100, tùy chọn).

### `previewDesignSystem`

Tạo preview từ đề xuất thiết kế.

`brief` (string, tối đa 16384 ký tự, **bắt buộc**); `page_id` (integer, ≥ 0, tùy chọn); `product_type` (string, tùy chọn); `mode` (string, tùy chọn); `bindings` (object, tùy chọn); `adapter_target` (string, tùy chọn).

### `enrichDesignIR`

Bổ sung hồ sơ thiết kế vào Design IR.

`design_ir` (object, **bắt buộc**); `brief` (string, tối đa 16384 ký tự, tùy chọn); `profile` (object, tùy chọn).

### `auditPageUX`

Kiểm tra UX của một trang.

`page_id` (integer, ≥ 1, **bắt buộc**).

### `previewFigma`

Chuyển đầu vào Figma thành preview.

`page_id` (integer, ≥ 0, tùy chọn); `figma_url` (string, **bắt buộc**); `node_id` (string, tùy chọn); `adapter_target` (string, tùy chọn); `instructions` (string, tối đa 4000 ký tự, tùy chọn).

### `previewBuild`

Biên dịch HTML/CSS hoặc Design IR thành preview.

`page_id` (integer, ≥ 0, tùy chọn); `design_ir` (object, tùy chọn); `html` (string, tùy chọn); `css` (string, tùy chọn); `adapter_target` (string, tùy chọn).

### `createDraftPage`

Tạo trang nháp, chưa ghi cấu trúc Elementor.

`title` (string, tối đa 200 ký tự, **bắt buộc**); `slug` (string, tối đa 200 ký tự, tùy chọn); `parent_id` (integer, ≥ 1, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `getPageSnapshot`

Đọc snapshot giới hạn của trang Elementor.

`page_id` (integer, ≥ 1, **bắt buộc**).

### `applyPageBuild`

Áp dụng đúng preview đã được duyệt.

`page_id` (integer, ≥ 1, **bắt buộc**); `preview_id` (string, **bắt buộc**); `plan_hash` (string, **bắt buộc**); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `verifyPage`

Đọc trạng thái, snapshot, UX và history sau thay đổi.

`page_id` (integer, ≥ 1, **bắt buộc**).

### `visualFeedback`

So sánh kết quả với nguồn tham chiếu.

`page_id` (integer, ≥ 1, **bắt buộc**); `reference_target` (string, tùy chọn); `reference_url` (string, tùy chọn); `candidate_target` (string, tùy chọn); `target_similarity` (number, ≥ 0, ≤ 1, tùy chọn).

### `autoCorrectPage`

Áp dụng hiệu chỉnh hình ảnh có kiểm soát.

`page_id` (integer, ≥ 1, **bắt buộc**); `feedback` (object, tùy chọn); `corrections` (array, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `publishPage`

Xuất bản trang đã được người dùng duyệt.

`page_id` (integer, ≥ 1, **bắt buộc**); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `getHistory`

Đọc lịch sử thay đổi Design Core.

Không nhận tham số.

### `rollbackHistory`

Hoàn tác entry đủ điều kiện, có kiểm tra xung đột.

`entry` (string, **bắt buộc**); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `listWordPressContent`

Liệt kê bài viết, trang hoặc nội dung CPT.

`post_type` (string, tùy chọn); `status` (string, tùy chọn); `search` (string, tùy chọn); `limit` (integer, ≥ 1, ≤ 100, tùy chọn).

### `getWordPressContent`

Đọc nội dung và cờ do Elementor quản lý.

`content_id` (integer, ≥ 1, **bắt buộc**).

### `createWordPressContent`

Tạo nội dung WordPress thông thường.

`post_type` (string, tùy chọn); `title` (string, tối đa 300 ký tự, **bắt buộc**); `slug` (string, tùy chọn); `status` (string, tùy chọn); `content` (string, tùy chọn); `excerpt` (string, tùy chọn); `parent_id` (integer, ≥ 0, tùy chọn); `menu_order` (integer, ≥ 0, tùy chọn); `elementor` (boolean, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `updateWordPressContent`

Sửa các trường nội dung được cho phép.

`content_id` (integer, ≥ 1, **bắt buộc**); `title` (string, tùy chọn); `slug` (string, tùy chọn); `status` (string, tùy chọn); `content` (string, tùy chọn); `excerpt` (string, tùy chọn); `parent_id` (integer, ≥ 0, tùy chọn); `menu_order` (integer, ≥ 0, tùy chọn); `expected_modified_gmt` (string, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `trashWordPressContent`

Chuyển nội dung vào thùng rác.

`content_id` (integer, ≥ 1, **bắt buộc**); `expected_modified_gmt` (string, tùy chọn); `confirm_front_page` (boolean, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `restoreWordPressContent`

Khôi phục nội dung từ thùng rác.

`content_id` (integer, ≥ 1, **bắt buộc**); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `getWordPressSettings`

Đọc nhóm thiết lập website được cho phép.

Không nhận tham số.

### `updateWordPressSettings`

Sửa nhóm thiết lập website được cho phép.

`blogname` (string, tùy chọn); `blogdescription` (string, tùy chọn); `show_on_front` (string, tùy chọn); `page_on_front` (integer, ≥ 0, tùy chọn); `page_for_posts` (integer, ≥ 0, tùy chọn); `timezone_string` (string, tùy chọn); `date_format` (string, tùy chọn); `time_format` (string, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `getWordPressMenus`

Đọc menu và các mục menu.

Không nhận tham số.

### `upsertWordPressMenuItem`

Tạo hoặc sửa một mục menu.

`menu_id` (integer, ≥ 1, **bắt buộc**); `item_id` (integer, ≥ 0, tùy chọn); `title` (string, tùy chọn); `url` (string, tùy chọn); `type` (string, tùy chọn); `object` (string, tùy chọn); `object_id` (integer, ≥ 0, tùy chọn); `parent_id` (integer, ≥ 0, tùy chọn); `position` (integer, ≥ 0, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `trashWordPressMenuItem`

Chuyển một mục menu vào thùng rác.

`menu_id` (integer, ≥ 1, **bắt buộc**); `item_id` (integer, ≥ 1, **bắt buộc**); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `importWordPressMedia`

Nhập một URL media qua dịch vụ có kiểm soát.

`url` (string, **bắt buộc**); `title` (string, tùy chọn); `alt` (string, tùy chọn); `parent_id` (integer, ≥ 0, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `updateWordPressMedia`

Sửa tiêu đề, alt, chú thích hoặc mô tả media.

`media_id` (integer, ≥ 1, **bắt buộc**); `title` (string, tùy chọn); `alt` (string, tùy chọn); `caption` (string, tùy chọn); `description` (string, tùy chọn); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

### `trashWordPressMedia`

Chuyển một attachment vào thùng rác.

`media_id` (integer, ≥ 1, **bắt buộc**); `confirm` (boolean, **bắt buộc**); `idempotency_key` (string, tối đa 200 ký tự, tùy chọn).

## Quy tắc không được bỏ qua

- Mọi operation ghi yêu cầu boolean `confirm: true`; chuỗi `"true"` không thay thế được boolean.
- `applyPageBuild` yêu cầu đúng `preview_id` và `plan_hash`; bridge không tự tạo hay sửa ticket.
- Dùng `idempotency_key` cho từng tác vụ ghi. Retry cùng tác vụ phải giữ nguyên key và payload; key đang là tham số tùy chọn trong contract, không được mô tả là luôn bắt buộc.
- `createWordPressContent`/`updateWordPressContent` cần thêm quyền publish khi yêu cầu trạng thái xuất bản. Không suy ra quyền này chỉ từ việc NHI có/không liệt kê `publish-page`.
- Không dùng generic content để ghi đè `post_content` của trang do Elementor quản lý. Không có API sửa meta/SQL/PHP tùy ý.
- `trashWordPressContent` trên trang chủ cần `confirm_front_page`; `expected_modified_gmt` hỗ trợ phát hiện thay đổi đồng thời khi endpoint có trường này.
- Menu và media chỉ hỗ trợ tập trường trong schema. Import media không đồng nghĩa được tải mã thực thi hay URL mạng riêng.

Xem [bảo mật](05-bao-mat.md), [kết nối MCP](04-ket-noi-mcp.md) và [quy trình làm việc](06-quy-trinh-thiet-ke.md).
