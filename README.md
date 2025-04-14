# Donate

Plugin nạp thẻ cho máy chủ PocketMine-MP 5.x sử dụng API của [trumthe.vn](https://trumthe.vn/).

## Tính năng

- Nạp thẻ trực tiếp trong game
- Hỗ trợ nhiều loại thẻ khác nhau (Viettel, Mobifone, Vinaphone, ...)
- Xử lý bất đồng bộ, không làm giật lag server
- Bảng xếp hạng người chơi nạp thẻ
- Cấu hình dễ dàng
- Thông báo toàn server khi có người nạp thẻ thành công
- Hệ thống phần thưởng có thể tùy chỉnh

## Cài đặt

1. Tải file `.phar` từ [Releases](https://github.com/NhanAZ/Donate/releases)
2. Đặt vào thư mục `plugins` của server PocketMine-MP
3. Khởi động lại server
4. Cấu hình file `config.yml` trong thư mục `plugins/Donate`

## Cấu hình

Sau khi cài đặt, vui lòng chỉnh sửa file `config.yml` để thêm thông tin đối tác từ [trumthe.vn](https://trumthe.vn/):

```yaml
# Partner credentials (từ trumthe.vn)
partner_id: ""  # ID đối tác
partner_key: "" # Khóa API đối tác
```

## Lệnh

- `/donate` hoặc `/napthe` - Mở form nạp thẻ
- `/topdonate [trang]` - Xem bảng xếp hạng nạp thẻ

## Quyền

- `donate.command` - Cho phép sử dụng lệnh `/donate`
- `topdonate.command` - Cho phép sử dụng lệnh `/topdonate`

## Phần thưởng

Bạn có thể tùy chỉnh tỷ lệ phần thưởng trong file `config.yml`:

```yaml
# Reward settings
bonus_multiplier: 1.0  # Hệ số nhân điểm khi nạp thẻ thành công (1.0 = 100%)
```

Để thêm các loại phần thưởng khác, bạn có thể sửa phương thức `successfulDonation` trong file `src/Donate.php`.

## Tác giả

- [hachkingtohach1](https://github.com/hachkingtohach1)
- [TungstenVN](https://github.com/TungstenVN)
- [NhanAZ](https://github.com/NhanAZ)

## Giấy phép

Plugin này được phát hành dưới giấy phép [MIT License](LICENSE). 