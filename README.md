## QUAN TRỌNG: Tuyến bố từ chối trách nhiệm

Đây là một dự án cá nhân không được phát triển tích cực. Plugin này được cung cấp "nguyên trạng" mà không có bất kỳ bảo đảm nào, dù rõ ràng hay ngụ ý. Tôi không chịu trách nhiệm và không tham gia vào việc phát triển, bảo trì hoặc hỗ trợ plugin này.

- Không có phiên bản chính thức nào được phát hành (không có file .phar)
- Repository này tồn tại chủ yếu cho mục đích tham khảo và học tập.
- Việc sử dụng plugin này trong môi trường thực tế hoàn toàn thuộc trách nhiệm của người dùng
- Tôi không đảm bảo rằng plugin hoạt động đúng cách hoặc không có lỗi
- Tôi không chịu trách nhiệm về bất kỳ thiệt hại nào có thể xảy ra do sử dụng plugin này
- Plugin này không liên kết chính thức với bất kỳ dịch vụ thanh toán nào
- Mã nguồn được cung cấp cho mục đích tham khảo và có thể chứa lỗi
- Đây là dự án mã nguồn mở, mọi người có thể tự do đóng góp hoặc chỉnh sửa thông qua PR và Issue, nhưng không đảm bảo rằng các đóng góp sẽ được xem xét

### Giấy phép

Plugin này được phát hành dưới giấy phép [MIT License](LICENSE). Bạn được phép sử dụng, sao chép, sửa đổi, hợp nhất, xuất bản, phân phối, cấp phép lại và/hoặc bán các bản sao của phần mềm, nhưng phải ghi nhận tác giả gốc và bao gồm thông báo bản quyền cùng với giấy phép này trong tất cả các bản sao hoặc phần quan trọng của phần mềm.

Sử dụng plugin này đồng nghĩa với việc bạn chấp nhận các điều khoản trên.

### Hướng dẫn kết nối API

#### Bước 1. Đăng ký và thiết lập API TrumThe.vn

1. Truy cập vào [TrumThe.vn](https://trumthe.vn/merchant) và đăng nhập hoặc đăng ký tài khoản
2. Vào phần "KẾT NỐI API" trong menu chính
3. Điền các thông tin cấu hình:
   - **Loại API**: Chọn "Đổi thẻ cào"
   - **Chọn ví giao dịch**: (Ví của bạn)
   - **Kiểu**: GET hoặc POST (Plugin hỗ trợ cả hai, khuyên dùng POST)
   - **Đường dẫn nhận dữ liệu (Callback URL)**: Nhập URL máy chủ của bạn, ví dụ: `https://test.pmmp.io/trumthe/callback`
   - **Địa chỉ IP (không bắt buộc)**: Địa chỉ IP của máy chủ (nếu có)

> **Lưu ý về Callback URL**: Mặc dù plugin này không yêu cầu Callback URL để hoạt động, việc điền thông tin này giúp TrumThe xác định nguồn gốc giao dịch hợp lệ. Điều này sẽ giúp bảo vệ tài khoản của bạn trong trường hợp có nghi vấn về thẻ không rõ nguồn gốc, thẻ lừa đảo hoặc thẻ bị đánh cắp. Đối với máy chủ Minecraft, bạn có thể giải thích với admin TrumThe rằng đây là IP của server game, không phải website thông thường nên không thể truy cập trực tiếp qua trình duyệt.

4. Sau khi đăng ký, bạn sẽ nhận được:
   - **Partner ID**: Được cấp bởi TrumThe
   - **Partner Key**: Chìa khóa bảo mật API

5. **Quan trọng**: Liên hệ với admin TrumThe để kích hoạt API từ trạng thái "Đang tắt" sang "Hoạt động"

#### Bước 2. Cấu hình plugin

1. Mở file `resources/config.yml`
2. Điền thông tin đã đăng ký:
```yml
# Partner credentials (từ trumthe.vn)
partner_id: "PARTNER_ID_CỦA_BẠN"  # ID đối tác
partner_key: "PARTNER_KEY_CỦA_BẠN" # Khóa API đối tác
```

#### Bước 3. Thiết lập Callback URL (Tham khảo - Có thể bỏ qua)

Nếu bạn muốn thiết lập một callback URL thực sự hoạt động (tùy chọn), bạn cần:
- Có domain trỏ đến IP máy chủ
- Thiết lập reverse proxy (nginx, apache) tới cổng máy chủ
- Đảm bảo địa chỉ callback có thể truy cập từ internet

Mẫu cấu hình Nginx:
```nginx
location /trumthe/callback {
    proxy_pass http://localhost:PORT/trumthe/callback;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

#### Bước 4. Hỗ trợ kỹ thuật

Nếu cần hỗ trợ đấu tích hợp API gạch thẻ, vui lòng liên hệ:
- **SĐT/Zalo**: 081.7577777 - 081.7377777
- Đội ngũ hỗ trợ kỹ thuật trực 24/7
- Hỗ trợ chuyển trạng thái từ "Đang tắt" thành "Hoạt động"
- Hỗ trợ giải đáp mọi thắc mắc về việc tích hợp API

## 🤖 Ghi nhận
Được hỗ trợ bởi các công cụ AI, bao gồm Claude của Anthropic.
