# TCross User Manager

結合 WooCommerce 的雙類型用戶註冊系統，支援需求單位和綠照師註冊。

## 功能特色

- 🏢 **需求單位註冊**：企業或組織可以註冊為需求單位
- 👨‍🏫 **綠照師註冊**：個人可以註冊為綠照師
- 📝 **Elementor 表單整合**：自動處理 Elementor 表單提交
- ⚖️ **審核流程**：管理員可審核申請並管理狀態
- 🎛️ **後台管理**：完整的用戶管理介面
- 📊 **統計報表**：用戶註冊統計和分析
- 🔍 **搜尋功能**：快速查找特定用戶
- 📤 **數據匯出**：支援 CSV 和 JSON 格式匯出
- 📧 **自動通知**：申請提交和審核結果自動發送郵件

## 安裝步驟

1. 確保您的 WordPress 網站已安裝並啟用 WooCommerce
2. 將此插件上傳到 `/wp-content/plugins/tcross-user-manager/` 目錄
3. 在 WordPress 後台啟用插件
4. 前往 「TCross 用戶」 管理頁面進行設定

## 使用方法

### 前台註冊

#### Elementor 表單註冊（推薦）
1. **綠照師申請**：前往「加入綠照夥伴」頁面 (post_id: 1254)
2. **需求單位申請**：前往「加入綠照地圖」頁面 (post_id: 1325)
3. 填寫詳細的申請表單
4. 提交後進入待審核狀態
5. 管理員審核通過後自動創建帳號

#### WooCommerce 註冊（備用）
1. 用戶前往 WooCommerce 註冊頁面（通常是 `/my-account/`）
2. 在註冊表單中選擇用戶類型：
   - 「註冊需求單位」- 適合企業、組織
   - 「註冊綠照師」- 適合個人專業人士
3. 完成其他必填資訊並提交註冊

### 後台管理
1. 登入 WordPress 後台
2. 點擊左側選單的 「TCross 用戶」
3. 可以查看：
   - **表單審核**：查看和審核 Elementor 表單提交
   - 用戶統計儀表板
   - 需求單位列表
   - 綠照師列表
   - 系統設定
   - 數據匯出功能

#### 審核流程
1. 用戶提交 Elementor 表單後，狀態為「待審核」
2. 管理員收到通知郵件
3. 在「表單審核」頁面查看申請詳情
4. 可以設定狀態為：
   - **待審核**：初始狀態
   - **審核中**：正在處理
   - **已通過**：自動創建用戶帳號並發送歡迎郵件
   - **已拒絕**：拒絕申請並可添加備註

## 系統需求

- WordPress 5.0 或更高版本
- WooCommerce 5.0 或更高版本
- PHP 7.4 或更高版本

## 文件結構

```
tcross-user-manager/
├── main.php           # 主插件文件
├── table.php          # 資料庫操作
├── api.php            # API 端點處理
├── admin-page.php     # 後台管理頁面
└── assets/            # 資源文件目錄
    ├── css/           # 樣式文件
    └── js/            # JavaScript 文件
```

## 開發者說明

### 資料庫表格
插件會創建兩個自定義表格：
- `wp_tcross_user_types`：儲存用戶類型資訊
- `wp_tcross_form_submissions`：儲存 Elementor 表單提交數據

### 用戶角色
- `demand_unit` - 需求單位角色
- `green_teacher` - 綠照師角色

### Hook 和 Filter
- `woocommerce_register_form_start` - 添加用戶類型選擇
- `woocommerce_created_customer` - 保存用戶類型
- `elementor_pro/forms/new_record` - 處理 Elementor 表單提交

### AJAX 端點
- `tcross_get_form_submissions` - 獲取表單提交列表
- `tcross_update_submission_status` - 更新審核狀態
- `tcross_get_submission_details` - 獲取申請詳情

## 故障排除

### 常見問題

**Q: 註冊頁面沒有顯示用戶類型選擇？**
A: 請確認 WooCommerce 已正確安裝並啟用。

**Q: 後台看不到 TCross 用戶選單？**
A: 請確認您具有管理員權限。

**Q: 註冊後用戶類型沒有保存？**
A: 檢查資料庫表格是否正確創建，可以嘗試停用並重新啟用插件。

## 更新日誌

### 1.0.0
- 初始版本發布
- 支援雙類型用戶註冊
- 後台管理介面
- 統計報表功能

## 技術支援

如有問題請聯繫 TCross 技術團隊。

## 授權

GPL v2 or later
