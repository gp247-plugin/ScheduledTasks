> 🌐 **Ngôn ngữ:** 🇻🇳 Tiếng Việt (hiện tại) · [🇬🇧 English](./readme.md) · [🇯🇵 日本語](./readme_ja.md)

# ScheduledTasks — Quản lý tác vụ định kỳ cho GP247 / S-Cart

## Giới thiệu
**ScheduledTasks** là plugin **miễn phí** giúp chủ site thấy và điều khiển **mọi tác vụ định kỳ** mà Laravel Scheduler chạy
trên site: tác vụ của lõi GP247 (ví dụ gửi mail trong hàng đợi), của các plugin và của code riêng do developer viết. Tài liệu
dành cho **chủ site / người vận hành** (cài đặt, bật bộ lập lịch, điều khiển tác vụ) và **developer** (cách thêm tác vụ mới).
Đọc xong, bạn biết cách cho bộ lập lịch chạy trên môi trường của mình, hiểu plugin áp thiết lập của admin thế nào, và vì sao
plugin **chỉ điều khiển tác vụ đã có trong code** chứ không cho tự thêm lệnh.

> **Quan trọng:** vì lý do bảo mật, màn hình **không cho thêm lệnh console mới**, không cho nhập tên lệnh hay tham số. Plugin
> chỉ điều khiển các tác vụ đã được lập lịch trong code — xem [Vì sao không cho tự thêm lệnh](#vì-sao-không-cho-tự-thêm-lệnh).

## Tính năng

| Tính năng | Mô tả |
|---|---|
| Tự phát hiện | Mọi tác vụ đăng ký bằng `Schedule::…` (ở bất kỳ đâu) tự hiện trên màn hình; tác vụ mới được gắn nhãn "Mới phát hiện" và báo lên chuông thông báo admin |
| Đổi lịch chạy | Biểu thức cron 5 trường, xem trước 5 lần chạy kế tiếp, cảnh báo khi dày hơn lịch trong code, nút khôi phục lịch trong code |
| Bật / tắt | Tạm dừng một tác vụ mà không sửa code |
| Chạy ngay | Đánh dấu để bộ lập lịch chạy tác vụ ở lượt kế tiếp (trong vòng 1 phút) — không chạy trong trình duyệt |
| Lịch sử chạy | Thời điểm, thời lượng, trạng thái, mã thoát, phần cuối output, lỗi; giữ 90 ngày và tự dọn |
| Chạy ngoài lịch | Ghi nhận lệnh artisan chạy tay hoặc từ crontab riêng (xem [Lệnh chạy ngoài lịch](#lệnh-chạy-ngoài-lịch)) |
| Tình trạng | Bộ lập lịch còn chạy không, chạy khoảng mỗi mấy phút, trên máy nào; queue worker còn sống không; báo rõ khi site **chưa có cron** và đưa sẵn dòng cron |
| Hàng đợi | Số job đang chờ (driver `database`), job lỗi trong 24 giờ, chạy lại job lỗi (không có chức năng xoá) |
| Nhật ký | Mọi thay đổi ghi vào nhật ký admin kèm giá trị cũ → mới |

Mọi tác vụ được chống chạy chồng. Tắt hoặc gỡ plugin: mọi tác vụ chạy lại đúng như code viết.

## Cài đặt

1. Chép thư mục `ScheduledTasks` vào `app/GP247/Plugins/` của site (hoặc tải lên file ZIP ở admin → **Plugin**).
2. Vào admin → **Plugin**, tìm **Scheduled Tasks** và bấm **Cài đặt**.

   Nếu thành công, menu **Hệ thống** có thêm mục **Tác vụ định kỳ**.
3. Mở **Hệ thống → Tác vụ định kỳ**.

   Lần đầu mở, bạn sẽ thấy danh sách tác vụ đang có trong lịch của site. Nếu thấy khung vàng "Bộ lập lịch chưa từng chạy trên
   site này", làm tiếp mục [Bật bộ lập lịch](#bật-bộ-lập-lịch).

**Yêu cầu:** GP247 core ≥ 3.0 và Livewire (có sẵn trong admin GP247).

## Bật bộ lập lịch

**Bộ lập lịch** (Laravel Scheduler) là phần của ứng dụng xem mỗi phút có tác vụ nào đến giờ chạy. Nó **không tự chạy** — cần một
thứ gọi lệnh `php artisan schedule:run` mỗi phút. Không có thì không tác vụ nào chạy, và mọi thiết lập trên màn hình không có tác
dụng. Chọn **một** cách hợp với môi trường của bạn:

| Môi trường | Cách chạy |
|---|---|
| Shared hosting, VPS Linux | Cron mỗi phút (các bước bên dưới) |
| VPS có supervisor | Một chương trình supervisor chạy `php artisan schedule:work` (Laravel tự gọi `schedule:run` mỗi đầu phút), dưới đúng user của web (ví dụ `www-data`) |
| Docker | Một container chạy `php artisan schedule:work` (hoặc vòng lặp `schedule:run; sleep 60`), cùng mã nguồn và biến môi trường với container web |
| Windows (máy dev) | Task Scheduler lặp mỗi 1 phút chạy `php.exe artisan schedule:run` với thư mục bắt đầu là thư mục site — hoặc để `php artisan schedule:work` chạy trong một cửa sổ terminal |

### Cách thêm cron trên hosting (cPanel và tương tự)

1. Đăng nhập trang quản trị hosting, mở mục **Cron Jobs** (tên có thể là "Tác vụ định kỳ" / "Scheduled Tasks").
2. Chọn tần suất **Mỗi phút** (Once Per Minute / `* * * * *`).
3. Ở ô lệnh, dán dòng sau — **thay** `/đường/dẫn/site` bằng thư mục site của bạn. Màn hình Tác vụ định kỳ in sẵn dòng này với
   đúng đường dẫn site, bạn có thể copy từ đó:

   ```
   cd /đường/dẫn/site && php artisan schedule:run >> /dev/null 2>&1
   ```

4. Lưu lại, đợi 1–2 phút rồi tải lại màn hình **Tác vụ định kỳ**.

   Nếu thành công, khung vàng/đỏ biến mất và thay bằng dòng xanh "Bộ lập lịch đang hoạt động — lượt gần nhất …". Sau vài lượt,
   dòng này hiện thêm "chạy khoảng mỗi N phút · máy: …".

## Vì sao không cho tự thêm lệnh

Màn hình **chỉ điều khiển các tác vụ đã có trong lịch của code** (đổi lịch, bật/tắt, chạy ngay). Nó **không** cho thêm tác vụ
mới, **không** có ô nhập tên lệnh hay tham số, và **không** cho chọn từ danh sách `php artisan list`. Lý do:

- **Một lệnh console chạy với toàn quyền của ứng dụng.** Danh sách lệnh của một site GP247 có sẵn các lệnh xoá sạch dữ liệu
  (`db:wipe`, `migrate:fresh`), gỡ plugin/template (`gp247:ext-uninstall`), đổi khoá mã hoá làm hỏng mọi khoá API đã lưu
  (`key:generate`), đưa site vào bảo trì (`down`), hoặc treo bộ lập lịch (`tinker`). Cho chọn hay nhập lệnh trên web thì **ai
  chiếm được một tài khoản admin sẽ chạy được những lệnh đó** — kể cả lập lịch để chúng tự chạy lúc nửa đêm.
- **Tham số cũng nguy hiểm như tên lệnh.** Một lệnh trông vô hại đổi hẳn hành vi với `--force`, `--execute`, `--database=…`.
- **Danh sách lệnh tự thay đổi** mỗi khi cài plugin hay package mới, không ai rà soát — một danh sách "lệnh bị cấm" sẽ luôn lỗi thời.
- **Code là nơi duy nhất quyết định chạy cái gì.** Tác vụ trong code được developer viết, kiểm tra và triển khai cùng ứng dụng;
  admin chỉ điều chỉnh **khi nào** chạy và **có chạy hay không**.

"Chạy ngay" cũng không chạy lệnh trong trình duyệt: nó chỉ đánh dấu để bộ lập lịch chạy ở lượt kế tiếp. Tham số thêm cho
"Chạy ngay" (nếu cần) chỉ khai báo được trong file cấu hình của site (`run_now_args`), không bao giờ lấy từ trình duyệt.

## Cách thêm một tác vụ định kỳ (dành cho developer)

Thêm tác vụ là việc của **developer, trong code**. Sau khi triển khai, tác vụ tự xuất hiện trên màn hình ở lượt lập lịch kế tiếp
(nhãn "Mới phát hiện" + chuông thông báo); từ đó admin điều khiển nó như mọi tác vụ khác.

### Ở code riêng của site — file `routes/console.php`

```php
<?php
use Illuminate\Support\Facades\Schedule;

// 1) Lệnh artisan (cách khuyến nghị)
Schedule::command('report:send --daily')->dailyAt('07:00');

// 2) Job đưa vào hàng đợi — định danh là tên class job
Schedule::job(new \App\Jobs\SyncStock)->everyFifteenMinutes();

// 3) Closure — BẮT BUỘC đặt tên, nếu không sẽ không điều khiển được
Schedule::call(fn () => \App\Support\Cleanup::run())
    ->name('cleanup-temp-files')
    ->hourly();
```

### Ở plugin GP247 — file `Provider.php`, bên trong khối kiểm tra plugin đang bật

```php
if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {
    // ...
    $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
        $schedule->command('myplugin:sync')->everyThirtyMinutes();
    });
}
```

Đặt trong khối "plugin đang bật" để khi tắt plugin, tác vụ cũng biến khỏi lịch.

### Quy tắc định danh — cần biết trước khi viết

Plugin nhận ra một tác vụ qua **định danh** của nó; mọi thiết lập của admin (lịch riêng, bật/tắt) gắn với định danh đó:

| Loại | Định danh | Ví dụ |
|---|---|---|
| Lệnh artisan | Tên lệnh + tham số (bỏ đường dẫn `php`/`artisan`) | `report:send --daily` |
| Job | Tên class job | `App\Jobs\SyncStock` |
| Closure | Tên đặt bằng `->name()` | `cleanup-temp-files` |
| Lệnh hệ thống (`Schedule::exec`) | Chuỗi lệnh | `/usr/bin/backup.sh` |

- **Đổi tham số = tác vụ mới.** `report:send --daily` đổi thành `report:send --day` là một tác vụ khác: tác vụ cũ hiện "Không
  còn trong code" (giữ lịch sử), tác vụ mới chạy theo lịch trong code, thiết lập admin của tác vụ cũ không chuyển sang.
- **Đổi lịch trong code giữ nguyên định danh.** Nếu admin đang đặt lịch riêng, màn hình báo "Lịch trong code đã đổi: X → Y".
- Cùng một định danh khai báo hai lần → thêm hậu tố `#2`, `#3` theo thứ tự khai báo.
- Closure không có `->name()` chỉ được đếm và cảnh báo "không định danh được".

### Nên và không nên

- **Nên** đưa mọi việc định kỳ vào Laravel Schedule thay vì crontab riêng của hosting — script ngoài lịch (bash, curl…) ứng
  dụng không thể thấy.
- **Không cần** tự thêm `withoutOverlapping()`: plugin đã chống chạy chồng cho mọi tác vụ. Nếu bạn đặt `withoutOverlapping(n)`,
  thời hạn khoá `n` của bạn được giữ.
- `runInBackground()`, `onOneServer()`, `timezone()`, `environments()` vẫn hoạt động như Laravel; màn hình tính "lần kế tiếp"
  theo đúng múi giờ của tác vụ.
- Lệnh cần tham số riêng khi "Chạy ngay" (ví dụ `--force` để bỏ qua khung giờ tự kiểm): khai trong `run_now_args` của site
  (xem [Tuỳ chỉnh](#tuỳ-chỉnh)).

## Nguyên tắc hoạt động

### Mỗi lượt lập lịch

Mỗi lượt `schedule:run` (mỗi phút), **trước khi** Laravel chọn tác vụ đến hạn, plugin:

1. Ghi nhịp tim của bộ lập lịch (thời điểm, máy chạy).
2. Đọc danh sách tác vụ trong lịch, thêm tác vụ mới, cập nhật tác vụ đã có.
3. Áp thiết lập của admin lên từng tác vụ (bảng dưới), rồi để Laravel chạy như bình thường.

Plugin **không sửa code** của tác vụ nào; nó chỉ chỉnh lịch, điều kiện chạy và khoá chống chạy chồng trong lượt đó.

### Thiết lập của admin được áp thế nào

| Thiết lập | Tác dụng |
|---|---|
| **Lịch riêng (ghi đè)** | Lịch hiệu lực = lịch admin đặt; không có thì dùng lịch trong code. Có hiệu lực từ lượt kế tiếp, không cần khởi động lại gì |
| **Khôi phục lịch trong code** | Xoá lịch riêng; từ đó tác vụ đi theo code, kể cả khi developer đổi lịch sau này |
| **Tắt** | Tác vụ không chạy khi đến giờ. Lượt đang chạy không bị dừng. Tác vụ tắt có badge "Đã tắt" nổi bật và xếp lên đầu danh sách |
| **Bật** | Chạy lại theo lịch hiệu lực |
| **Chạy ngay** | Đánh dấu; lượt kế tiếp chạy tác vụ **một lần** (kể cả tác vụ đang tắt), rồi xoá dấu |
| **Đã xem** | Bỏ nhãn "Mới phát hiện" |

Mọi thay đổi ghi nhật ký admin: ai, khi nào, IP, tác vụ, giá trị cũ → mới.

### Trạng thái của một lượt chạy

| Trạng thái | Nghĩa |
|---|---|
| Đang chạy | Đã bắt đầu, chưa kết thúc |
| Thành công / Thất bại | Kết thúc với mã thoát 0 / khác 0 (hoặc lỗi — xem chi tiết trong lịch sử) |
| Bỏ qua (lượt trước chưa xong) | Đến giờ nhưng lượt trước của chính tác vụ đó chưa xong (khoá chống chạy chồng) |
| Dừng bất thường | "Đang chạy" quá 120 phút không kết thúc (tiến trình bị dừng giữa chừng); nếu sau đó kết thúc thật thì cập nhật lại |
| Chưa chạy | Tác vụ chưa có lượt chạy nào |

### Lệnh chạy ngoài lịch

Lệnh artisan chạy tay hoặc từ crontab riêng được ghi lại ở tab **Chạy ngoài lịch** (chỉ xem) khi thuộc **một** trong các nhóm:

- lệnh của GP247, plugin hoặc code riêng của site (kể cả lệnh viết trong `routes/console.php`);
- lệnh đang có trong lịch (kể cả lệnh của package khác) — lượt chạy tay được gộp vào đúng tác vụ đó;
- lệnh khớp danh sách `external_commands` trong cấu hình site.

Tiến trình do chính bộ lập lịch sinh ra không bị ghi trùng. Lệnh khác của framework/package (ví dụ `migrate`, `optimize:clear`)
và script không phải lệnh artisan không được ghi.

### Tình trạng bộ lập lịch

Plugin không đọc crontab hay danh sách tiến trình — nó nhận biết từng **lượt** `schedule:run` đã chạy qua và lưu trong cơ sở dữ
liệu (không mất khi xoá cache). Màn hình đo khoảng cách giữa các lượt ("chạy khoảng mỗi N phút") và liệt kê máy đang chạy. Lịch bị
tạm dừng bằng `php artisan schedule:pause` được báo riêng.

### Khi có sự cố

- Không đọc được thiết lập (cơ sở dữ liệu lỗi, bảng chưa có) hoặc ghi lịch sử thất bại → mọi tác vụ **chạy đúng như code viết**,
  lỗi được ghi log; plugin không bao giờ làm dừng lịch.
- Tắt hoặc gỡ plugin → không còn lớp điều khiển, mọi tác vụ chạy như code viết.

## Phân quyền

| Ai | Được làm |
|---|---|
| Vai `administrator` | Mọi thao tác |
| Vai `view.all` | Chỉ xem |
| Quyền **Scheduled tasks — view** | Chỉ xem |
| Quyền **Scheduled tasks — configure** | Xem + đổi lịch, bật/tắt, chạy ngay, chạy lại job lỗi |

Đây là cấu hình toàn site: admin của từng cửa hàng (multi-store) không truy cập được.

## Tuỳ chỉnh

Không sửa `config.php` của plugin — file đó bị ghi đè mỗi lần cập nhật plugin. Thay vào đó:

1. Tạo file `config/scheduled-tasks.php` ở **thư mục gốc của site** (cạnh `config/app.php`).
2. Chỉ ghi các khoá bạn muốn đổi, ví dụ:

   ```php
   <?php
   return [
       'retention_days' => 30,              // số ngày giữ lịch sử chạy (mặc định 90)
       'scheduler_stale_minutes' => 5,      // ngưỡng tối thiểu (phút) trước khi coi bộ lập lịch đã dừng (mặc định 3)
       'record_external' => true,           // ghi lệnh chạy ngoài lịch (mặc định bật)
       'external_commands' => ['backup:*'], // theo dõi thêm lệnh của package khi chạy ngoài lịch
       'run_now_args' => [                  // tham số thêm khi "Chạy ngay", theo định danh tác vụ
           'report:send --daily' => '--force',
       ],
   ];
   ```

3. Nếu site có bật cache cấu hình, chạy lại `php artisan config:cache` để nạp giá trị mới.

Dọn lịch sử thủ công: `php artisan scheduled-tasks:purge-runs` chỉ **đếm** số dòng sẽ xoá; thêm `--execute` mới xoá thật.

## Gỡ cài đặt

Gỡ plugin sẽ xoá 3 bảng của plugin (lịch sử chạy, nhịp tim bộ lập lịch, lịch / bật-tắt đã đặt); mọi tác vụ trở về lịch trong
code. Code của các tác vụ không bị ảnh hưởng.

## Giấy phép

MIT — miễn phí.

## Điều kiện & ràng buộc (hiểu trước khi thao tác)

**Khi đổi lịch chạy**
- **Cron phải đúng 5 trường** (phút giờ ngày tháng thứ) — sai cú pháp thì báo lỗi và giữ nguyên lịch cũ, để tác vụ không bao giờ
  rơi vào trạng thái "không có lịch".
- **Lưu một lịch trùng lịch trong code = khôi phục** — không tạo lịch riêng, để sau này developer đổi lịch trong code thì tác vụ đi theo.
- **Tác vụ chạy dưới 1 phút không đổi lịch được** — biểu thức cron nhỏ nhất là mỗi phút, không diễn tả được "mỗi 10 giây".
- **Lịch dày hơn lịch trong code vẫn lưu được nhưng có cảnh báo** — tác vụ nặng chạy quá dày có thể làm quá tải hosting.

**Khi bật/tắt, chạy ngay**
- **Chỉ tác vụ còn trong lịch của code mới điều khiển được** — tác vụ "Không còn trong code" và lệnh chạy ngoài lịch chỉ xem,
  vì không còn (hoặc chưa bao giờ có) lịch để điều khiển.
- **"Chạy ngay" bị khoá khi bộ lập lịch không chạy** — dấu sẽ không bao giờ được xử lý, khoá lại để bạn không tưởng đã chạy.
- **Đang chờ chạy thì không bấm thêm được** — mỗi tác vụ chỉ có một dấu chờ; lượt chạy xong mới nhận dấu mới.
- **Lượt trước chưa xong thì lượt mới chờ** — chống chạy chồng hai lượt của cùng tác vụ.

**Khi xử lý hàng đợi**
- **Chỉ chạy lại được job lỗi đang có trong danh sách**, **không có chức năng xoá** — xoá job là thao tác không khôi phục được.
- **Số job đang chờ chỉ đếm được với driver `database`**; với `sync` màn hình nói rõ site không dùng hàng đợi thay vì hiện số 0.

**Về quyền và phạm vi**
- **Cần quyền "configure"** (hoặc vai `administrator`) cho mọi thao tác ghi — người chỉ có quyền xem không thấy nút, và nếu cố
  gọi thì bị từ chối.
- **Admin của từng cửa hàng (multi-store) không truy cập được** — lịch chạy là của cả site, không thuộc riêng cửa hàng nào.

## Hỏi & Đáp (Q&A)

**Câu 1: Màn hình báo "Bộ lập lịch chưa từng chạy" dù tôi đã cài plugin?**

→ Plugin không tự chạy bộ lập lịch; site cần một cron (hoặc supervisor/Docker) gọi `schedule:run` mỗi phút. Làm theo mục
[Bật bộ lập lịch](#bật-bộ-lập-lịch), đợi 1–2 phút rồi tải lại trang.

**Câu 2: Tôi có thể thêm một lệnh mới để chạy hằng ngày ngay trên màn hình không?**

→ Không, có chủ đích vì bảo mật (xem [Vì sao không cho tự thêm lệnh](#vì-sao-không-cho-tự-thêm-lệnh)). Nhờ developer khai báo
tác vụ trong code; sau khi triển khai, nó tự hiện trên màn hình để bạn điều khiển.

**Câu 3: Tôi đổi lịch rồi, bao lâu thì có hiệu lực?**

→ Từ lượt lập lịch kế tiếp, thường trong vòng 1 phút. Không cần khởi động lại gì.

**Câu 4: Bấm "Chạy ngay" mà tác vụ không chạy?**

→ Kiểm tra dòng tình trạng bộ lập lịch: nếu bộ lập lịch không chạy thì dấu không được xử lý. Nếu lượt trước của tác vụ còn chạy
dở, lượt mới chờ tới khi lượt trước xong. Xem kết quả ở tab lịch sử của tác vụ.

**Câu 5: Tắt một tác vụ có làm dừng lượt đang chạy không?**

→ Không. Tắt chỉ ngăn các lượt sau; lượt đang chạy vẫn chạy tới khi xong.

**Câu 6: Developer đổi lịch trong code, nhưng tôi đã đặt lịch riêng thì sao?**

→ Lịch riêng của bạn vẫn được dùng, và màn hình báo "Lịch trong code đã đổi: X → Y". Muốn đi theo code, bấm "Khôi phục lịch trong code".

**Câu 7: Vì sao tab "Chạy ngoài lịch" không thấy lệnh tôi vừa chạy tay?**

→ Chỉ lệnh của GP247/plugin/code riêng, lệnh có trong lịch, và lệnh khớp `external_commands` mới được ghi. Thêm mẫu tên lệnh vào
`external_commands` trong `config/scheduled-tasks.php` nếu muốn theo dõi lệnh của package khác.

**Câu 8: Hosting của tôi chạy cron mỗi 5 phút, màn hình có báo lỗi liên tục không?**

→ Không. Plugin đo chu kỳ thực tế và chỉ báo "không chạy" khi quá max(3 phút, 2 × chu kỳ) — với cron 5 phút là sau 10 phút không có lượt mới.

**Câu 9: Xoá cache (`php artisan cache:clear`) có làm mất trạng thái bộ lập lịch không?**

→ Không. Nhịp tim bộ lập lịch lưu trong cơ sở dữ liệu của plugin, không nằm trong cache.

**Câu 10: Gỡ plugin có làm mất tác vụ của site không?**

→ Không. Tác vụ nằm trong code nên vẫn chạy bình thường, theo đúng lịch trong code. Chỉ lịch sử chạy và các thiết lập bạn đã đặt
trên màn hình bị xoá.

---

<sub>📅 **Cập nhật lần cuối:** 2026-10-22 · ✍️ **Tác giả (Author):** GP247</sub>
