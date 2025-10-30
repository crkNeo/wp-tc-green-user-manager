<?php
/**
 * TCross User Manager - Admin Page
 * 後台管理介面
 */

if (!defined('ABSPATH')) {
    exit;
}

class TCrossAdminPage {

    /**
     * 顯示管理頁面
     */
    public static function display_admin_page() {
        // 檢查權限
        if (!current_user_can('manage_options')) {
            wp_die('您沒有足夠的權限訪問此頁面。');
        }

        // 處理表單提交
        if (isset($_POST['action']) && wp_verify_nonce($_POST['tcross_admin_nonce'], 'tcross_admin_action')) {
            self::handle_admin_actions();
        }

        // 獲取統計數據
        $stats = TCrossUserTable::get_user_type_stats();

        ?>
        <div class="wrap">
            <h1>TCross 用戶管理</h1>

            <!-- 統計儀表板 -->
            <div class="tcross-dashboard">
                <h2>用戶統計</h2>
                <div class="tcross-stats-grid">
                    <?php self::render_stats_cards($stats); ?>
                </div>
            </div>

            <!-- 用戶管理選項卡 -->
            <div class="tcross-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="#form-submissions" class="nav-tab nav-tab-active">表單審核</a>
                    <a href="#demand-units" class="nav-tab">需求單位</a>
                    <a href="#green-teachers" class="nav-tab">綠照師</a>
                    <a href="#settings" class="nav-tab">設定</a>
                    <a href="#export" class="nav-tab">匯出</a>
                </nav>

                <!-- 表單審核選項卡 -->
                <div id="form-submissions" class="tab-content active">
                    <h3>表單提交審核</h3>
                    <?php self::render_form_submissions_table(); ?>
                </div>

                <!-- 需求單位選項卡 -->
                <div id="demand-units" class="tab-content">
                    <h3>需求單位管理</h3>
                    <?php self::render_user_table('demand_unit'); ?>
                </div>

                <!-- 綠照師選項卡 -->
                <div id="green-teachers" class="tab-content">
                    <h3>綠照師管理</h3>
                    <?php self::render_user_table('green_teacher'); ?>
                </div>

                <!-- 設定選項卡 -->
                <div id="settings" class="tab-content">
                    <h3>系統設定</h3>
                    <?php self::render_settings_form(); ?>
                </div>

                <!-- 匯出選項卡 -->
                <div id="export" class="tab-content">
                    <h3>數據匯出</h3>
                    <?php self::render_export_form(); ?>
                </div>
            </div>
        </div>

        <?php self::render_admin_styles_and_scripts(); ?>
        <?php
    }

    /**
     * 渲染統計卡片
     */
    private static function render_stats_cards($stats) {
        $total_stats = array(
            'total' => 0,
            'active' => 0,
            'today' => 0,
            'week' => 0,
            'month' => 0
        );

        foreach ($stats as $type_stats) {
            $total_stats['total'] += $type_stats['total'] ?? 0;
            $total_stats['active'] += $type_stats['active'] ?? 0;
            $total_stats['today'] += $type_stats['today'] ?? 0;
            $total_stats['week'] += $type_stats['week'] ?? 0;
            $total_stats['month'] += $type_stats['month'] ?? 0;
        }

        $cards = array(
            array(
                'title' => '總用戶數',
                'value' => $total_stats['total'],
                'icon' => 'dashicons-groups',
                'color' => 'blue'
            ),
            array(
                'title' => '活躍用戶',
                'value' => $total_stats['active'],
                'icon' => 'dashicons-admin-users',
                'color' => 'green'
            ),
            array(
                'title' => '今日註冊',
                'value' => $total_stats['today'],
                'icon' => 'dashicons-calendar-alt',
                'color' => 'orange'
            ),
            array(
                'title' => '本週註冊',
                'value' => $total_stats['week'],
                'icon' => 'dashicons-chart-line',
                'color' => 'purple'
            ),
            array(
                'title' => '需求單位',
                'value' => $stats['demand_unit']['total'] ?? 0,
                'icon' => 'dashicons-building',
                'color' => 'red'
            ),
            array(
                'title' => '綠照師',
                'value' => $stats['green_teacher']['total'] ?? 0,
                'icon' => 'dashicons-admin-customizer',
                'color' => 'green'
            )
        );

        foreach ($cards as $card) {
            ?>
            <div class="tcross-stat-card <?php echo $card['color']; ?>">
                <div class="stat-icon">
                    <span class="dashicons <?php echo $card['icon']; ?>"></span>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($card['value']); ?></h3>
                    <p><?php echo $card['title']; ?></p>
                </div>
            </div>
            <?php
        }
    }

    /**
     * 渲染用戶表格
     */
    private static function render_user_table($user_type) {
        $users = TCrossUserTable::get_users_by_type($user_type, 50);
        $type_name = $user_type === 'demand_unit' ? '需求單位' : '綠照師';

        ?>
        <div class="tcross-user-table-wrapper">
            <!-- 搜尋和篩選 -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <input type="text" id="user-search-<?php echo $user_type; ?>" placeholder="搜尋用戶..." class="user-search-input">
                    <select id="status-filter-<?php echo $user_type; ?>" class="status-filter">
                        <option value="">所有狀態</option>
                        <option value="active">活躍</option>
                        <option value="blocked">停權</option>
                    </select>
                    <button type="button" class="button" onclick="searchUsers('<?php echo $user_type; ?>')">搜尋</button>
                    <button type="button" class="button" onclick="exportUsers('<?php echo $user_type; ?>')">匯出</button>
                </div>
            </div>

            <!-- 用戶表格 -->
            <table class="wp-list-table widefat fixed striped users">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-cb check-column">
                            <input type="checkbox" id="select-all-<?php echo $user_type; ?>">
                        </th>
                        <th scope="col" class="manage-column column-name">顯示名稱</th>
                        <th scope="col" class="manage-column column-email">電子郵件</th>
                        <th scope="col" class="manage-column column-registered">註冊日期</th>
                        <th scope="col" class="manage-column column-status">狀態</th>
                        <th scope="col" class="manage-column column-actions">操作</th>
                    </tr>
                </thead>
                <tbody id="user-table-<?php echo $user_type; ?>">
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="no-users">暫無<?php echo $type_name; ?>用戶</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr id="user-row-<?php echo $user->user_id; ?>">
                                <td class="check-column">
                                    <input type="checkbox" name="users[]" value="<?php echo $user->user_id; ?>">
                                </td>
                                <td class="name column-name">
                                    <?php echo esc_html($user->display_name ?? 'N/A'); ?>
                                </td>
                                <td class="email column-email">
                                    <a href="mailto:<?php echo esc_attr($user->user_email); ?>">
                                        <?php echo esc_html($user->user_email ?? 'N/A'); ?>
                                    </a>
                                </td>
                                <td class="registered column-registered">
                                    <?php echo date('Y-m-d H:i', strtotime($user->registration_date)); ?>
                                </td>
                                <td class="status column-status">
                                    <span class="status-badge status-<?php echo $user->status; ?>">
                                        <?php echo self::get_status_text($user->status); ?>
                                    </span>
                                </td>
                                <td class="actions column-actions">
                                    <select class="status-change" data-user-id="<?php echo $user->user_id; ?>">
                                        <option value="active" <?php selected($user->status, 'active'); ?>>活躍</option>
                                        <option value="blocked" <?php selected($user->status, 'blocked'); ?>>停權</option>
                                    </select>
                                    <button type="button" class="button button-small delete-user-btn"
                                            data-user-id="<?php echo $user->user_id; ?>" 
                                            data-user-name="<?php echo esc_attr($user->display_name ?? $user->user_email); ?>"
                                            style="color: #dc3232; border-color: #dc3232;">刪除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 獲取狀態文字
     */
    private static function get_status_text($status) {
        $status_texts = array(
            'active' => '活躍',
            'blocked' => '停權'
        );
        return $status_texts[$status] ?? $status;
    }

    /**
     * 渲染設定表單
     */
    private static function render_settings_form() {
        $options = get_option('tcross_user_manager_options', array());

        ?>
        <form method="post" action="">
            <?php wp_nonce_field('tcross_admin_action', 'tcross_admin_nonce'); ?>
            <input type="hidden" name="action" value="update_settings">

            <table class="form-table">
                <tr>
                    <th scope="row">註冊後自動審核</th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_approve" value="1" <?php checked(isset($options['auto_approve']) ? $options['auto_approve'] : 0, 1); ?>>
                            新用戶註冊後自動設為活躍狀態
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">發送歡迎郵件</th>
                    <td>
                        <label>
                            <input type="checkbox" name="send_welcome_email" value="1" <?php checked(isset($options['send_welcome_email']) ? $options['send_welcome_email'] : 1, 1); ?>>
                            註冊成功後發送歡迎郵件
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">允許的用戶類型</th>
                    <td>
                        <label>
                            <input type="checkbox" name="allow_demand_unit" value="1" <?php checked(isset($options['allow_demand_unit']) ? $options['allow_demand_unit'] : 1, 1); ?>>
                            需求單位
                        </label><br>
                        <label>
                            <input type="checkbox" name="allow_green_teacher" value="1" <?php checked(isset($options['allow_green_teacher']) ? $options['allow_green_teacher'] : 1, 1); ?>>
                            綠照師
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">每頁顯示用戶數</th>
                    <td>
                        <input type="number" name="users_per_page" value="<?php echo isset($options['users_per_page']) ? $options['users_per_page'] : 20; ?>" min="10" max="100">
                    </td>
                </tr>
                <tr>
                    <th scope="row">註冊確認視窗標題</th>
                    <td>
                        <input type="text" name="registration_notice_title" value="<?php echo isset($options['registration_notice_title']) ? esc_attr($options['registration_notice_title']) : '註冊確認'; ?>" class="regular-text">
                        <p class="description">在註冊確認視窗中顯示的標題</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">註冊須知內容</th>
                    <td>
                        <?php
                        $registration_content = isset($options['registration_notice_content']) ? $options['registration_notice_content'] : self::get_default_registration_content();
                        wp_editor($registration_content, 'registration_notice_content', array(
                            'textarea_name' => 'registration_notice_content',
                            'media_buttons' => false,
                            'textarea_rows' => 30,
                            'editor_height' => 600,
                            'teeny' => true,
                            'quicktags' => true
                        ));
                        ?>
                        <p class="description">用戶點擊註冊按鈕時顯示的內容，支持 HTML 格式</p>
                        <p>
                            <button type="button" class="button button-secondary" id="preview-registration-modal">預覽註冊視窗</button>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button('保存設定'); ?>
        </form>
        <?php
    }

    /**
     * 渲染匯出表單
     */
    private static function render_export_form() {
        ?>
        <div class="tcross-export-section">
            <h4>匯出用戶數據</h4>
            <form id="export-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">用戶類型</th>
                        <td>
                            <select name="export_user_type" id="export-user-type">
                                <option value="all">所有用戶</option>
                                <option value="demand_unit">需求單位</option>
                                <option value="green_teacher">綠照師</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">匯出格式</th>
                        <td>
                            <label>
                                <input type="radio" name="export_format" value="csv" checked>
                                CSV
                            </label><br>
                            <label>
                                <input type="radio" name="export_format" value="json">
                                JSON
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">狀態篩選</th>
                        <td>
                            <select name="export_status">
                                <option value="all">所有狀態</option>
                                <option value="active">活躍</option>
                                <option value="blocked">停權</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">包含表單資料</th>
                        <td>
                            <label>
                                <input type="checkbox" name="include_form_data" value="1">
                                包含用戶填寫的表單詳細資料（學經歷、專長、證照等）
                            </label>
                            <p class="description">勾選此選項將會包含用戶提交的完整表單資料，檔案會較大但資訊更完整。</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="button" class="button button-primary" onclick="performExport()">開始匯出</button>
                </p>
            </form>
        </div>

        <div class="tcross-cleanup-section">
            <h4>數據清理</h4>
            <p>清理超過一年的已刪除用戶記錄</p>
            <button type="button" class="button button-secondary" onclick="cleanupOldData()">清理舊數據</button>
        </div>
        <?php
    }

    /**
     * 處理管理操作
     */
    private static function handle_admin_actions() {
        $action = sanitize_text_field($_POST['action']);

        switch ($action) {
            case 'update_settings':
                self::update_settings();
                break;
            case 'bulk_action':
                self::handle_bulk_action();
                break;
        }
    }

    /**
     * 更新設定
     */
    private static function update_settings() {
        $options = array(
            'auto_approve' => isset($_POST['auto_approve']) ? 1 : 0,
            'send_welcome_email' => isset($_POST['send_welcome_email']) ? 1 : 0,
            'allow_demand_unit' => isset($_POST['allow_demand_unit']) ? 1 : 0,
            'allow_green_teacher' => isset($_POST['allow_green_teacher']) ? 1 : 0,
            'users_per_page' => intval($_POST['users_per_page']),
            'registration_notice_title' => sanitize_text_field($_POST['registration_notice_title']),
            'registration_notice_content' => wp_kses_post($_POST['registration_notice_content'])
        );

        update_option('tcross_user_manager_options', $options);

        echo '<div class="notice notice-success is-dismissible"><p>設定已保存！</p></div>';
    }
    
    /**
     * 獲取預設註冊內容
     */
    private static function get_default_registration_content() {
        return '<h4>請仔細閱讀以下注意事項：</h4>
<ul>
<li>請確保您提供的資料真實有效</li>
<li>註冊後您將收到確認郵件，請檢查您的信箱</li>
<li>如有任何問題，請聯繫客服人員</li>
<li>註冊即表示您同意我們的服務條款和隱私政策</li>
</ul>
<p><strong>確認後將完成註冊程序。</strong></p>';
    }

    /**
     * 處理批量操作
     */
    private static function handle_bulk_action() {
        // 實現批量操作邏輯
    }

    /**
     * 渲染樣式和腳本
     */
    private static function render_admin_styles_and_scripts() {
        ?>
        <style>
        .tcross-dashboard {
            margin: 20px 0;
        }

        .tcross-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .tcross-stat-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .tcross-stat-card.blue { border-left: 4px solid #0073aa; }
        .tcross-stat-card.green { border-left: 4px solid #46b450; }
        .tcross-stat-card.orange { border-left: 4px solid #ffb900; }
        .tcross-stat-card.purple { border-left: 4px solid #826eb4; }
        .tcross-stat-card.red { border-left: 4px solid #dc3232; }

        .stat-icon {
            font-size: 36px;
            margin-right: 15px;
            opacity: 0.7;
        }

        .stat-info h3 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }

        .stat-info p {
            margin: 5px 0 0 0;
            color: #666;
        }

        .tcross-tabs .tab-content {
            display: none;
            background: #fff;
            border: 1px solid #ddd;
            border-top: none;
            padding: 20px;
        }

        .tcross-tabs .tab-content.active {
            display: block;
        }

        .tcross-user-table-wrapper {
            margin-top: 20px;
        }

        .user-search-input {
            width: 250px;
            margin-right: 10px;
        }

        .status-filter {
            margin-right: 10px;
        }

        .status-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-active { background: #46b450; color: white; }
        .status-blocked { background: #dc3232; color: white; }

        .no-users {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .tcross-export-section, .tcross-cleanup-section {
            background: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            margin: 20px 0;
        }
        </style>

        <script>
jQuery(document).ready(function($) {
    // 選項卡切換
    $('.nav-tab').click(function(e) {
        e.preventDefault();
        var target = $(this).attr('href');

        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.tab-content').removeClass('active');
        $(target).addClass('active');
    });

    // 改用事件委託來綁定狀態變更
    $(document).on('change', '.status-change', function() {
        var userId = $(this).data('user-id');
        var newStatus = $(this).val();

        console.log('Status change triggered:', userId, newStatus); // 除錯用

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'tcross_update_user_status',
                user_id: userId,
                status: newStatus,
                nonce: '<?php echo wp_create_nonce("tcross_admin_nonce"); ?>'
            },
            success: function(response) {
                console.log('AJAX response:', response); // 除錯用
                if (response.success) {
                    location.reload();
                } else {
                    alert('更新失敗：' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX error:', xhr, status, error); // 除錯用
                alert('請求失敗：' + error);
            }
        });
    });

    // 刪除用戶功能
    $(document).on('click', '.delete-user-btn', function() {
        var userId = $(this).data('user-id');
        var userName = $(this).data('user-name');
        
        var confirmMessage = '⚠️ 警告：刪除用戶操作\n\n' +
                           '您即將刪除用戶：' + userName + '\n\n' +
                           '此操作將會：\n' +
                           '• 完全刪除 WordPress 用戶帳號\n' +
                           '• 刪除所有相關的 TCross 數據\n' +
                           '• 刪除用戶的所有提交記錄\n' +
                           '• 此操作無法撤銷！\n\n' +
                           '確定要繼續嗎？';
        
        if (confirm(confirmMessage)) {
            var doubleConfirm = prompt('請輸入 "DELETE" 來確認刪除操作：');
            if (doubleConfirm === 'DELETE') {
                deleteUser(userId, userName);
            } else if (doubleConfirm !== null) {
                alert('確認文字不正確，操作已取消。');
            }
        }
    });
});

        // 搜尋用戶
        function searchUsers(userType) {
            var searchTerm = document.getElementById('user-search-' + userType).value;
            var statusFilter = document.getElementById('status-filter-' + userType).value;

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tcross_search_users',
                    search_term: searchTerm,
                    user_type: userType,
                    status: statusFilter,
                    nonce: '<?php echo wp_create_nonce("tcross_admin_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        updateUserTable(userType, response.data);
                    }
                }
            });
        }

        // 更新用戶表格
        function updateUserTable(userType, users) {
            var tbody = document.getElementById('user-table-' + userType);
            var html = '';

            if (users.length === 0) {
                html = '<tr><td colspan="7" class="no-users">沒有找到符合條件的用戶</td></tr>';
            } else {
                users.forEach(function(user) {
                    html += '<tr id="user-row-' + user.user_id + '">';
                    html += '<td class="check-column"><input type="checkbox" name="users[]" value="' + user.user_id + '"></td>';
                    html += '<td class="name column-name">' + (user.display_name || 'N/A') + '</td>';
                    html += '<td class="email column-email"><a href="mailto:' + user.user_email + '">' + (user.user_email || 'N/A') + '</a></td>';
                    html += '<td class="registered column-registered">' + new Date(user.registration_date).toLocaleString() + '</td>';
                    html += '<td class="status column-status"><span class="status-badge status-' + user.status + '">' + getStatusText(user.status) + '</span></td>';
                    html += '<td class="actions column-actions">';
                    html += '<select class="status-change" data-user-id="' + user.user_id + '">';
                    html += '<option value="active"' + (user.status === 'active' ? ' selected' : '') + '>活躍</option>';
                    html += '<option value="blocked"' + (user.status === 'blocked' ? ' selected' : '') + '>停權</option>';
                    html += '</select>';
                    html += '<button type="button" class="button button-small" onclick="viewUserDetails(' + user.user_id + ')">詳情</button>';
                    html += '</td></tr>';
                });
            }

            tbody.innerHTML = html;
        }

        // 獲取狀態文字
        function getStatusText(status) {
            var statusTexts = {
                'active': '活躍',
                'blocked': '停權'
            };
            return statusTexts[status] || status;
        }

        // 匯出用戶
        function exportUsers(userType) {
            window.location.href = ajaxurl + '?action=tcross_export_users&user_type=' + userType + '&format=csv&nonce=<?php echo wp_create_nonce("tcross_admin_nonce"); ?>';
        }

        // 執行匯出
        function performExport() {
            var form = document.getElementById('export-form');
            var formData = new FormData(form);
            formData.append('action', 'tcross_export_users');
            formData.append('nonce', '<?php echo wp_create_nonce("tcross_admin_nonce"); ?>');

            var params = new URLSearchParams();
            for (let [key, value] of formData) {
                params.append(key, value);
            }

            window.location.href = ajaxurl + '?' + params.toString();
        }

        // 清理舊數據
        function cleanupOldData() {
            if (confirm('確定要清理超過一年的已刪除用戶記錄嗎？此操作無法撤銷。')) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tcross_cleanup_old_data',
                        nonce: '<?php echo wp_create_nonce("tcross_admin_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('清理完成！');
                        } else {
                            alert('清理失敗：' + response.data);
                        }
                    }
                });
            }
        }
        
        // 預覽註冊視窗功能
        jQuery(document).ready(function($) {
            // 加載註冊視窗CSS
            if (!$('link[href*="registration-modal.css"]').length) {
                $('head').append('<link rel="stylesheet" type="text/css" href="<?php echo plugin_dir_url(__FILE__); ?>assets/css/registration-modal.css">');
            }
            
            $('#preview-registration-modal').on('click', function() {
                previewRegistrationModal();
            });
        });
        
        function previewRegistrationModal() {
            var title = jQuery('input[name="registration_notice_title"]').val() || '註冊確認';
            var content = '';
            
            // 獲取編輯器內容
            if (typeof tinymce !== 'undefined' && tinymce.get('registration_notice_content')) {
                content = tinymce.get('registration_notice_content').getContent();
            } else {
                content = jQuery('#registration_notice_content').val();
            }
            
            if (!content) {
                content = <?php echo json_encode(self::get_default_registration_content()); ?>;
            }
            
            // 創建預覽模態視窗
            createPreviewModal(title, content);
        }
        
        function createPreviewModal(title, content) {
            // 移除現有的預覽模態視窗
            jQuery('#tcross-preview-modal').remove();
            
            var modalHtml = 
                '<div id="tcross-preview-modal" class="tcross-registration-modal show">' +
                    '<div class="tcross-modal-content">' +
                        '<div class="tcross-modal-header">' +
                            '<h3>' + title + '</h3>' +
                            '<span class="tcross-modal-close" onclick="closePreviewModal()">&times;</span>' +
                        '</div>' +
                        '<div class="tcross-modal-body">' +
                            '<div>' + content + '</div>' +
                        '</div>' +
                        '<div class="tcross-modal-footer">' +
                            '<button type="button" class="tcross-modal-btn tcross-modal-btn-cancel" onclick="closePreviewModal()">取消</button>' +
                            '<button type="button" class="tcross-modal-btn tcross-modal-btn-confirm" onclick="closePreviewModal()">確認註冊（預覽）</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            
            jQuery('body').append(modalHtml);
            jQuery('body').css('overflow', 'hidden');
        }
        
        function closePreviewModal() {
            jQuery('#tcross-preview-modal').removeClass('show').addClass('closing');
            setTimeout(function() {
                jQuery('#tcross-preview-modal').remove();
                jQuery('body').css('overflow', '');
            }, 300);
        }

        // 刪除用戶
        function deleteUser(userId, userName) {
            // 顯示載入狀態
            var button = jQuery('.delete-user-btn[data-user-id="' + userId + '"]');
            var originalText = button.text();
            button.text('刪除中...').prop('disabled', true);
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tcross_delete_user',
                    user_id: userId,
                    nonce: '<?php echo wp_create_nonce("tcross_admin_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('用戶 "' + userName + '" 已成功刪除！');
                        // 從表格中移除該行
                        jQuery('#user-row-' + userId).fadeOut(500, function() {
                            jQuery(this).remove();
                            // 檢查是否還有用戶，如果沒有則顯示空狀態
                            checkEmptyTable();
                        });
                    } else {
                        alert('刪除失敗：' + response.data);
                        button.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    alert('刪除請求失敗：' + error);
                    button.text(originalText).prop('disabled', false);
                }
            });
        }
        
        // 檢查表格是否為空
        function checkEmptyTable() {
            jQuery('table tbody').each(function() {
                var tbody = jQuery(this);
                var visibleRows = tbody.find('tr:visible').length;
                
                if (visibleRows === 0) {
                    var colspan = tbody.closest('table').find('thead th').length;
                    tbody.html('<tr><td colspan="' + colspan + '" class="no-users">暫無用戶</td></tr>');
                }
            });
        }
        </script>
        <?php
    }

    /**
     * 渲染表單提交審核表格
     */
    private static function render_form_submissions_table() {
        $submissions = TCrossUserTable::get_form_submissions('', '', 50, 0);
        $stats = TCrossUserTable::get_form_submission_stats();

        ?>
        <div class="tcross-submissions-header">
            <div class="tcross-filters">
                <select id="submission-type-filter">
                    <option value="">所有類型</option>
                    <option value="green_teacher">綠照師</option>
                    <option value="demand_unit">需求單位</option>
                </select>

                <select id="submission-status-filter">
                    <option value="">所有狀態</option>
                    <option value="pending">待審核</option>
                    <option value="under_review">審核中</option>
                    <option value="approved">已通過</option>
                    <option value="rejected">已拒絕</option>
                    <option value="archived">已下架</option>
                </select>
            </div>

        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>類型</th>
                    <th>申請人</th>
                    <th>電子郵件</th>
                    <th>提交用戶</th>
                    <th>提交時間</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $submission): ?>
                    <?php
                    $form_data = $submission->form_data;
                    $name = $form_data['name']['value'] ?? '未提供';
                    $email = $form_data['email']['value'] ?? '未提供';
                    $type_name = $submission->user_type === 'green_teacher' ? '綠照師' : '需求單位';

                    // 獲取提交用戶資訊
                    $submitted_by_info = '訪客';
                    if ($submission->submitted_by_user_id) {
                        $submitted_user = get_user_by('id', $submission->submitted_by_user_id);
                        if ($submitted_user) {
                            $submitted_by_info = $submitted_user->display_name . '<br><small>(' . $submitted_user->user_email . ')</small>';
                        } else {
                            $submitted_by_info = '用戶已刪除 (ID: ' . $submission->submitted_by_user_id . ')';
                        }
                    }

                    // 使用 application_status 而不是 status
                    $display_status = $submission->application_status ?? $submission->status;
                    if ($submission->status === 'new') {
                        $display_status = 'pending';
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($submission->id); ?></td>
                        <td><?php echo esc_html($type_name); ?></td>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo esc_html($email); ?></td>
                        <td><?php echo wp_kses($submitted_by_info, array('br' => array(), 'small' => array())); ?></td>
                        <td><?php echo esc_html($submission->submitted_at); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo esc_attr($display_status); ?>">
                                <?php echo self::get_status_label($display_status); ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" class="button view-submission-btn" 
                                    data-submission-id="<?php echo esc_attr($submission->id); ?>"
                                    data-user-id="<?php echo esc_attr($submission->submitted_by_user_id ?: 0); ?>">
                                查看詳情
                            </button>
                            
                            <?php if ($display_status === 'pending' || $submission->status === 'new'): ?>
                                <button type="button" class="button button-primary approve-btn" 
                                        data-submission-id="<?php echo esc_attr($submission->id); ?>"
                                        data-user-id="<?php echo esc_attr($submission->submitted_by_user_id ?: 0); ?>">
                                    通過
                                </button>
                                <button type="button" class="button reject-btn" 
                                        data-submission-id="<?php echo esc_attr($submission->id); ?>"
                                        data-user-id="<?php echo esc_attr($submission->submitted_by_user_id ?: 0); ?>">
                                    拒絕
                                </button>
                            <?php elseif ($display_status === 'approved'): ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- 詳情模態框 -->
        <div id="submission-modal" class="tcross-modal" style="display: none;">
            <div class="tcross-modal-content">
                <div class="tcross-modal-header">
                    <h3>申請詳情</h3>
                    <span class="tcross-modal-close">&times;</span>
                </div>
                <div class="tcross-modal-body">
                    <div id="submission-details"></div>
                </div>
            </div>
        </div>
        
        <style>
        .tcross-modal {
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .tcross-modal-content {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 0;
            border: 1px solid #888;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            border-radius: 5px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .tcross-modal-header {
            padding: 15px 20px;
            background-color: #f1f1f1;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .tcross-modal-close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .tcross-modal-close:hover {
            color: black;
        }
        
        .tcross-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
            max-height: calc(90vh - 80px);
        }
        
        .form-data-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }
        
        .form-field {
            padding: 10px;
            background: #f9f9f9;
            border-radius: 3px;
            word-wrap: break-word;
        }
        
        .form-field strong {
            display: block;
            margin-bottom: 5px;
            color: #333;
        }
        
        .tcross-modal-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            position: sticky;
            bottom: 0;
            background: white;
        }
        
        .tcross-modal-buttons {
            margin-top: 15px;
            text-align: right;
        }
        
        .tcross-modal-buttons .button {
            margin-left: 10px;
        }
        
        #admin-notes {
            width: 100%;
            min-height: 80px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            resize: vertical;
        }
        
        .submission-info h4 {
            color: #0073aa;
            border-bottom: 2px solid #0073aa;
            padding-bottom: 5px;
            margin-top: 25px;
            margin-bottom: 15px;
        }
        
        .submission-info h4:first-child {
            margin-top: 0;
        }
        
        .submission-info p {
            margin: 8px 0;
            line-height: 1.5;
        }
        
        /* 響應式設計 */
        @media (max-width: 768px) {
            .tcross-modal-content {
                width: 95%;
                margin: 1% auto;
                max-height: 95vh;
            }
            
            .form-data-grid {
                grid-template-columns: 1fr;
            }
            
            .tcross-modal-buttons {
                text-align: center;
            }
            
            .tcross-modal-buttons .button {
                margin: 5px;
                display: block;
                width: 100%;
            }
        }
        
        .tcross-submission-stats {
            margin: 20px 0;
        }
        
        .tcross-stats-cards {
            display: flex;
            gap: 20px;
        }
        
        .tcross-stat-card {
            background: white;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            min-width: 200px;
        }
        
        .stat-numbers {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
        }
        
        .status-pending { background: #ffb900; color: white; }
        .status-under_review { background: #0073aa; color: white; }
        .status-approved { background: #46b450; color: white; }
        .status-rejected { background: #dc3232; color: white; }
        .status-archived { background: #666; color: white; }
        .status-revision_pending { background: #ff8c00; color: white; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
             $('#submission-type-filter, #submission-status-filter').on('change', function() {
        filterSubmissions();
    });

function filterSubmissions() {
    var typeFilter = $('#submission-type-filter').val();
    var statusFilter = $('#submission-status-filter').val();

    $('tbody tr').each(function() {
        var $row = $(this);
        var rowType = $row.find('td:nth-child(2)').text().trim();
        var $statusBadge = $row.find('.status-badge');

        // 修正狀態提取邏輯
        var rowStatus = '';
        var badgeClass = $statusBadge.attr('class');

        if (badgeClass) {
            var classes = badgeClass.split(' ');
            for (var i = 0; i < classes.length; i++) {
                // 跳過 'status-badge'，只處理實際狀態的 class
                if (classes[i].startsWith('status-') && classes[i] !== 'status-badge') {
                    rowStatus = classes[i].replace('status-', '');
                    break;
                }
            }
        }

        // 轉換顯示文字為值
        var typeValue = '';
        if (rowType === '綠照師') typeValue = 'green_teacher';
        if (rowType === '需求單位') typeValue = 'demand_unit';

        var showRow = true;

        // 檢查類型篩選
        if (typeFilter && typeFilter !== typeValue) {
            showRow = false;
        }

        // 檢查狀態篩選
        if (statusFilter && statusFilter !== rowStatus) {
            showRow = false;
        }

        $row.toggle(showRow);
    });
}

    // 重置篩選按鈕
    $('.tcross-submissions-header .tcross-filters').append(
        '<button type="button" id="reset-filters" class="button" style="margin-left: 10px;">重置篩選</button>'
    );

    $('#reset-filters').on('click', function() {
        $('#submission-type-filter').val('');
        $('#submission-status-filter').val('');
        filterSubmissions();
    });

            // 查看提交詳情
            $('.view-submission-btn').on('click', function() {
                var submissionId = $(this).data('submission-id');
                viewSubmissionDetails(submissionId);
            });
            
            // 快速審核按鈕
            $('.approve-btn').on('click', function() {
                var submissionId = $(this).data('submission-id');
                var userId = $(this).data('user-id');
                if (confirm('確定要通過這個申請嗎？')) {
                    updateSubmissionStatusWithUser(submissionId, 'approved', '', userId);
                }
            });
            
            $('.reject-btn').on('click', function() {
                var submissionId = $(this).data('submission-id');
                var userId = $(this).data('user-id');
                var reason = prompt('請輸入拒絕原因：');
                if (reason !== null) {
                    updateSubmissionStatusWithUser(submissionId, 'rejected', reason, userId);
                }
            });
            
            // 模態框操作
            $('.tcross-modal-close').on('click', function() {
                $('#submission-modal').hide();
            });
            
            // 點擊模態框背景關閉
            $('.tcross-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });
            
            // ESC 鍵關閉模態框
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#submission-modal').is(':visible')) {
                    $('#submission-modal').hide();
                }
            });
            
            $('#approve-submission').on('click', function() {
                var submissionId = $('#submission-modal').data('submission-id');
                var userId = $('#submission-modal').data('user-id');
                var notes = $('#admin-notes').val();
                updateSubmissionStatusWithUser(submissionId, 'approved', notes, userId);
            });
            
            $('#reject-submission').on('click', function() {
                var submissionId = $('#submission-modal').data('submission-id');
                var userId = $('#submission-modal').data('user-id');
                var notes = $('#admin-notes').val();
                updateSubmissionStatusWithUser(submissionId, 'rejected', notes, userId);
            });
            
            $('#review-submission').on('click', function() {
                var submissionId = $('#submission-modal').data('submission-id');
                var userId = $('#submission-modal').data('user-id');
                var notes = $('#admin-notes').val();
                updateSubmissionStatusWithUser(submissionId, 'under_review', notes, userId);
            });
            
            function updateSubmissionStatus(submissionId, status, notes) {
                // 向後兼容的函數，不指定用戶ID
                updateSubmissionStatusWithUser(submissionId, status, notes, 0);
            }
            
            function updateSubmissionStatusWithUser(submissionId, status, notes, userId) {
                console.log('TCross: 更新提交狀態 - ID:', submissionId, '狀態:', status, '用戶ID:', userId);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tcross_update_submission_status',
                        submission_id: submissionId,
                        status: status,
                        admin_notes: notes,
                        user_id: userId || 0, // 新增用戶ID參數
                        nonce: '<?php echo wp_create_nonce('tcross_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('TCross: 狀態更新成功:', response.data);
                            $('#submission-modal').hide();
                            location.reload();
                        } else {
                            alert('操作失敗：' + response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('TCross: AJAX 請求失敗:', error);
                        alert('請求失敗：' + error);
                    }
                });
            }
            
            function viewSubmissionDetails(submissionId) {
                // 獲取用戶ID
                var userId = $('[data-submission-id="' + submissionId + '"]').data('user-id');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tcross_get_submission_details',
                        submission_id: submissionId,
                        nonce: '<?php echo wp_create_nonce('tcross_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            displaySubmissionDetails(response.data);
                            $('#submission-modal').data('submission-id', submissionId).data('user-id', userId).show();
                        } else {
                            alert('無法載入詳情：' + response.data);
                        }
                    }
                });
            }
            
            function displaySubmissionDetails(submission) {
                var html = '<div class="submission-info">';
                html += '<h4>基本資訊</h4>';
                html += '<p><strong>申請類型：</strong>' + (submission.user_type === 'green_teacher' ? '綠照師' : '需求單位') + '</p>';
                html += '<p><strong>提交時間：</strong>' + submission.submitted_at + '</p>';
                html += '<p><strong>當前狀態：</strong>' + getStatusLabel(submission.application_status || submission.status) + '</p>';
                
                // 顯示提交用戶資訊
                html += '<h4>提交用戶資訊</h4>';
                if (submission.submitted_by_user_id) {
                    // 顯示用戶帳號和其他資訊
                    if (submission.submitted_user_login) {
                        html += '<p><strong>用戶帳號：</strong>' + submission.submitted_user_login + '</p>';
                    }
                    if (submission.submitted_user_email) {
                        html += '<p><strong>電子郵件：</strong>' + submission.submitted_user_email + '</p>';
                    }
                    if (submission.submitted_user_display_name) {
                        html += '<p><strong>顯示名稱：</strong>' + submission.submitted_user_display_name + '</p>';
                    }
                    html += '<p><strong>用戶ID：</strong>' + submission.submitted_by_user_id + '</p>';
                } else {
                    html += '<p><strong>提交用戶：</strong>訪客（未登入）</p>';
                }
                
                if (submission.admin_notes) {
                    html += '<h4>管理員備註</h4>';
                    html += '<p>' + submission.admin_notes + '</p>';
                }
                
                html += '<h4>表單數據</h4>';
                
                if (submission.form_data && Object.keys(submission.form_data).length > 0) {
                    html += '<div class="form-data-grid">';
                    
                    var fieldCount = 0;
                    for (var field in submission.form_data) {
                        if (field !== '_meta' && submission.form_data[field] && submission.form_data[field].label) {
                            html += '<div class="form-field">';
                            html += '<strong>' + submission.form_data[field].label + '</strong>';
                            html += '<div>' + (submission.form_data[field].value || '未填寫') + '</div>';
                            html += '</div>';
                            fieldCount++;
                        }
                    }
                    
                    html += '</div>';
                    
                    if (fieldCount === 0) {
                        html += '<p style="color: #666; font-style: italic;">沒有可顯示的表單數據</p>';
                    }
                } else {
                    html += '<p style="color: #666; font-style: italic;">沒有表單數據</p>';
                }
                
                html += '</div>';
                
                $('#submission-details').html(html);
                $('#admin-notes').val(submission.admin_notes || '');
                
                // 滾動到頂部
                $('.tcross-modal-body').scrollTop(0);
            }
            
            function getStatusLabel(status) {
                var labels = {
                    'pending': '待審核',
                    'under_review': '審核中',
                    'approved': '已通過',
                    'rejected': '已拒絕',
                    'archived': '已下架'
                };
                return labels[status] || status;
            }
        });
        </script>
        <?php
    }
    
    /**
     * 渲染提交統計
     */
    private static function render_submission_stats($stats) {
        ?>
        <div class="tcross-stats-cards">
            <?php foreach (['green_teacher' => '綠照師', 'demand_unit' => '需求單位'] as $type => $label): ?>
                <?php if (isset($stats[$type])): ?>
                    <div class="tcross-stat-card">
                        <h4><?php echo esc_html($label); ?></h4>
                        <div class="stat-numbers">
                            <?php foreach ($stats[$type] as $status => $data): ?>
                                <div class="stat-item">
                                    <span class="stat-label"><?php echo self::get_status_label($status); ?></span>
                                    <span class="stat-value"><?php echo esc_html($data['total']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    /**
     * 獲取狀態標籤
     */
    private static function get_status_label($status) {
        $labels = array(
            'pending' => '待審核',
            'under_review' => '審核中', 
            'approved' => '已通過',
            'rejected' => '已拒絕',
            'archived' => '已下架',
            'revision_pending' => '修正中'
        );
        
        return $labels[$status] ?? $status;
    }
}
?>