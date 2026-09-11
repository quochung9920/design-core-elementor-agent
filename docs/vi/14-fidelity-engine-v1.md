# Fidelity Engine V1

Fidelity Engine V1 bổ sung lớp hợp đồng trung thực nguồn cho pipeline HTML/CSS → Design IR → Elementor. Mục tiêu là ngăn một trang “Elementor hợp lệ” nhưng làm rơi cấu trúc, ảnh, form hoặc các thuộc tính thiết kế quan trọng của source.

## Thứ tự quyết định mới

```text
HTML/CSS
→ merge CSS nhúng trong <style>
→ resolve biến :root đơn giản
→ Static CSS + Browser Analysis (nếu runtime có Playwright)
→ Design IR
→ Component Graph
→ Source Fidelity Contract
→ Source Asset Contract
→ Semantic/Build planning
→ Native Elementor mapping
→ Control Coverage
→ Critical Atom Verification
→ Draft/QA
```

Fidelity Engine không thay Browser Visual QA. `Critical Atom Verification=PASS` chỉ chứng minh các atom bắt buộc như ảnh/form/field không bị mất khi compile; nó không có nghĩa pixel, focus, interaction hoặc responsive đã PASS.

## Component Graph và composition policy

Mỗi node Design IR nhận `semantic.composition_policy`:

- `leaf-native`: leaf có thể map trực tiếp thành widget phù hợp.
- `structural-native`: wrapper cấu trúc đơn giản.
- `preserve-children`: wrapper sở hữu nhiều atom độc lập; planner phải giữ composition thay vì collapse thành một widget.
- `replace-with-verified-native`: semantic component chỉ được collapse khi adapter native đã xác minh contract, ví dụ FAQ/navigation/form.

`preserve-children` có quyền ưu tiên cao hơn shallow widget reuse. BuildPlan Validator fail-closed nếu một regression cố dùng `native-widget`, `reuse-widget` hoặc `custom-widget` cho node này.

## Source CSS fidelity

Analysis Engine hiện:

- đọc cả CSS truyền riêng và mọi `<style>` nhúng trong HTML;
- bỏ `style/script/link/meta/title/base/noscript/template` khỏi cây giao diện;
- resolve `var(--token)` khi token được định nghĩa trong `:root`;
- map padding/margin/radius shorthand 1–4 giá trị thành dimension native;
- giữ row/column gap khác nhau;
- đọc fixed grid column count như `repeat(2, 1fr)`;
- đọc `text-align` và border shorthand;
- tiếp tục đẩy property chưa map được vào scoped fallback thay vì đoán control private.

CSS variable theo context, auto-fit/auto-fill và biểu thức không thể chứng minh bằng static parser vẫn không được giả định. Browser Analysis là lớp bằng chứng cao hơn khi có runtime.

## Source Asset Contract

Source media phải có địa chỉ có nghĩa trên site đích trước khi BuildPlan được xem là an toàn.

Các URL `https://...`, `http://...`, `//host/...` và đường dẫn site-root như `/wp-content/uploads/...` có thể đi tiếp qua pipeline. Reference dạng bundle tương đối như `assets/hero.webp`, `./hero.webp`, `../images/card.webp`, URL trống hoặc scheme không hỗ trợ bị đánh dấu unresolved.

BuildPlan fail-closed khi còn unresolved source media. Design Core không được tự tìm ảnh “gần giống”, generate ảnh thay thế hoặc viết một URL hỏng chỉ để build thành công. Khi source dùng bundle asset tương đối, client phải cung cấp asset/base context hoặc import media thật trước.

## Form fidelity

Trước planning, form source được hydrate từ DOM:

- `label[for]` → field tương ứng;
- giữ `name/id`, field type, placeholder, required;
- giữ submit button text;
- tạo `custom_id` ổn định khi map Elementor Form;
- `submit_actions=[]` mặc định.

Engine không tự cấu hình Email, CRM, Webhook hay lưu submission khi source không cung cấp contract tương ứng.

## Control Coverage

Mỗi lần `Elementor_Mapping_Engine` gọi live `Widget_Control_Mapper`, mapping report ghi coverage theo `node_id` cho các nhóm:

- content;
- media;
- interaction;
- layout;
- responsive;
- style;
- spacing;
- editability.

Coverage dựa trên control thực sự được mapper dùng, scoped fallback và unsupported control; nó không dùng widget prestige làm bằng chứng. Content/media/interaction có trọng số cao hơn cosmetic style.

## Critical Atom Verification

Sau V3 semantic mapping, engine so source contract với Elementor tree. Build fail-closed nếu làm rơi:

- source image widget atom;
- source form atom;
- source field atom khi form đã được map.

Điều này ngăn build tiếp tục thành công khi ví dụ card `heading + text + image` bị biến thành một Text Editor và mất ảnh.

## Browser runtime trong Docker

`Browser_Analysis_Service::is_available()` dùng `scripts/browser-probe.mjs` nằm trong plugin thay vì `node -e import('playwright')`. Cách cũ có thể false-negative trong Docker vì Node resolve module từ WordPress working directory thay vì thư mục plugin.

VPS vẫn phải có dependencies và Chromium executable, ví dụ theo deployment policy của server:

```bash
npm ci
npx playwright install chromium
```

Không báo Browser QA PASS chỉ vì package tồn tại. Runtime probe và browser comparison thật phải PASS.

## Regression

Suite mới:

```bash
php tests/fidelity-engine-v1/run.php
npm run check
```

Suite kiểm composition preservation, form hydration, critical atom fail-closed, unresolved asset gate, BuildPlan guard, control coverage, embedded CSS, root variables, grid/gap/padding/border mapping.