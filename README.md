# Donate

Plugin nạp thẻ cho máy chủ PocketMine-MP 5.x sử dụng API của [trumthe.vn](https://trumthe.vn/).

## ✨ Tính năng

- **Nạp thẻ trực tiếp trong game** qua giao diện thân thiện
- **Hỗ trợ nhiều loại thẻ**: Viettel, Mobifone, Vinaphone, Vietnamobile, Zing
- **Xử lý bất đồng bộ**, không làm giật lag server khi xử lý thẻ
- **Bảng xếp hạng người chơi** nạp thẻ đa trang, dễ nhìn
- **Thông báo toàn server** khi có người nạp thẻ thành công
- **Hệ thống phần thưởng** có thể tùy chỉnh dễ dàng
- **Admin có thể nạp thẻ** giúp người chơi qua console
- **Hệ thống ghi log** chi tiết cho việc theo dõi và gỡ lỗi
- **Debug mode** giúp quản trị viên kiểm tra và xử lý sự cố

## 📥 Cài đặt

1. Tải file `.phar` từ [Releases](https://github.com/NhanAZ/Donate/releases)
2. Đặt vào thư mục `plugins` của server PocketMine-MP
3. Khởi động lại server
4. Cấu hình file `config.yml` trong thư mục `plugins/Donate`

## ⚙️ Cấu hình

Sau khi cài đặt, vui lòng chỉnh sửa file `config.yml` để thêm thông tin đối tác từ [trumthe.vn](https://trumthe.vn/):

```yaml
# Thông tin đối tác (từ trumthe.vn)
partner_id: ""  # ID đối tác
partner_key: "" # Khóa API đối tác

# Cài đặt phần thưởng
bonus_multiplier: 1.0  # Hệ số nhân phần thưởng khi nạp thẻ thành công (1.0 = 100%)

# Cài đặt debug
debug:
  enabled: false  # Bật/tắt chế độ debug
  notify_admins: false  # Gửi thông báo debug cho admin
  categories:  # Các danh mục debug
    general: true
    payment: true
    form: true
    api: true
    command: true
```

## 📋 Lệnh & Alias

### Lệnh Nạp Thẻ
- **Chính thức**: `/donate`
- **Alias**: `/napthe`, `/card`, `/nap`
- **Mô tả**: Mở form nạp thẻ (cho người chơi) hoặc nạp thẻ giúp người chơi (từ console)
- **Quyền hạn**: `donate.command.donate`
- **Sử dụng**:
  - Người chơi: `/donate`
  - Console: `/donate <tên người chơi> <telco> <mã thẻ> <serial> <mệnh giá>`
  - Ví dụ: `/donate NhanAZ VIETTEL 123456789012 987654321098 50000`

### Lệnh Bảng Xếp Hạng
- **Chính thức**: `/topdonate [trang]`
- **Alias**: `/topnap`, `/topcard`, `/bangxephang`, `/bxh`
- **Mô tả**: Xem bảng xếp hạng người chơi nạp thẻ
- **Quyền hạn**: `donate.command.topdonate`

### Lệnh Debug (Admin)
- **Chính thức**: `/donatedebug`
- **Alias**: `/ddebug`, `/ddbg`, `/napdebug`
- **Mô tả**: Quản lý và kiểm tra thông tin debug
- **Quyền hạn**: `donate.command.debug`
- **Các lệnh con**:
  - `/donatedebug pending` - Xem giao dịch đang xử lý
  - `/donatedebug status <requestId>` - Kiểm tra trạng thái giao dịch
  - `/donatedebug toggle <category>` - Bật/tắt debug cho danh mục
  - `/donatedebug enabledebug` - Bật debug
  - `/donatedebug disabledebug` - Tắt debug
  - `/donatedebug notifyadmins` - Bật/tắt thông báo debug
  - `/donatedebug list` - Liệt kê trạng thái debug
  - `/donatedebug loginfo` - Xem thông tin log
  - `/donatedebug clearlog` - Xóa nội dung log
  - `/donatedebug testlog` - Kiểm tra ghi log

## 🔒 Quyền Hạn

```yaml
donate.command.donate:
  default: true
  description: "Cho phép sử dụng lệnh /donate"
donate.command.topdonate:
  default: true
  description: "Cho phép sử dụng lệnh /topdonate"
donate.command.debug:
  default: op
  description: "Cho phép sử dụng lệnh /donatedebug"
donate.admin:
  default: op
  description: "Cấp quyền admin cho plugin nạp thẻ"
```

## 🛠️ Tích Hợp Vào Plugin Khác

Plugin cung cấp các API để tích hợp với các plugin khác:

```php
// Kiểm tra xem người chơi đã nạp bao nhiêu
$donatePlugin = $server->getPluginManager()->getPlugin("Donate");
$donateAmount = $donatePlugin->getDonateData()->getNested($playerName, 0);

// Đăng ký phần thưởng tùy chỉnh
// Xem thêm phương thức successfulDonation trong src/Donate.php
```

## 🎁 Phần Thưởng

Bạn có thể tùy chỉnh hệ thống phần thưởng trong file `config.yml` thông qua `bonus_multiplier`, hoặc chỉnh sửa trực tiếp phương thức `successfulDonation` trong file `src/Donate.php` để thêm các loại phần thưởng đặc biệt.

Ví dụ code thêm xu sau khi nạp thẻ thành công:
```php
// Trong phương thức successfulDonation
// $bonusAmount đã được tính toán từ $amount và $multiplier
EconomyAPI::getInstance()->addMoney($playerName, $bonusAmount);
```

## 👥 Tác Giả

- [NhanAZ](https://github.com/NhanAZ)
- [hachkingtohach1](https://github.com/hachkingtohach1)
- [TungstenVN](https://github.com/TungstenVN)

## 🧪 Testing & Triển Khai

- **Testing**: Cảm ơn Thanh Huy (Miheisu) đã giúp kiểm thử cho plugin này
- **Server sử dụng**: Bạn có thể trải nghiệm plugin này trên server **miheisu.io.vn** (cổng 19132)

## 🤖 AI Assistance

Plugin này đã được cải thiện với sự hỗ trợ của [Claude](https://www.anthropic.com/claude), một AI assistant từ Anthropic, giúp tối ưu hóa mã nguồn và nâng cao UX.

## 📜 Giấy Phép

Plugin này được phát hành dưới giấy phép [MIT License](LICENSE).

## 🤝 Hỗ Trợ

Nếu bạn gặp vấn đề hoặc có câu hỏi, vui lòng tạo [Issue](https://github.com/NhanAZ/Donate/issues) trên GitHub hoặc liên hệ với tác giả qua Discord. 