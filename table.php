<?php
/**
 * TCross User Manager - Database Table Management
 * 管理用戶類型相關的資料庫操作
 */

if (!defined('ABSPATH')) {
    exit;
}

class TCrossUserTable {

    const TABLE_NAME = 'tcross_user_types';
    const FORM_DATA_TABLE = 'tcross_form_submissions';

    /**
     * 創建用戶類型表格
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            user_type varchar(50) NOT NULL,
            registration_date datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'active',
            additional_data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY user_type (user_type),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // 創建索引以提高查詢效能
        $wpdb->query("CREATE INDEX idx_user_type_status ON $table_name (user_type, status)");

        // 創建表單提交數據表格
        self::create_form_data_table();
    }

    /**
     * 創建審核狀態表格（關聯 Elementor 提交）
     */
    public static function create_form_data_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::FORM_DATA_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            elementor_submission_id bigint(20) NOT NULL,
            user_type varchar(50) NOT NULL,
            submission_status varchar(20) DEFAULT 'pending',
            submitted_by_user_id bigint(20) NULL,
            submission_type varchar(20) DEFAULT 'initial',
            is_current_active tinyint(1) DEFAULT 1,
            replaces_submission_id bigint(20) NULL,
            portfolio_id bigint(20) NULL,
            portfolio_status varchar(20) DEFAULT 'none',
            admin_notes text,
            reviewed_at datetime NULL,
            reviewed_by bigint(20) NULL,
            archived_at datetime NULL,
            archived_reason varchar(50) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY elementor_submission_id (elementor_submission_id),
            KEY user_type (user_type),
            KEY submission_status (submission_status),
            KEY submitted_by_user_id (submitted_by_user_id),
            KEY submission_type (submission_type),
            KEY is_current_active (is_current_active),
            KEY portfolio_status (portfolio_status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * 插入用戶類型記錄
     */
    public static function insert_user_type($user_id, $user_type, $additional_data = array()) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'user_type' => $user_type,
                'additional_data' => maybe_serialize($additional_data),
                'registration_date' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s')
        );

        if ($result === false) {
            error_log('TCross User Manager: Failed to insert user type for user ' . $user_id);
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * 更新用戶類型
     */
    public static function update_user_type($user_id, $user_type, $additional_data = array()) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->update(
            $table_name,
            array(
                'user_type' => $user_type,
                'additional_data' => maybe_serialize($additional_data),
                'updated_at' => current_time('mysql')
            ),
            array('user_id' => $user_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * 獲取用戶類型
     */
    public static function get_user_type($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE user_id = %d AND status = 'active'",
                $user_id
            )
        );

        if ($result) {
            $result->additional_data = maybe_unserialize($result->additional_data);
        }

        return $result;
    }

    /**
     * 獲取特定類型的所有用戶
     */
    public static function get_users_by_type($user_type, $limit = 50, $offset = 0, $status = 'active') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // 建立查詢條件
        $where_clauses = array();
        $params = array();

        // 用戶類型篩選
        if ($user_type !== 'all' && !empty($user_type)) {
            $where_clauses[] = "ut.user_type = %s";
            $params[] = $user_type;
        }

        // 狀態篩選
        if ($status !== 'all' && !empty($status)) {
            $where_clauses[] = "ut.status = %s";
            $params[] = $status;
        }

        // 組合 WHERE 條件
        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // 添加 LIMIT 和 OFFSET 參數
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT ut.*, u.display_name, u.user_email, u.user_login, u.user_registered
                FROM $table_name ut
                LEFT JOIN {$wpdb->users} u ON ut.user_id = u.ID
                $where_sql
                ORDER BY ut.registration_date DESC
                LIMIT %d OFFSET %d";

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        foreach ($results as $result) {
            $result->additional_data = maybe_unserialize($result->additional_data);
        }

        return $results;
    }

    /**
     * 獲取用戶類型統計
     */
    public static function get_user_type_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results(
            "SELECT 
                user_type,
                COUNT(*) as total_count,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
                COUNT(CASE WHEN DATE(registration_date) = CURDATE() THEN 1 END) as today_count,
                COUNT(CASE WHEN registration_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as week_count,
                COUNT(CASE WHEN registration_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as month_count
             FROM $table_name 
             GROUP BY user_type"
        );

        $stats = array();
        foreach ($results as $result) {
            $stats[$result->user_type] = array(
                'total' => $result->total_count,
                'active' => $result->active_count,
                'today' => $result->today_count,
                'week' => $result->week_count,
                'month' => $result->month_count
            );
        }

        return $stats;
    }

    /**
     * 更新用戶狀態
     */
    public static function update_user_status($user_id, $status) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->update(
            $table_name,
            array('status' => $status, 'updated_at' => current_time('mysql')),
            array('user_id' => $user_id),
            array('%s', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * 刪除用戶類型記錄（軟刪除）
     */
    public static function delete_user_type($user_id) {
        return self::update_user_status($user_id, 'deleted');
    }

    /**
     * 搜尋用戶
     */
public static function search_users($search_term, $user_type = '', $limit = 50, $status = '') {
    global $wpdb;

    $table_name = $wpdb->prefix . self::TABLE_NAME;

    $where_clauses = array();
    $params = array();

    // 狀態篩選邏輯
    if (!empty($status)) {
        $where_clauses[] = "ut.status = %s";
        $params[] = $status;
    } else {
        // 如果沒有指定狀態，預設只顯示活躍用戶（保持原有行為）
        $where_clauses[] = "ut.status = 'active'";
    }

    if (!empty($user_type)) {
        $where_clauses[] = "ut.user_type = %s";
        $params[] = $user_type;
    }

    if (!empty($search_term)) {
        $where_clauses[] = "(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)";
        $search_param = '%' . $wpdb->esc_like($search_term) . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    $where_sql = implode(' AND ', $where_clauses);

    $sql = "SELECT ut.*, u.display_name, u.user_email, u.user_login, u.user_registered 
            FROM $table_name ut 
            LEFT JOIN {$wpdb->users} u ON ut.user_id = u.ID 
            WHERE $where_sql 
            ORDER BY ut.registration_date DESC 
            LIMIT %d";

    $params[] = $limit;

    $results = $wpdb->get_results($wpdb->prepare($sql, $params));

    foreach ($results as $result) {
        $result->additional_data = maybe_unserialize($result->additional_data);
    }

    return $results;
}

    /**
     * 檢查表格是否存在
     */
    public static function table_exists() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * 清理過期數據（可選用）
     */
    public static function cleanup_old_data($days = 365) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name 
                 WHERE status = 'deleted' 
                 AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        return $result;
    }

    /**
     * 插入審核記錄（關聯 Elementor 提交 ID）
     */
    public static function insert_form_submission($elementor_submission_id, $user_type) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::FORM_DATA_TABLE;

        $result = $wpdb->insert(
            $table_name,
            array(
                'elementor_submission_id' => $elementor_submission_id,
                'user_type' => $user_type,
                'status' => 'pending'
            ),
            array('%d', '%s', '%s')
        );

        if ($result === false) {
            error_log('TCross: Failed to insert form submission - ' . $wpdb->last_error);
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * 獲取表單提交列表（結合 Elementor 和 TCross 數據）
     */
    public static function get_form_submissions($user_type = '', $status = '', $limit = 50, $offset = 0) {
        global $wpdb;

        $elementor_table = $wpdb->prefix . 'e_submissions';
        $tcross_table = $wpdb->prefix . self::FORM_DATA_TABLE;

        $where_clauses = array('e.post_id IN (1254, 1325)'); // 只顯示我們的表單
        $params = array();

        // 根據 post_id 篩選用戶類型
        if (!empty($user_type)) {
            if ($user_type === 'green_teacher') {
                $where_clauses[] = "e.post_id = 1254";
            } elseif ($user_type === 'demand_unit') {
                $where_clauses[] = "e.post_id = 1325";
            }
        }

        // 狀態篩選 - 優先使用 TCross 狀態，回退到 Elementor 狀態
        if (!empty($status)) {
            if ($status === 'pending') {
                $where_clauses[] = "(COALESCE(t.submission_status, 'pending') = 'pending' OR e.status = 'new')";
            } elseif ($status === 'approved') {
                $where_clauses[] = "(t.submission_status = 'approved' OR e.status = 'approved')";
            } elseif ($status === 'rejected') {
                $where_clauses[] = "(t.submission_status = 'rejected' OR e.status = 'rejected')";
            } elseif ($status === 'archived') {
                $where_clauses[] = "t.submission_status = 'archived'";
            }
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "SELECT 
                    e.id,
                    e.post_id,
                    e.form_name,
                    e.user_id as submitted_by_user_id,
                    e.created_at as submitted_at,
                    e.referer_title,
                    e.status,
                    t.id as tcross_id,
                    t.submission_status,
                    t.submission_type,
                    t.is_current_active,
                    t.portfolio_id,
                    t.portfolio_status,
                    t.admin_notes,
                    CASE 
                        WHEN e.post_id = 1254 THEN 'green_teacher'
                        WHEN e.post_id = 1325 THEN 'demand_unit'
                        ELSE 'unknown'
                    END as user_type
                FROM $elementor_table e
                LEFT JOIN $tcross_table t ON e.id = t.elementor_submission_id
                WHERE $where_sql 
                ORDER BY e.created_at DESC 
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        // 為每個結果獲取表單數據和用戶申請狀態
        foreach ($results as $result) {
            $result->form_data = self::get_elementor_submission_data($result->id, $result->user_type, false);

            // 優先使用 TCross 狀態，回退到用戶 meta 或 Elementor 狀態
            if ($result->submission_status) {
                $result->application_status = $result->submission_status;
            } elseif ($result->submitted_by_user_id) {
                $user_status = get_user_meta($result->submitted_by_user_id, 'tcross_application_status', true);
                $result->application_status = $user_status ?: 'pending';
            } else {
                $result->application_status = 'guest';
            }
        }

        return $results;
    }

    /**
     * 獲取 Elementor 提交的表單數據
     */
    public static function get_elementor_submission_data($submission_id, $user_type = '', $filter_empty = false) {
        global $wpdb;

        $values_table = $wpdb->prefix . 'e_submissions_values';
        $submissions_table = $wpdb->prefix . 'e_submissions';

        // 如果沒有提供 user_type，從 submission 表中獲取
        if (empty($user_type)) {
            $submission = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id FROM $submissions_table WHERE id = %d",
                $submission_id
            ));
            
            if ($submission) {
                if ($submission->post_id == 1254) {
                    $user_type = 'green_teacher';
                } elseif ($submission->post_id == 1325) {
                    $user_type = 'demand_unit';
                }
            }
        }

        // 獲取有效欄位清單
        $valid_fields = self::get_valid_fields($user_type);

        $values = $wpdb->get_results($wpdb->prepare(
            "SELECT `key`, `value` FROM $values_table WHERE submission_id = %d",
            $submission_id
        ));

        $form_data = array();
        foreach ($values as $value) {
            // 檢查是否為有效欄位（白名單過濾）
            if (!in_array($value->key, $valid_fields)) {
                continue;
            }

            // 過濾空值（如果啟用過濾）
            // 注意：对於新增的語言欄位，給予更寬鬆的過濾條件
            if ($filter_empty) {
                $trimmed_value = trim($value->value);
                if ($trimmed_value === '' || $trimmed_value === null) {
                    continue;
                }
                // 特別處理：如果值為 '0' 或 'false'，不應該被過濾
                if ($trimmed_value === '0' || $trimmed_value === 'false') {
                    // 這些值是有意義的，不過濾
                }
            }

            // 獲取標籤
            $label = self::get_field_label($value->key, $user_type);
            
            // 檢查欄位是否在標籤定義中存在（如果標籤等於原始 key，表示該欄位未定義）
            if ($label === $value->key) {
                continue;
            }

            $form_data[$value->key] = array(
                'value' => $value->value,
                'label' => $label
            );
        }

        return $form_data;
    }

    /**
     * 獲取清理後的表單數據（便利函數）
     */
    public static function get_cleaned_form_data($submission_id, $user_type = '') {
        return self::get_elementor_submission_data($submission_id, $user_type, true);
    }

    /**
     * 獲取完整表單數據（包含空值，用於調試）
     */
    public static function get_full_form_data($submission_id, $user_type = '') {
        return self::get_elementor_submission_data($submission_id, $user_type, false);
    }

    /**
     * 獲取有效欄位清單
     */
    private static function get_valid_fields($user_type) {
        $green_teacher_fields = array(
            'name', 'email', 'field_d55c6b4', 'field_781bfc9', 'field_bb29f05',
            'field_dc61789', 'field_ae2b1cc', 'field_84fd647', 'field_55a66b7',
            'field_9958b1c', 'field_f80259e', 'field_b3308c1', 'field_7502fc4',
            'field_d4920bd', 'field_3749a9b', 'field_410e99d', 'field_712b0a0',
            'field_3877e1a', 'field_4c044af', 'field_7eb67a1', 'field_1a8e4b7',
            'field_97b2536', 'field_3183410'
        );
        
        $demand_unit_fields = array(
            'name', 'email', 'field_8ca1f60', 'field_e6c4bc3', 'field_3183411',
            'field_ae2b1cc', 'field_410e99d', 'field_bb29f05', 'field_9958b1c',
            'field_0fdcf08', 'field_f80259e', 'field_b3308c1', 'field_7502fc4',
            'field_a0dea9a', 'field_4a3ee18', 'field_7293927', 'field_d4920bd',
            'field_6435ede', 'field_3749a9c', 'field_bb4ebd2', 'field_37ccf39',
            'field_d411003', 'field_164d567', 'field_05e2d38', 'field_dc61789',
            'field_97b2536', 'field_3183410'
        );
        
        if ($user_type === 'green_teacher') {
            return $green_teacher_fields;
        } elseif ($user_type === 'demand_unit') {
            return $demand_unit_fields;
        }
        
        return array_merge($green_teacher_fields, $demand_unit_fields);
    }

    /**
     * 獲取欄位標籤
     */
    public static function get_field_label($field_key, $user_type = '') {
        // 綠照師表單標籤
        $green_teacher_labels = array(
            'name' => '姓名',
            'email' => '電子郵件',
            'field_d55c6b4' => '學經歷',
            'field_781bfc9' => '性別',
            'field_bb29f05' => '電話',
            'field_dc61789' => '照片連結',
            'field_ae2b1cc' => 'LINE ID',
            'field_84fd647' => '證照',
            'field_55a66b7' => '其他證照說明',
            'field_9958b1c' => '綠色活動專長',
            'field_f80259e' => '其他說明',
            'field_b3308c1' => '可帶領族群',
            'field_7502fc4' => '其他說明',
            'field_d4920bd' => '進修訓練',
            'field_3749a9b' => '詳細說明',
            'field_410e99d' => '可服務區域',
            'field_712b0a0' => '其他地區',
            'field_3877e1a' => '合作方式',
            'field_4c044af' => '近年服務單位狀況',
            'field_7eb67a1' => '影片連結',
            'field_1a8e4b7' => '課程連結',
            'field_97b2536' => '語言',
            'field_3183410' => '其他語言'
        );

        // 需求單位表單標籤
        $demand_unit_labels = array(
            'name' => '單位名稱',
            'email' => '電子郵件',
            'field_8ca1f60' => '聯絡人姓名',
            'field_e6c4bc3' => '職稱',
            'field_3183411' => '聯絡電話',
            'field_ae2b1cc' => 'LINE ID',
            'field_dc61789' => '照片連結',
            'field_410e99d' => '可服務區域',
            'field_bb29f05' => '地址',
            'field_9958b1c' => '預定上課時間',
            'field_0fdcf08' => '預定上課時間段',
            'field_f80259e' => '時間詳細說明',
            'field_b3308c1' => '對象類型',
            'field_7502fc4' => '對象詳細說明',
            'field_a0dea9a' => '參加人數',
            'field_4a3ee18' => '對象說明',
            'field_7293927' => '預期目標',
            'field_d4920bd' => '課程類型',
            'field_6435ede' => '可提供配合資源',
            'field_3749a9c' => '可支應之講師費範圍',
            'field_bb4ebd2' => '材料費、交通補助款',
            'field_37ccf39' => '請款方式',
            'field_d411003' => '是否已合作過綠照師',
            'field_164d567' => '是否開放媒合複數師資選擇',
            'field_05e2d38' => '是否願意接受平台推薦之師資',
            'field_97b2536' => '語言',
            'field_3183410' => '其他語言'
        );

        // 根據用戶類型選擇對應的標籤陣列
        if ($user_type === 'green_teacher') {
            $labels = $green_teacher_labels;
        } elseif ($user_type === 'demand_unit') {
            $labels = $demand_unit_labels;
        } else {
            // 如果沒有指定類型，合併兩個陣列（需求單位優先，避免重複時綠照師覆蓋）
            $labels = array_merge($green_teacher_labels, $demand_unit_labels);
        }

        return $labels[$field_key] ?? $field_key;
    }

    /**
     * 直接從 Elementor 表獲取單個表單提交
     */
    public static function get_form_submission($id) {
        global $wpdb;

        $elementor_table = $wpdb->prefix . 'e_submissions';

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    e.id,
                    e.post_id,
                    e.form_name,
                    e.user_id as submitted_by_user_id,
                    e.created_at as submitted_at,
                    e.referer_title,
                    e.status,
                    CASE 
                        WHEN e.post_id = 1254 THEN 'green_teacher'
                        WHEN e.post_id = 1325 THEN 'demand_unit'
                        ELSE 'unknown'
                    END as user_type
                FROM $elementor_table e
                WHERE e.id = %d AND e.post_id IN (1254, 1325)",
                $id
            )
        );

        if ($result) {
            $result->form_data = self::get_elementor_submission_data($result->id, $result->user_type, false);

            // 獲取用戶申請狀態和用戶詳細資訊
            if ($result->submitted_by_user_id) {
                $result->application_status = get_user_meta($result->submitted_by_user_id, 'tcross_application_status', true) ?: 'pending';
                $result->admin_notes = get_user_meta($result->submitted_by_user_id, 'tcross_admin_notes', true) ?: '';

                // 獲取用戶詳細資訊
                $user = get_user_by('id', $result->submitted_by_user_id);
                if ($user) {
                    $result->submitted_user_login = $user->user_login;
                    $result->submitted_user_email = $user->user_email;
                    $result->submitted_user_display_name = $user->display_name;
                } else {
                    $result->submitted_user_login = '用戶已刪除';
                    $result->submitted_user_email = '';
                    $result->submitted_user_display_name = '';
                }
            } else {
                $result->application_status = 'guest';
                $result->admin_notes = '';
                $result->submitted_user_login = '';
                $result->submitted_user_email = '';
                $result->submitted_user_display_name = '';
            }
        }

        return $result;
    }

    /**
     * 更新表單提交狀態（直接更新 Elementor 表和用戶 meta）
     */
    public static function update_form_submission_status($id, $status, $admin_notes = '', $reviewed_by = null) {
        global $wpdb;

        $elementor_table = $wpdb->prefix . 'e_submissions';

        // 首先獲取提交信息
        $submission = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $elementor_table WHERE id = %d AND post_id IN (1254, 1325)",
            $id
        ));

        if (!$submission) {
            return false;
        }

        // 更新 Elementor 表的狀態
        $elementor_status = $status;
        if ($status === 'pending') {
            $elementor_status = 'new';
        }

        $result = $wpdb->update(
            $elementor_table,
            array('status' => $elementor_status),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        // 如果有用戶ID，更新用戶的申請狀態
        if ($submission->user_id) {
            update_user_meta($submission->user_id, 'tcross_application_status', $status);

            if (!empty($admin_notes)) {
                update_user_meta($submission->user_id, 'tcross_admin_notes', $admin_notes);
            }

            if ($status === 'approved' || $status === 'rejected') {
                update_user_meta($submission->user_id, 'tcross_reviewed_at', current_time('mysql'));
                if ($reviewed_by) {
                    update_user_meta($submission->user_id, 'tcross_reviewed_by', $reviewed_by);
                }
            }
        }

        return $result !== false;
    }

    /**
     * 直接從 Elementor 表獲取表單提交統計
     */
    public static function get_form_submission_stats() {
        global $wpdb;

        $elementor_table = $wpdb->prefix . 'e_submissions';

        $results = $wpdb->get_results(
            "SELECT 
                CASE 
                    WHEN e.post_id = 1254 THEN 'green_teacher'
                    WHEN e.post_id = 1325 THEN 'demand_unit'
                    ELSE 'unknown'
                END as user_type,
                CASE 
                    WHEN e.status = 'new' THEN 'pending'
                    WHEN e.status = 'approved' THEN 'approved'
                    WHEN e.status = 'rejected' THEN 'rejected'
                    ELSE e.status
                END as status,
                COUNT(*) as count,
                COUNT(CASE WHEN DATE(e.created_at) = CURDATE() THEN 1 END) as today_count,
                COUNT(CASE WHEN e.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as week_count,
                COUNT(CASE WHEN e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as month_count
             FROM $elementor_table e
             WHERE e.post_id IN (1254, 1325)
             GROUP BY user_type, status"
        );

        $stats = array();
        foreach ($results as $result) {
            if (!isset($stats[$result->user_type])) {
                $stats[$result->user_type] = array();
            }
            $stats[$result->user_type][$result->status] = array(
                'total' => $result->count,
                'today' => $result->today_count,
                'week' => $result->week_count,
                'month' => $result->month_count
            );
        }

        return $stats;
    }

    /**
     * 檢查並執行升級（在插件初始化時調用）
     */
    public static function check_and_upgrade() {
        $current_version = get_option('tcross_db_version', '1.0');
        $target_version = '2.0'; // 新版本號 - 方案一重構

        if (version_compare($current_version, $target_version, '<')) {
            error_log('TCross: Starting database upgrade from ' . $current_version . ' to ' . $target_version);

            self::upgrade_form_submissions_table();

            // 更新版本號
            update_option('tcross_db_version', $target_version);

            error_log('TCross: Database upgrade completed to version ' . $target_version);
        }
    }

    /**
     * 升級表單提交表格結構 - 方案一重構
     */
    public static function upgrade_form_submissions_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::FORM_DATA_TABLE;

        // 檢查表格是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            // 表格不存在，創建新表格
            self::create_form_data_table();
            return;
        }

        // 檢查字段是否已存在
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $existing_columns = array();

        foreach ($columns as $column) {
            $existing_columns[] = $column->Field;
        }

        error_log('TCross: Existing columns: ' . implode(', ', $existing_columns));

        // 重命名舊欄位
        if (in_array('status', $existing_columns) && !in_array('submission_status', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name CHANGE COLUMN status submission_status VARCHAR(20) DEFAULT 'pending'");
            error_log('TCross: Renamed status to submission_status - result: ' . ($result !== false ? 'success' : 'failed'));
        }

        if (in_array('is_active', $existing_columns) && !in_array('is_current_active', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name CHANGE COLUMN is_active is_current_active TINYINT(1) DEFAULT 1");
            error_log('TCross: Renamed is_active to is_current_active - result: ' . ($result !== false ? 'success' : 'failed'));
        }

        // 添加新字段
        if (!in_array('submission_type', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN submission_type VARCHAR(20) DEFAULT 'initial'");
            error_log('TCross: Added submission_type column - result: ' . ($result !== false ? 'success' : 'failed'));
        }

        if (!in_array('is_current_active', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN is_current_active TINYINT(1) DEFAULT 1");
            error_log('TCross: Added is_current_active column - result: ' . ($result !== false ? 'success' : 'failed'));
        }

        if (!in_array('replaces_submission_id', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN replaces_submission_id BIGINT(20) NULL");
            error_log('TCross: Added replaces_submission_id column - result: ' . ($result !== false ? 'success' : 'failed'));
        }

        if (!in_array('portfolio_id', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN portfolio_id BIGINT(20) NULL");
            error_log('TCross: Added portfolio_id column - result: ' . ($result !== false ? 'success' : 'failed'));
        }

        if (!in_array('portfolio_status', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN portfolio_status VARCHAR(20) DEFAULT 'none'");
            error_log('TCross: Added portfolio_status column - result: ' . ($result !== false ? 'success' : 'failed'));
        }
        
        // 添加下架相關欄位
        if (!in_array('archived_at', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN archived_at DATETIME NULL");
            error_log('TCross: Added archived_at column - result: ' . ($result !== false ? 'success' : 'failed'));
        }
        
        if (!in_array('archived_reason', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN archived_reason VARCHAR(50) NULL");
            error_log('TCross: Added archived_reason column - result: ' . ($result !== false ? 'success' : 'failed'));
        }

        // 添加索引
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
        $existing_indexes = array();

        foreach ($indexes as $index) {
            $existing_indexes[] = $index->Key_name;
        }

        if (!in_array('idx_submission_type', $existing_indexes)) {
            $wpdb->query("CREATE INDEX idx_submission_type ON $table_name (submission_type)");
            error_log('TCross: Added idx_submission_type index');
        }

        if (!in_array('idx_is_current_active', $existing_indexes)) {
            $wpdb->query("CREATE INDEX idx_is_current_active ON $table_name (is_current_active)");
            error_log('TCross: Added idx_is_current_active index');
        }

        if (!in_array('idx_portfolio_status', $existing_indexes)) {
            $wpdb->query("CREATE INDEX idx_portfolio_status ON $table_name (portfolio_status)");
            error_log('TCross: Added idx_portfolio_status index');
        }

        // 遷移現有數據
        self::migrate_to_new_structure();

        error_log('TCross: Form submissions table upgrade completed');
    }

    /**
     * 遷移現有數據到新結構
     */
    private static function migrate_to_new_structure() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::FORM_DATA_TABLE;

        // 檢查是否有需要遷移的數據
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE submission_type IS NULL OR submission_type = ''");

        if ($count > 0) {
            // 將現有記錄設為 initial 類型
            $wpdb->query("UPDATE $table_name SET submission_type = 'initial' WHERE submission_type IS NULL OR submission_type = ''");
            error_log("TCross: Migrated $count existing records to initial submission type");
        }

        // 確保 is_current_active 字段有正確的默認值
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_current_active IS NULL");

        if ($count > 0) {
            $wpdb->query("UPDATE $table_name SET is_current_active = 1 WHERE is_current_active IS NULL");
            error_log("TCross: Set is_current_active = 1 for $count existing records");
        }

        // 確保 portfolio_status 字段有正確的默認值
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE portfolio_status IS NULL OR portfolio_status = ''");

        if ($count > 0) {
            $wpdb->query("UPDATE $table_name SET portfolio_status = 'none' WHERE portfolio_status IS NULL OR portfolio_status = ''");
            error_log("TCross: Set portfolio_status = 'none' for $count existing records");
        }
    }
}
?>