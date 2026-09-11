<?php
/**
 * CLI-only export of the canonical Owner API/MCP contract; no database access.
 * php tools/export-mcp-contract.php --json
 * php tools/export-mcp-contract.php --write
 * php tools/export-mcp-contract.php --check
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', $root . '/' ); }
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
}
foreach ( array( 'design-core-capabilities', 'gpt-actions-api', 'gpt-actions-rest-controller', 'mcp-ability-bridge' ) as $file ) {
    require_once $root . '/core/' . $file . '.php';
}
$report = Design_Core_Elementor_MCP_Ability_Bridge::contract_report();
if ( ! $report['parity'] ) {
    fwrite( STDERR, "Owner API/MCP contract mismatch.\n" );
    exit( 1 );
}
$records = array();
$names = Design_Core_Elementor_MCP_Ability_Bridge::ability_names();
foreach ( Design_Core_Elementor_GPT_Actions_API::operations() as $op ) {
    $id = $op['operationId'];
    $records[] = array(
        'operation_id' => $id,
        'ability' => $names[ $id ],
        'method' => $op['method'],
        'path' => $op['path'],
        'capability' => $op['capability'],
        'read_only' => $op['read_only'],
        'input_schema' => Design_Core_Elementor_MCP_Ability_Bridge::input_schema_for( $id, $op ),
    );
}
$mode = $argv[1] ?? '--json';
if ( '--json' === $mode ) {
    echo json_encode( array( 'contract' => $report, 'operations' => $records ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    exit( 0 );
}
if ( ! in_array( $mode, array( '--write', '--check' ), true ) ) {
    fwrite( STDERR, "Usage: php tools/export-mcp-contract.php [--json|--write|--check]\n" );
    exit( 2 );
}
$vi = array(
    'getManifest'=>'Đọc manifest và tình trạng đối chiếu API/MCP.',
    'getSiteStatus'=>'Đọc trạng thái WordPress, Elementor, Pro và Design Core.',
    'understandSite'=>'Tổng hợp ngữ cảnh site và kế hoạch theo yêu cầu.',
    'getSiteMap'=>'Đọc cấu trúc trang, menu, template và registry.',
    'getSiteDesignSystem'=>'Đọc token, Elementor Kit và breakpoint thực tế.',
    'searchSiteContent'=>'Tìm nội dung WordPress và tài nguyên tái sử dụng.',
    'getElementorCapabilities'=>'Đọc khả năng Elementor/Pro đang có.',
    'getElementorCatalog'=>'Đọc danh mục widget từ runtime.',
    'searchElementorWidgets'=>'Xếp hạng widget theo yêu cầu có cấu trúc.',
    'getElementorWidgetSchema'=>'Đọc control schema của một widget.',
    'getMediaLibrary'=>'Tìm metadata trong thư viện media.',
    'planTask'=>'Lập kế hoạch, không thay đổi nội dung.',
    'getDesignIntelligenceStatus'=>'Đọc tình trạng kho tri thức thiết kế.',
    'recommendDesign'=>'Đề xuất hồ sơ thiết kế theo brief.',
    'previewDesignSystem'=>'Tạo preview từ đề xuất thiết kế.',
    'enrichDesignIR'=>'Bổ sung hồ sơ thiết kế vào Design IR.',
    'auditPageUX'=>'Kiểm tra UX của một trang.',
    'previewFigma'=>'Chuyển đầu vào Figma thành preview.',
    'previewBuild'=>'Biên dịch HTML/CSS hoặc Design IR thành preview.',
    'createDraftPage'=>'Tạo trang nháp, chưa ghi cấu trúc Elementor.',
    'getPageSnapshot'=>'Đọc snapshot giới hạn của trang Elementor.',
    'applyPageBuild'=>'Áp dụng đúng preview đã được duyệt.',
    'verifyPage'=>'Đọc trạng thái, snapshot, UX và history sau thay đổi.',
    'visualFeedback'=>'So sánh kết quả với nguồn tham chiếu.',
    'autoCorrectPage'=>'Áp dụng hiệu chỉnh hình ảnh có kiểm soát.',
    'publishPage'=>'Xuất bản trang đã được người dùng duyệt.',
    'getHistory'=>'Đọc lịch sử thay đổi Design Core.',
    'rollbackHistory'=>'Hoàn tác entry đủ điều kiện, có kiểm tra xung đột.',
    'listWordPressContent'=>'Liệt kê bài viết, trang hoặc nội dung CPT.',
    'getWordPressContent'=>'Đọc nội dung và cờ do Elementor quản lý.',
    'createWordPressContent'=>'Tạo nội dung WordPress thông thường.',
    'updateWordPressContent'=>'Sửa các trường nội dung được cho phép.',
    'trashWordPressContent'=>'Chuyển nội dung vào thùng rác.',
    'restoreWordPressContent'=>'Khôi phục nội dung từ thùng rác.',
    'getWordPressSettings'=>'Đọc nhóm thiết lập website được cho phép.',
    'updateWordPressSettings'=>'Sửa nhóm thiết lập website được cho phép.',
    'getWordPressMenus'=>'Đọc menu và các mục menu.',
    'upsertWordPressMenuItem'=>'Tạo hoặc sửa một mục menu.',
    'trashWordPressMenuItem'=>'Chuyển một mục menu vào thùng rác.',
    'importWordPressMedia'=>'Nhập một URL media qua dịch vụ có kiểm soát.',
    'updateWordPressMedia'=>'Sửa tiêu đề, alt, chú thích hoặc mô tả media.',
    'trashWordPressMedia'=>'Chuyển một attachment vào thùng rác.',
);
$text = "# 03. Danh mục Owner API và MCP\n\n";
$text .= "> Tệp được sinh tự động bằng `php tools/export-mcp-contract.php --write`. Không sửa bảng bằng tay.\n\n";
$text .= "Nguồn: `core/gpt-actions-api.php` và `core/mcp-ability-bridge.php`. Có **" . count( $records ) . " operation nghiệp vụ**; endpoint công khai `GET /openapi` là đường tải tài liệu riêng, không nằm trong con số này.\n\n";
$text .= "Base path REST: `/wp-json/design-core/v1`. Tên ability chuẩn dùng `design-core/`; connector có thể hiển thị dấu `/` thành `__`. Luôn dùng tên chính xác mà discovery trả về.\n\n";
$text .= "Scope trong bảng bỏ tiền tố `design_core_` để dễ đọc. Cột đầu vào dùng tên của **MCP**, không phải tên path parameter trong URL REST. Kiểu và ràng buộc đầy đủ có trong `get_ability_info` hoặc lệnh `php tools/export-mcp-contract.php --json`.\n\n";
$text .= "Các tác vụ preview không sửa nội dung trang nhưng có thể lưu ticket tạm, cache hoặc dữ liệu phục vụ kiểm tra. Có ability trong registry không có nghĩa NHI/OAuth đã được cấp quyền gọi.\n\n";
$text .= "| Operation | Ability | REST | Quyền | Chế độ |\n|---|---|---|---|---|\n";
foreach ( $records as $record ) {
    $id = $record['operation_id'];
    if ( ! isset( $vi[ $id ] ) ) { fwrite( STDERR, "Missing Vietnamese description for {$id}.\n" ); exit( 1 ); }
    $text .= '| `' . $id . '` | `' . $record['ability'] . '` | `' . $record['method'] . ' ' . $record['path'] . '` | `' . str_replace( 'design_core_', '', $record['capability'] ) . '` | ' . ( $record['read_only'] ? 'Đọc/preview' : 'Ghi, cần xác nhận' ) . " |\n";
}
$text .= "\n## Đầu vào và mục đích từng operation\n\n";
foreach ( $records as $record ) {
    $schema = $record['input_schema'];
    $required = $schema['required'] ?? array();
    $fields = array();
    foreach ( $schema['properties'] as $field => $definition ) {
        $label = '`' . $field . '` (' . ( $definition['type'] ?? 'JSON' );
        if ( isset( $definition['minimum'] ) ) { $label .= ', ≥ ' . $definition['minimum']; }
        if ( isset( $definition['maximum'] ) ) { $label .= ', ≤ ' . $definition['maximum']; }
        if ( isset( $definition['maxLength'] ) ) { $label .= ', tối đa ' . $definition['maxLength'] . ' ký tự'; }
        $label .= in_array( $field, $required, true ) ? ', **bắt buộc**)' : ', tùy chọn)';
        $fields[] = $label;
    }
    $text .= '### `' . $record['operation_id'] . "`\n\n" . $vi[ $record['operation_id'] ] . "\n\n";
    $text .= $fields ? implode( '; ', $fields ) . ".\n\n" : "Không nhận tham số.\n\n";
}
$text .= "## Quy tắc không được bỏ qua\n\n";
$text .= "- Mọi operation ghi yêu cầu boolean `confirm: true`; chuỗi `\"true\"` không thay thế được boolean.\n";
$text .= "- `applyPageBuild` yêu cầu đúng `preview_id` và `plan_hash`; bridge không tự tạo hay sửa ticket.\n";
$text .= "- Dùng `idempotency_key` cho từng tác vụ ghi. Retry cùng tác vụ phải giữ nguyên key và payload; key đang là tham số tùy chọn trong contract, không được mô tả là luôn bắt buộc.\n";
$text .= "- `createWordPressContent`/`updateWordPressContent` cần thêm quyền publish khi yêu cầu trạng thái xuất bản. Không suy ra quyền này chỉ từ việc NHI có/không liệt kê `publish-page`.\n";
$text .= "- Không dùng generic content để ghi đè `post_content` của trang do Elementor quản lý. Không có API sửa meta/SQL/PHP tùy ý.\n";
$text .= "- `trashWordPressContent` trên trang chủ cần `confirm_front_page`; `expected_modified_gmt` hỗ trợ phát hiện thay đổi đồng thời khi endpoint có trường này.\n";
$text .= "- Menu và media chỉ hỗ trợ tập trường trong schema. Import media không đồng nghĩa được tải mã thực thi hay URL mạng riêng.\n\n";
$text .= "Xem [bảo mật](05-bao-mat.md), [kết nối MCP](04-ket-noi-mcp.md) và [quy trình làm việc](06-quy-trinh-thiet-ke.md).\n";
$target = $root . '/docs/vi/03-api-mcp.md';
if ( '--write' === $mode ) {
    if ( ! is_dir( dirname( $target ) ) ) { mkdir( dirname( $target ), 0775, true ); }
    if ( false === file_put_contents( $target, $text ) ) { fwrite( STDERR, "Cannot write generated documentation.\n" ); exit( 1 ); }
    echo "Generated docs/vi/03-api-mcp.md\n";
} else {
    if ( ! is_file( $target ) || file_get_contents( $target ) !== $text ) {
        fwrite( STDERR, "MCP documentation is stale. Run php tools/export-mcp-contract.php --write\n" );
        exit( 1 );
    }
    echo "MCP documentation matches the canonical contract.\n";
}
