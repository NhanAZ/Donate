# Donate Plugin

Plugin nạp thẻ cho máy chủ PocketMine-MP.

## Tuyên bố từ chối trách nhiệm

**QUAN TRỌNG: Từ chối trách nhiệm**

Đây là một dự án cá nhân không được phát triển tích cực. Plugin này được cung cấp "nguyên trạng" mà không có bất kỳ bảo đảm nào, dù rõ ràng hay ngụ ý. Tôi không chịu trách nhiệm và không tham gia vào việc phát triển, bảo trì hoặc hỗ trợ plugin này.

- Không có phiên bản chính thức nào được phát hành (không có file .phar)
- Repository này tồn tại chủ yếu cho mục đích tham khảo, học tập và làm portfolio
- Việc sử dụng plugin này trong môi trường thực tế hoàn toàn thuộc trách nhiệm của người dùng
- Tôi không đảm bảo rằng plugin hoạt động đúng cách hoặc không có lỗi
- Tôi không chịu trách nhiệm về bất kỳ thiệt hại nào có thể xảy ra do sử dụng plugin này
- Plugin này không liên kết chính thức với bất kỳ dịch vụ thanh toán nào
- Mã nguồn được cung cấp cho mục đích tham khảo và có thể chứa lỗi
- Đây là dự án mã nguồn mở, mọi người có thể tự do đóng góp hoặc chỉnh sửa thông qua PR và Issue, nhưng không đảm bảo rằng các đóng góp sẽ được xem xét

### Giấy phép

Plugin này được phát hành dưới giấy phép [MIT License](LICENSE). Bạn được phép sử dụng, sao chép, sửa đổi, hợp nhất, xuất bản, phân phối, cấp phép lại và/hoặc bán các bản sao của phần mềm, nhưng phải ghi nhận tác giả gốc và bao gồm thông báo bản quyền cùng với giấy phép này trong tất cả các bản sao hoặc phần quan trọng của phần mềm.

Sử dụng plugin này đồng nghĩa với việc bạn chấp nhận các điều khoản trên.

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

1. Clone repository này: `git clone https://github.com/NhanAZ/Donate.git`
2. Copy thư mục `Donate` vào thư mục `plugins` của server PocketMine-MP
3. Cài đặt dependency [pmforms](https://github.com/dktapps/pmforms)
4. Khởi động lại server
5. Cấu hình file `config.yml` trong thư mục `plugins/Donate`

Lưu ý: Plugin này không có file `.phar`, bạn cần sử dụng trực tiếp từ mã nguồn.

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

Plugin này hiện không được bảo trì tích cực. Mặc dù bạn có thể tạo [Issue](https://github.com/NhanAZ/Donate/issues) trên GitHub, nhưng không đảm bảo sẽ có phản hồi hoặc hỗ trợ. Nếu bạn muốn sử dụng plugin này, khuyến khích bạn fork dự án và tự duy trì phiên bản của riêng mình. 