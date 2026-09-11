# 02. Cài đặt và vận hành trên VPS/Docker

## Không dựng lại một hệ thống đang chạy

Dự án đã có VPS, Docker và domain `designcorehub.online`; trước đây cũng sử dụng `staging.designcorehub.online`. Không suy ra hostname nào đang phục vụ WordPress chỉ từ tên miền. Xác minh qua cấu hình proxy, public URL của WordPress và request thực tế.

`docker-compose.yml` ở gốc repository là **stack mẫu phát triển local/WSL**, không phải bằng chứng về compose file đang chạy trên VPS. Mẫu có `db`, `wordpress`, `wpcli`, `design-core-mcp`, `caddy`, volume dữ liệu và bind-mount plugin. Không chạy mẫu này đè lên stack VPS hiện hữu.

## Kiểm kê trước thay đổi

Thực hiện trong repository/deployment directory đã xác định:

```bash
pwd
git status --short
git branch --show-current
git log -5 --oneline
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Ports}}\t{{.Status}}'
docker compose config --services
docker compose ps
```

Xác định compose file/override, service WordPress, database, proxy, network, bind-mount hay image chứa code. Không dùng `docker inspect` toàn bộ rồi đưa output lên chat vì phần environment có thể chứa mật khẩu. Chỉ đọc trường cần thiết:

```bash
docker inspect --format '{{json .Mounts}}' TEN_CONTAINER_WORDPRESS
docker inspect --format '{{json .NetworkSettings.Networks}}' TEN_CONTAINER_WORDPRESS
```

Tên container trong ví dụ là placeholder; thay bằng tên đã kiểm kê. Không tự động cấp quyền SSH, Docker socket hay database cho ChatGPT.

## Yêu cầu phần mềm

Baseline dự án trong README là WordPress 6.5+, PHP 8.1+ và Elementor. Elementor Pro, Figma và browser tooling là các khả năng tùy chọn.

Đường WordPress abilities còn yêu cầu runtime thực sự có `wp_register_ability`, `wp_get_ability` và `wp_get_abilities`, cùng connector hỗ trợ cách expose tương ứng. Không suy ra hỗ trợ abilities từ baseline WordPress 6.5.

Cài dependency theo lockfile của repository trong đúng môi trường build. Không cài phiên bản “mới nhất” tùy tiện lên VPS đang chạy.

## Xác minh domain và đường dẫn

```bash
curl -I --max-time 15 https://designcorehub.online/
curl -i --max-time 15 https://designcorehub.online/wp-json/design-core/v1/openapi
curl -i --max-time 15 https://designcorehub.online/wp-json/design-core/v1/site/status
```

Các URL trên là ví dụ với domain chính. Nếu WordPress nằm ở staging subdomain, dùng URL đã xác minh của instance đó.

`/openapi` phải trả JSON OpenAPI. `/site/status` không có xác thực phải bị chặn; lỗi 404 HTML không phải bằng chứng auth đang an toàn.

Nếu pretty REST URL không chạy, so sánh đường chẩn đoán:

```text
/?rest_route=/design-core/v1/openapi
```

Đường fallback chạy nhưng `/wp-json/...` lỗi thường chỉ ra vấn đề rewrite; cần tìm nguyên nhân ở proxy/Apache/WordPress thay vì bỏ xác thực để “sửa API”.

## Cấu hình Owner API

Đăng nhập đúng user dự kiến sở hữu API, vào **Design Core → API Access**, claim owner rồi bật API. Không chọn một administrator ngẫu nhiên và không sửa trực tiếp option owner.

Đối với REST, tạo credential riêng cho từng client, scope tối thiểu và thời hạn phù hợp. Đối với WordPress MCP bridge, kết nối OAuth phải resolve đúng user owner; không cần một `dcapi_*` đặt trong bridge.

**Remote Access** có công tắc remote writes riêng. Khi tắt writes, đọc/preview vẫn có thể hoạt động. Khi tắt Owner API, bridge version 2 cũng chặn các scoped abilities.

## Triển khai code

Trước thay đổi đáng kể, xác minh backup database/volume và cách restore. Giữ ghi nhận commit trước triển khai. Không dùng `git reset --hard`, `git clean -fd`, `docker compose down -v` hoặc xóa volume để đồng bộ code.

Khi working tree sạch và đã xác định đúng branch:

```bash
git fetch origin
git status --short
git log --oneline HEAD..origin/main
git diff --stat HEAD..origin/main
git pull --ff-only origin main
```

Nếu có local changes hoặc branch phân kỳ, dừng để hợp nhất có chủ đích; không ghi đè.

Bind-mount thường nhận file mới mà không rebuild image. Tuy nhiên PHP OPcache hoặc cấu hình runtime có thể cần reload service. Image chứa sẵn code phải build/redeploy đúng service. Xác định bằng mount/image thực tế, không chỉ bằng báo cáo cũ.

Không restart database để cập nhật PHP plugin. Validate cấu hình proxy trước reload; giữ phương án quay lại cấu hình trước đó.

## API hostname tùy chọn

`deploy/caddy/Caddyfile.api-only` là mẫu cho:

```text
api.designcorehub.online/v1/*
  → WordPress /?rest_route=/design-core/v1/*
```

Mẫu chỉ phục vụ `/v1/*`; đường ngoài prefix trả 404. Đây là giới hạn routing, không thay thế xác thực.

Chỉ dùng sau khi DNS, TLS, upstream và Docker network được xác minh. Không đổi domain đang chạy chỉ vì tài liệu có mẫu này. Khi thực sự dùng host riêng, cấu hình `DESIGN_CORE_API_PUBLIC_URL` để OpenAPI quảng bá đúng base URL.

Proxy phải giữ Authorization, query string, method và body; kiểm thử query như `limit`/`q`, không chỉ `/openapi`. Host API trả 404 cho route khác không có nghĩa hostname WordPress gốc cũng đã được khóa.

## Kiểm tra sau triển khai

Chạy lint/test trước, rồi chạy read-only runtime verifier bằng WP-CLI có sẵn trong deployment:

```bash
wp --user=OWNER_ID eval-file wp-content/plugins/design-core-elementor/tests/mcp-abilities/runtime-read.php
```

Nếu dùng service WP-CLI của compose mẫu, lệnh tương ứng bắt đầu bằng `docker compose run --rm wpcli`; không giả định service này tồn tại trên VPS thật.

Sau đó cập nhật grant NHI cho các ability mới bằng UI/API chính thức của connector, kiểm tra từ ChatGPT thật và chỉ tiếp tục write E2E trên trang nháp riêng. Xem [kiểm thử và phát hành](07-kiem-thu-phat-hanh.md).

Database không nên có port public. Giữ SSH/firewall trong phạm vi quản trị VPS hiện tại; không sửa firewall mù hoặc đóng cổng quản trị khi chưa có đường truy cập thay thế.
