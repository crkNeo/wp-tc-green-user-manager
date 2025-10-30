<?php
/**
 * TCross User Manager - API Endpoints (重構版本)
 * 處理前端與後端的互動邏輯
 *
 * 重構內容：
 * 1. 統一權限驗證方法
 * 2. 統一錯誤處理
 * 3. 消除重複的狀態檢查代碼
 * 4. 統一表單提交處理流程
 * 5. 分離業務邏輯到專門的 service 類
 */

if (!defined('ABSPATH')) {
    exit;
}

class TCrossUserAPI {

    public function __construct() {
        error_log('TCross: TCrossUserAPI initialized');
        $this->init_hooks();
    }

    private function init_hooks() {
        // AJAX 處理
        add_action('wp_ajax_tcross_get_user_stats', array($this, 'get_user_stats'));
        add_action('wp_ajax_tcross_get_users_by_type', array($this, 'get_users_by_type'));
        add_action('wp_ajax_tcross_update_user_status', array($this, 'update_user_status'));
        add_action('wp_ajax_tcross_search_users', array($this, 'search_users'));
        add_action('wp_ajax_tcross_export_users', array($this, 'export_users'));

        // REST API 端點
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // 自定義註冊端點
        add_action('wp_ajax_tcross_custom_register', array($this, 'handle_custom_register'));
        add_action('wp_ajax_nopriv_tcross_custom_register', array($this, 'handle_custom_register'));

        // Elementor 表單處理
//        add_action('elementor_pro/forms/new_record', array($this, 'handle_elementor_form_submission'), 10, 2);
        add_action('elementor_pro/forms/record/sent', array($this, 'handle_elementor_form_sent'), 10, 2);
        // 延遲處理 hook
        add_action('tcross_process_delayed_submission', array($this, 'handle_delayed_submission'), 10, 5);

        // 表單審核相關
        add_action('wp_ajax_tcross_get_form_submissions', array($this, 'get_form_submissions'));
        add_action('wp_ajax_tcross_update_submission_status', array($this, 'update_submission_status'));
        add_action('wp_ajax_tcross_get_submission_details', array($this, 'get_submission_details'));

        // 用戶提交狀態
        add_action('wp_ajax_tcross_get_user_submission_status', array($this, 'get_user_submission_status'));
        add_action('wp_ajax_nopriv_tcross_get_user_submission_status', array($this, 'get_user_submission_status'));

        // 手動添加tcross_form_submissions
        add_action('wp_ajax_tcross_manual_process_submission', array($this, 'manual_process_submission'));

        add_action('wp_ajax_tcross_sync_latest_submission', array($this, 'sync_latest_submission'));
        add_action('wp_ajax_nopriv_tcross_sync_latest_submission', array($this, 'sync_latest_submission'));

        add_action('wp_ajax_tcross_get_latest_submission_id', array($this, 'get_latest_submission_id'));
        add_action('wp_ajax_nopriv_tcross_get_latest_submission_id', array($this, 'get_latest_submission_id'));

        add_action('wp_ajax_tcross_hide_user_portfolio', array($this, 'hide_user_portfolio'));
        
        // 更新用戶類型的 AJAX 端點
        add_action('wp_ajax_tcross_update_user_type', array($this, 'update_user_type_ajax'));
        add_action('wp_ajax_nopriv_tcross_update_user_type', array($this, 'update_user_type_ajax'));
        
        // 刪除用戶的 AJAX 端點
        add_action('wp_ajax_tcross_delete_user', array($this, 'delete_user_ajax'));
        
        // 獲取用戶提交歷史
        add_action('wp_ajax_tcross_get_user_submission_history', array($this, 'get_user_submission_history'));
        add_action('wp_ajax_nopriv_tcross_get_user_submission_history', array($this, 'get_user_submission_history'));

    }

    public function hide_user_portfolio() {
    if (!wp_verify_nonce($_REQUEST['nonce'], 'tcross_user_nonce')) {
        wp_send_json_error('安全驗證失敗');
        return;
    }

    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        wp_send_json_error('用戶未登入');
        return;
    }

    global $wpdb;
    $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
    $elementor_table = $wpdb->prefix . 'e_submissions';

    // 開始事務處理
    $wpdb->query('START TRANSACTION');

    try {
        // 1. 獲取用戶的 Portfolio ID
        $portfolio_id = get_user_meta($current_user_id, 'tcross_portfolio_id', true);

        if ($portfolio_id) {
            // 將 Portfolio 設為草稿狀態（隱藏）
            $result = wp_update_post(array(
                'ID' => $portfolio_id,
                'post_status' => 'draft'
            ));

            if (is_wp_error($result)) {
                throw new Exception('隱藏 Portfolio 失敗: ' . $result->get_error_message());
            }

            error_log("TCross: Portfolio {$portfolio_id} hidden for user {$current_user_id}");
        }

        // 2. 同時下架所有舊的表單審核記錄
        $active_submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, e.id as elementor_id FROM $tcross_table t
             LEFT JOIN $elementor_table e ON t.elementor_submission_id = e.id
             WHERE t.submitted_by_user_id = %d 
             AND t.user_type = 'green_teacher'
             AND t.submission_status IN ('approved', 'pending', 'under_review')
             AND t.submission_status != 'archived'",
            $current_user_id
        ));

        $archived_count = 0;
        foreach ($active_submissions as $submission) {
            // 更新 TCross 表
            $tcross_result = $wpdb->update(
                $tcross_table,
                array(
                    'submission_status' => 'archived',
                    'is_current_active' => 0,
                    'admin_notes' => ($submission->admin_notes ? $submission->admin_notes . "\n\n" : "") . "[系統]: 用戶修正資料，舊記錄已下架 (" . current_time('Y-m-d H:i:s') . ")",
                    'updated_at' => current_time('mysql'),
                    'archived_at' => current_time('mysql'),
                    'archived_reason' => 'user_revision_request'
                ),
                array('id' => $submission->id),
                array('%s', '%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );

            if ($tcross_result === false) {
                throw new Exception("更新 TCross 記錄失敗: {$submission->id}");
            }

            // 更新 Elementor 表
            if ($submission->elementor_submission_id) {
                $elementor_result = $wpdb->update(
                    $elementor_table,
                    array('status' => 'archived'),
                    array('id' => $submission->elementor_submission_id),
                    array('%s'),
                    array('%d')
                );

                if ($elementor_result === false) {
                    throw new Exception("更新 Elementor 記錄失敗: {$submission->elementor_submission_id}");
                }
            }

            // 更新 portfolio_status
            if ($submission->portfolio_id) {
                $wpdb->update(
                    $tcross_table,
                    array('portfolio_status' => 'archived'),
                    array('id' => $submission->id),
                    array('%s'),
                    array('%d')
                );
            }

            $archived_count++;
            error_log("TCross: Archived submission {$submission->id} (Elementor: {$submission->elementor_submission_id}) for user revision");
        }

        // 3. 更新用戶 meta 狀態
        update_user_meta($current_user_id, 'tcross_application_status', 'revision_pending');
        update_user_meta($current_user_id, 'tcross_archived_submissions_count', $archived_count);

        // 提交事務
        $wpdb->query('COMMIT');

        error_log("TCross: User {$current_user_id} initiated revision - Portfolio hidden, {$archived_count} submissions archived");

        wp_send_json_success(array(
            'message' => 'Portfolio 已隱藏，舊申請記錄已下架',
            'portfolio_id' => $portfolio_id,
            'archived_submissions' => $archived_count
        ));

    } catch (Exception $e) {
        // 回滾事務
        $wpdb->query('ROLLBACK');
        
        error_log("TCross: Hide portfolio failed for user {$current_user_id}: " . $e->getMessage());
        wp_send_json_error('操作失敗: ' . $e->getMessage());
    }
}

    /**
     * 通過 AJAX 更新用戶類型
     */
    public function update_user_type_ajax() {
        if (!wp_verify_nonce($_REQUEST['nonce'], 'tcross_user_nonce')) {
            wp_send_json_error('安全驗證失敗');
            return;
        }

        $user_id = intval($_REQUEST['user_id']);
        $user_type = sanitize_text_field($_REQUEST['user_type']);

        if (!$user_id || !$user_type) {
            wp_send_json_error('缺少必要參數');
            return;
        }

        // 驗證用戶類型
        if (!in_array($user_type, array('green_teacher', 'demand_unit'))) {
            wp_send_json_error('無效的用戶類型');
            return;
        }

        error_log('TCross: AJAX update user type - User ID: ' . $user_id . ', Type: ' . $user_type);

        // 保存到 wp_usermeta
        $meta_result = update_user_meta($user_id, 'tcross_user_type', $user_type);

        // 保存到自定義表格
        $table_result = TCrossUserTable::insert_user_type($user_id, $user_type);

        // 驗證保存結果
        $saved_meta = get_user_meta($user_id, 'tcross_user_type', true);

        error_log('TCross: AJAX update results - Meta: ' . ($meta_result ? 'success' : 'failed') .
                  ', Table: ' . ($table_result ? 'success' : 'failed') .
                  ', Saved value: ' . $saved_meta);

        if ($saved_meta === $user_type) {
            wp_send_json_success(array(
                'message' => '用戶類型更新成功',
                'user_id' => $user_id,
                'user_type' => $user_type
            ));
        } else {
            wp_send_json_error('用戶類型保存失敗');
        }
    }

    /**
     * 刪除用戶及相關數據
     */
    public function delete_user_ajax() {
        // 檢查權限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('權限不足');
            return;
        }

        if (!wp_verify_nonce($_REQUEST['nonce'], 'tcross_admin_nonce')) {
            wp_send_json_error('安全驗證失敗');
            return;
        }

        $user_id = intval($_REQUEST['user_id']);

        if (!$user_id) {
            wp_send_json_error('缺少用戶ID');
            return;
        }

        // 檢查用戶是否存在
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error('用戶不存在');
            return;
        }

        // 防止刪除管理員
        if (user_can($user_id, 'manage_options')) {
            wp_send_json_error('不能刪除管理員用戶');
            return;
        }

        error_log('TCross: 開始刪除用戶 ID: ' . $user_id . ', 用戶名: ' . $user->user_login);

        global $wpdb;

        try {
            // 開始事務
            $wpdb->query('START TRANSACTION');

            // 1. 刪除 TCross 用戶類型記錄
            $tcross_table = $wpdb->prefix . 'tcross_user_types';
            $deleted_types = $wpdb->delete($tcross_table, array('user_id' => $user_id), array('%d'));
            error_log('TCross: 刪除用戶類型記錄: ' . $deleted_types . ' 條');

            // 2. 刪除表單提交記錄
            $form_table = $wpdb->prefix . 'tcross_form_submissions';
            $deleted_submissions = $wpdb->delete($form_table, array('submitted_by_user_id' => $user_id), array('%d'));
            error_log('TCross: 刪除表單提交記錄: ' . $deleted_submissions . ' 條');

            // 3. 刪除 Elementor 提交記錄（如果存在）
            $elementor_table = $wpdb->prefix . 'e_submissions';
            if ($wpdb->get_var("SHOW TABLES LIKE '$elementor_table'") == $elementor_table) {
                $deleted_elementor = $wpdb->delete($elementor_table, array('user_id' => $user_id), array('%d'));
                error_log('TCross: 刪除 Elementor 提交記錄: ' . $deleted_elementor . ' 條');
            }

            // 4. 刪除用戶的 Portfolio（如果存在）
            $portfolio_id = get_user_meta($user_id, 'tcross_portfolio_id', true);
            if ($portfolio_id) {
                $deleted_portfolio = wp_delete_post($portfolio_id, true);
                if ($deleted_portfolio) {
                    error_log('TCross: 刪除 Portfolio ID: ' . $portfolio_id);
                } else {
                    error_log('TCross: Portfolio 刪除失敗 ID: ' . $portfolio_id);
                }
            }

            // 5. 刪除用戶的所有 meta 數據
            $deleted_meta = $wpdb->delete($wpdb->usermeta, array('user_id' => $user_id), array('%d'));
            error_log('TCross: 刪除用戶 meta 數據: ' . $deleted_meta . ' 條');

            // 6. 最後刪除 WordPress 用戶
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            $deleted_user = wp_delete_user($user_id);

            if (is_wp_error($deleted_user)) {
                throw new Exception('WordPress 用戶刪除失敗: ' . $deleted_user->get_error_message());
            }

            // 提交事務
            $wpdb->query('COMMIT');

            error_log('TCross: 用戶刪除成功 ID: ' . $user_id);

            wp_send_json_success(array(
                'message' => '用戶及相關數據已成功刪除',
                'user_id' => $user_id,
                'deleted_data' => array(
                    'user_types' => $deleted_types,
                    'form_submissions' => $deleted_submissions,
                    'user_meta' => $deleted_meta,
                    'portfolio' => $portfolio_id ? 'deleted' : 'none'
                )
            ));

        } catch (Exception $e) {
            // 回滾事務
            $wpdb->query('ROLLBACK');

            error_log('TCross: 用戶刪除失敗 ID: ' . $user_id . ', 錯誤: ' . $e->getMessage());
            wp_send_json_error('刪除失敗: ' . $e->getMessage());
        }
    }

    public function get_latest_submission_id() {
    if (!wp_verify_nonce($_REQUEST['nonce'], 'tcross_user_nonce')) {
        wp_send_json_error('安全驗證失敗');
        return;
    }

    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        wp_send_json_error('用戶未登入');
        return;
    }

    global $wpdb;

    // 獲取該用戶最新的 Elementor 提交 ID
    $elementor_table = $wpdb->prefix . 'e_submissions';
    $latest_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $elementor_table 
         WHERE user_id = %d AND post_id IN (1254, 1325)
         ORDER BY created_at DESC LIMIT 1",
        $current_user_id
    ));

    if ($latest_id) {
        wp_send_json_success(array('submission_id' => $latest_id));
    } else {
        wp_send_json_error('找不到最新的提交記錄');
    }
}

    public function handle_elementor_form_sent($record, $ajax_handler) {
    error_log('TCross: Form sent hook triggered');

    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        error_log('TCross: User not logged in');
        return;
    }

    // 從 record 獲取 post_id
    $form_settings = $record->get('form_settings');
    $post_id = $form_settings['post_id'] ?? get_the_ID();
    error_log('TCross: Post ID = ' . $post_id);

    // 確定用戶類型
    $user_type = $this->determine_user_type_by_post_id($post_id);
    if (!$user_type) {
        error_log('TCross: Cannot determine user type for post ID: ' . $post_id);
        return;
    }

    error_log('TCross: Processing for user ' . $current_user_id . ', type ' . $user_type);

    // 延遲 3 秒確保 Elementor 數據已保存
    wp_schedule_single_event(time() + 3, 'tcross_process_delayed_submission',
        array($current_user_id, $user_type, $post_id, 'initial', null));

    error_log('TCross: Scheduled delayed processing');
}

public function sync_latest_submission() {
    if (!wp_verify_nonce($_REQUEST['nonce'], 'tcross_sync_nonce')) {
        wp_send_json_error('安全驗證失敗');
        return;
    }

    $post_id = intval($_REQUEST['post_id']);
    $current_user_id = get_current_user_id();

    if (!$current_user_id) {
        wp_send_json_error('用戶未登入');
        return;
    }

    // 確定用戶類型
    $user_type = null;
    if ($post_id == 1254) {
        $user_type = 'green_teacher';
    } elseif ($post_id == 1325) {
        $user_type = 'demand_unit';
    } else {
        wp_send_json_error('無效的表單類型');
        return;
    }

    global $wpdb;

    // 找到該用戶最新的 Elementor 提交
    $elementor_table = $wpdb->prefix . 'e_submissions';
    $latest_submission = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $elementor_table 
         WHERE user_id = %d AND post_id = %d 
         ORDER BY created_at DESC LIMIT 1",
        $current_user_id, $post_id
    ));

    if (!$latest_submission) {
        wp_send_json_error('找不到最新的表單提交記錄');
        return;
    }

    // 檢查是否已經處理過
    $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $tcross_table WHERE elementor_submission_id = %d",
        $latest_submission->id
    ));

    if ($existing) {
        wp_send_json_success(array(
            'message' => '記錄已存在，ID: ' . $existing,
            'already_processed' => true
        ));
        return;
    }

    // 使用您驗證過的方法創建記錄
    try {
        $result = TCrossUserStatus::createSubmissionRecord(
            $latest_submission->id,
            $user_type,
            $current_user_id,
            'initial',
            null
        );

        if ($result) {
            wp_send_json_success(array(
                'message' => "成功創建記錄 ID: {$result}",
                'tcross_id' => $result,
                'elementor_id' => $latest_submission->id
            ));
        } else {
            wp_send_json_error('TCrossUserStatus::createSubmissionRecord 返回 false');
        }
    } catch (Exception $e) {
        wp_send_json_error('發生異常: ' . $e->getMessage());
    }
}

public function manual_process_submission() {
    if (!wp_verify_nonce($_REQUEST['nonce'], 'tcross_user_nonce')) {
        wp_send_json_error('安全驗證失敗');
        return;
    }

    $submission_id = intval($_REQUEST['submission_id']);
    $current_user_id = get_current_user_id(); // 使用當前登入用戶ID
    if (!$current_user_id) {
        wp_send_json_error('用戶未登入');
        return;
    }

    $user_type = 'green_teacher';
    $post_id = 1254;

    error_log("TCross Manual: Processing submission {$submission_id} for user {$current_user_id}");

    try {
        // 直接調用 TCrossUserStatus::createSubmissionRecord
        $result = TCrossUserStatus::createSubmissionRecord(
            $submission_id,
            $user_type,
            $current_user_id,
            'initial',
            null
        );

        if ($result) {
            error_log("TCross Manual: Success - created record with ID {$result}");
            wp_send_json_success("處理成功，創建記錄 ID: {$result}");
        } else {
            error_log("TCross Manual: Failed - createSubmissionRecord returned false");
            wp_send_json_error('TCrossUserStatus::createSubmissionRecord 返回 false');
        }
    } catch (Exception $e) {
        error_log("TCross Manual: Exception - " . $e->getMessage());
        wp_send_json_error('發生異常: ' . $e->getMessage());
    }
}

    /**
     * 統一的權限驗證方法
     */
    private function verify_admin_permission($nonce_action = 'tcross_admin_nonce') {
        if (!wp_verify_nonce($_REQUEST['nonce'], $nonce_action)) {
            wp_send_json_error('安全驗證失敗');
            return false;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('權限不足');
            return false;
        }

        return true;
    }

    /**
     * 統一的用戶權限驗證方法
     */
    private function verify_user_permission($nonce_action = 'tcross_user_nonce') {
        if (!wp_verify_nonce($_REQUEST['nonce'], $nonce_action)) {
            wp_send_json_error('安全驗證失敗');
            return false;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error('用戶未登入');
            return false;
        }

        return true;
    }

    /**
     * 統一的參數處理方法
     */
    private function get_request_params($required_params = array(), $optional_params = array()) {
        $params = array();

        // 處理必填參數
        foreach ($required_params as $param => $type) {
            if (!isset($_REQUEST[$param])) {
                wp_send_json_error("缺少必要參數: {$param}");
                return false;
            }

            $params[$param] = $this->sanitize_param($_REQUEST[$param], $type);
        }

        // 處理選填參數
        foreach ($optional_params as $param => $config) {
            $type = $config['type'];
            $default = $config['default'] ?? null;

            $params[$param] = isset($_REQUEST[$param])
                ? $this->sanitize_param($_REQUEST[$param], $type)
                : $default;
        }

        return $params;
    }

    /**
     * 參數清理方法
     */
    private function sanitize_param($value, $type) {
        switch ($type) {
            case 'int':
                return intval($value);
            case 'text':
                return sanitize_text_field($value);
            case 'email':
                return sanitize_email($value);
            case 'textarea':
                return sanitize_textarea_field($value);
            case 'array':
                return is_array($value) ? array_map('sanitize_text_field', $value) : array();
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * 註冊 REST API 路由
     */
    public function register_rest_routes() {
        register_rest_route('tcross/v1', '/users/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_user_stats'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route('tcross/v1', '/users/(?P<type>[a-zA-Z_]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_users_by_type'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route('tcross/v1', '/user/(?P<id>\d+)/status', array(
            'methods' => 'PUT',
            'callback' => array($this, 'rest_update_user_status'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
    }

    /**
     * 檢查管理員權限
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * 獲取用戶統計數據
     */
    public function get_user_stats() {
        if (!$this->verify_admin_permission()) return;

        $stats = TCrossUserTable::get_user_type_stats();

        // 計算總計
        $total_stats = array_reduce($stats, function($total, $type_stats) {
            foreach (['total', 'active', 'today', 'week', 'month'] as $key) {
                $total[$key] = ($total[$key] ?? 0) + ($type_stats[$key] ?? 0);
            }
            return $total;
        }, array_fill_keys(['total', 'active', 'today', 'week', 'month'], 0));

        $stats['total'] = $total_stats;
        wp_send_json_success($stats);
    }

    /**
     * 根據類型獲取用戶列表
     */
    public function get_users_by_type() {
        if (!$this->verify_admin_permission()) return;

        $params = $this->get_request_params(
            array('user_type' => 'text'),
            array(
                'page' => array('type' => 'int', 'default' => 1),
                'per_page' => array('type' => 'int', 'default' => 20)
            )
        );

        if ($params === false) return;

        $offset = ($params['page'] - 1) * $params['per_page'];
        $users = TCrossUserTable::get_users_by_type($params['user_type'], $params['per_page'], $offset);

        // 添加額外的用戶資訊
        foreach ($users as &$user) {
            $wp_user = get_user_by('id', $user->user_id);
            if ($wp_user) {
                $user->user_roles = $wp_user->roles;
                $user->user_meta = get_user_meta($user->user_id);
            }
        }

        wp_send_json_success(array(
            'users' => $users,
            'pagination' => array(
                'page' => $params['page'],
                'per_page' => $params['per_page'],
                'total' => count($users)
            )
        ));
    }

    /**
     * 更新用戶狀態
     */
    public function update_user_status() {
        if (!$this->verify_admin_permission()) return;

        $params = $this->get_request_params(array(
            'user_id' => 'int',
            'status' => 'text'
        ));

        if ($params === false) return;

        $allowed_statuses = array('active', 'blocked');
        if (!in_array($params['status'], $allowed_statuses)) {
            wp_send_json_error('無效的狀態值');
            return;
        }

        $result = TCrossUserTable::update_user_status($params['user_id'], $params['status']);

        if ($result) {
            wp_send_json_success('狀態更新成功');
        } else {
            wp_send_json_error('狀態更新失敗');
        }
    }

    /**
     * 搜尋用戶
     */
    public function search_users() {
        if (!$this->verify_admin_permission()) return;

        $params = $this->get_request_params(
            array(
                'search_term' => 'text',
                'user_type' => 'text'
            ),
            array(
                'status' => array('type' => 'text', 'default' => ''),
                'limit' => array('type' => 'int', 'default' => 50)
            )
        );

        if ($params === false) return;

        $users = TCrossUserTable::search_users(
            $params['search_term'],
            $params['user_type'],
            $params['limit'],
            $params['status']
        );

        wp_send_json_success($users);
    }

    /**
     * 匯出用戶數據
     */
    public function export_users() {
        if (!$this->verify_admin_permission()) return;

        // 支援 export_user_type 和 user_type 兩種參數名稱（向後相容）
        $user_type = isset($_REQUEST['export_user_type']) ? sanitize_text_field($_REQUEST['export_user_type']) :
                    (isset($_REQUEST['user_type']) ? sanitize_text_field($_REQUEST['user_type']) : 'all');

        $status = isset($_REQUEST['export_status']) ? sanitize_text_field($_REQUEST['export_status']) : 'all';
        $format = isset($_REQUEST['export_format']) ? sanitize_text_field($_REQUEST['export_format']) :
                 (isset($_REQUEST['format']) ? sanitize_text_field($_REQUEST['format']) : 'csv');

        // 獲取用戶資料，支援類型和狀態篩選
        $users = TCrossUserTable::get_users_by_type($user_type, 9999, 0, $status);

        if ($format === 'csv') {
            $this->export_to_csv($users, $user_type, $status);
        } elseif ($format === 'json') {
            $this->export_to_json($users, $user_type, $status);
        }
    }

    /**
     * 匯出為 CSV
     */
    private function export_to_csv($users, $user_type, $status = 'all') {
        // 建立檔案名稱
        $filename_parts = array('tcross_users');
        if ($user_type !== 'all') {
            $filename_parts[] = $user_type;
        }
        if ($status !== 'all') {
            $filename_parts[] = $status;
        }
        $filename_parts[] = date('Y-m-d');
        $filename = implode('_', $filename_parts) . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // 添加 BOM 以支援 Excel 正確顯示中文
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // CSV 標題
        fputcsv($output, array(
            'ID', '用戶名', '顯示名稱', '電子郵件', '用戶類型',
            '註冊日期', '狀態', '最後更新'
        ));

        // 數據行
        foreach ($users as $user) {
            fputcsv($output, array(
                $user->user_id,
                $user->user_login ?: '',
                $user->display_name ?: '',
                $user->user_email ?: '',
                $user->user_type,
                $user->registration_date,
                $user->status,
                $user->updated_at
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * 匯出為 JSON
     */
    private function export_to_json($users, $user_type, $status = 'all') {
        // 建立檔案名稱
        $filename_parts = array('tcross_users');
        if ($user_type !== 'all') {
            $filename_parts[] = $user_type;
        }
        if ($status !== 'all') {
            $filename_parts[] = $status;
        }
        $filename_parts[] = date('Y-m-d');
        $filename = implode('_', $filename_parts) . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $export_data = array(
            'export_date' => current_time('mysql'),
            'user_type' => $user_type,
            'status' => $status,
            'total_users' => count($users),
            'users' => $users
        );

        echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 處理自定義註冊
     */
    public function handle_custom_register() {
        // 驗證 nonce
        if (!wp_verify_nonce($_POST['nonce'], 'tcross_register_nonce')) {
            wp_send_json_error('安全驗證失敗');
            return;
        }

        $params = $this->get_request_params(array(
            'user_type' => 'text',
            'username' => 'text',
            'email' => 'email',
            'password' => 'text',
            'confirm_password' => 'text'
        ));

        if ($params === false) return;

        // 驗證用戶類型
        if (!in_array($params['user_type'], array('demand_unit', 'green_teacher'))) {
            wp_send_json_error('無效的用戶類型');
            return;
        }

        // 驗證密碼確認
        if ($params['password'] !== $params['confirm_password']) {
            wp_send_json_error('密碼確認不一致');
            return;
        }

        // 檢查用戶名和電子郵件是否已存在
        if (username_exists($params['username'])) {
            wp_send_json_error('用戶名已存在');
            return;
        }

        if (email_exists($params['email'])) {
            wp_send_json_error('電子郵件已被註冊');
            return;
        }

        // 創建用戶
        $user_id = wp_create_user($params['username'], $params['password'], $params['email']);

        if (is_wp_error($user_id)) {
            wp_send_json_error('註冊失敗：' . $user_id->get_error_message());
            return;
        }

        // 保存用戶類型
        update_user_meta($user_id, 'tcross_user_type', $params['user_type']);
        TCrossUserTable::insert_user_type($user_id, $params['user_type']);

        // 添加用戶角色
        $user = new WP_User($user_id);
        $user->add_role($params['user_type']);

        // 發送歡迎郵件
        $this->send_welcome_email($user_id, $params['user_type']);

        wp_send_json_success(array(
            'message' => '註冊成功！歡迎加入 TCross！',
            'user_id' => $user_id,
            'user_type' => $params['user_type']
        ));
    }

    /**
     * 發送歡迎郵件
     */
    private function send_welcome_email($user_id, $user_type) {
        $user = get_user_by('id', $user_id);
        if (!$user) return;

        $type_names = array(
            'demand_unit' => '需求單位',
            'green_teacher' => '綠照師'
        );

        $type_name = $type_names[$user_type] ?? $user_type;
        $subject = '歡迎加入 TCross！';

        $message = "親愛的 {$user->display_name}，\n\n";
        $message .= "歡迎您以「{$type_name}」身份加入 TCross 平台！\n\n";
        $message .= "您的帳戶資訊：\n";
        $message .= "用戶名：{$user->user_login}\n";
        $message .= "電子郵件：{$user->user_email}\n";
        $message .= "註冊類型：{$type_name}\n\n";
        $message .= "您現在可以登入平台開始使用各項功能。\n\n";
        $message .= "如有任何問題，請隨時與我們聯繫。\n\n";
        $message .= "TCross 團隊";

        wp_mail($user->user_email, $subject, $message);
    }

    // REST API 方法
    public function rest_get_user_stats($request) {
        $stats = TCrossUserTable::get_user_type_stats();
        return new WP_REST_Response($stats, 200);
    }

    public function rest_get_users_by_type($request) {
        $user_type = $request['type'];
        $page = $request->get_param('page') ?: 1;
        $per_page = $request->get_param('per_page') ?: 20;
        $offset = ($page - 1) * $per_page;

        $users = TCrossUserTable::get_users_by_type($user_type, $per_page, $offset);

        return new WP_REST_Response(array(
            'users' => $users,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => count($users)
            )
        ), 200);
    }

    public function rest_update_user_status($request) {
        $user_id = $request['id'];
        $status = $request->get_param('status');

        $allowed_statuses = array('active', 'inactive', 'pending', 'blocked');
        if (!in_array($status, $allowed_statuses)) {
            return new WP_Error('invalid_status', '無效的狀態值', array('status' => 400));
        }

        $result = TCrossUserTable::update_user_status($user_id, $status);

        if ($result) {
            return new WP_REST_Response(array('message' => '狀態更新成功'), 200);
        } else {
            return new WP_Error('update_failed', '狀態更新失敗', array('status' => 500));
        }
    }

    /**
     * 獲取當前用戶的類型資訊
     */
    public function get_current_user_type() {
        if (!is_user_logged_in()) {
            wp_send_json_error('用戶未登入');
            return;
        }

        $user_id = get_current_user_id();
        $user_type_data = TCrossUserTable::get_user_type($user_id);

        if ($user_type_data) {
            wp_send_json_success($user_type_data);
        } else {
            wp_send_json_error('未找到用戶類型資訊');
        }
    }

    /**
     * 處理 Elementor 表單提交
     */
    public function handle_elementor_form_submission($record, $handler) {
        error_log('TCross: handle_elementor_form_submission called');

        $current_user_id = get_current_user_id();
        if (!$current_user_id) {
            error_log('TCross: User not logged in, blocking form submission');
            return;
        }

        $post_id = $record->get_form_settings('post_id');
        error_log('TCross: Form submission by user ID: ' . $current_user_id . ', Post ID: ' . $post_id);

        // 確定用戶類型
        $user_type = $this->determine_user_type_by_post_id($post_id);
        if (!$user_type) {
            error_log('TCross: Cannot determine user type for post ID: ' . $post_id);
            return;
        }

        error_log('TCross: Determined user type: ' . $user_type);

        // 檢查提交模式
        $mode = $_GET['mode'] ?? 'initial';
        $replaces_id = $_GET['replaces'] ?? null;

        // 確定提交類型
        $submission_type = ($user_type === 'green_teacher' && $mode === 'revision') ? 'revision' :
                          ($user_type === 'demand_unit' ? 'new_demand' : 'initial');

        error_log("TCross: Processing submission - User: {$current_user_id}, Type: {$user_type}, Mode: {$mode}, Submission Type: {$submission_type}");

        $this->handle_delayed_submission($current_user_id, $user_type, $post_id, $submission_type, $replaces_id);
    }

    /**
     * 延遲處理提交記錄
     */
public function handle_delayed_submission($current_user_id, $user_type, $post_id, $submission_type = 'initial', $replaces_id = null) {
    global $wpdb;

    error_log("TCross: Handle delayed submission - User: {$current_user_id}, Type: {$user_type}, Post: {$post_id}");

    $elementor_table = $wpdb->prefix . 'e_submissions';

    // 查找最新的提交記錄
    $latest_submission = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $elementor_table 
         WHERE user_id = %d AND post_id = %d 
         ORDER BY created_at DESC LIMIT 1",
        $current_user_id, $post_id
    ));

    if (!$latest_submission) {
        error_log('TCross: Could not find latest Elementor submission');
        return false;
    }

    $elementor_submission_id = $latest_submission->id;
    error_log('TCross: Processing Elementor submission ID: ' . $elementor_submission_id);

    // 檢查是否已經處理過
    $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $tcross_table WHERE elementor_submission_id = %d",
        $elementor_submission_id
    ));

    if ($existing) {
        error_log('TCross: Submission already processed');
        return false;
    }

    // 注意：舊的表單下架邏輯已經移到 hide_user_portfolio() 函數中
    // 當用戶按下「修正資料」按鈕時就會立即處理，這裡不再重複處理
    if ($user_type === 'green_teacher') {
        error_log("TCross: 綠照師提交新表單 - 舊記錄應該已在修正資料時下架");
    }

    // 直接插入新的提交記錄
    $result = $wpdb->insert(
        $tcross_table,
        array(
            'elementor_submission_id' => $elementor_submission_id,
            'user_type' => $user_type,
            'submission_status' => 'pending',
            'submitted_by_user_id' => $current_user_id,
            'submission_type' => $submission_type,
            'is_current_active' => ($user_type === 'green_teacher') ? 1 : 0,
            'replaces_submission_id' => $replaces_id,
            'portfolio_status' => 'none'
        ),
        array('%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s')
    );

    if ($result === false) {
        error_log('TCross: Failed to insert submission: ' . $wpdb->last_error);
        return false;
    }

    $tcross_id = $wpdb->insert_id;
    error_log('TCross: Form submission processed with TCross ID: ' . $tcross_id);

    return $tcross_id;
}

    /**
     * 根據 post_id 判斷用戶類型
     */
    private function determine_user_type_by_post_id($post_id) {
        switch ($post_id) {
            case 1254:
                return 'green_teacher';
            case 1325:
                return 'demand_unit';
            default:
                $post = get_post($post_id);
                if ($post) {
                    $title = $post->post_title;
                    if (strpos($title, '綠照夥伴') !== false || strpos($title, '綠照師') !== false) {
                        return 'green_teacher';
                    } elseif (strpos($title, '綠照地圖') !== false || strpos($title, '需求單位') !== false) {
                        return 'demand_unit';
                    }
                }
                return null;
        }
    }

    /**
     * 獲取表單提交列表
     */
    public function get_form_submissions() {
        if (!$this->verify_admin_permission()) return;

        $params = $this->get_request_params(
            array(),
            array(
                'user_type' => array('type' => 'text', 'default' => ''),
                'status' => array('type' => 'text', 'default' => ''),
                'page' => array('type' => 'int', 'default' => 1),
                'per_page' => array('type' => 'int', 'default' => 20)
            )
        );

        if ($params === false) return;

        $offset = ($params['page'] - 1) * $params['per_page'];
        $submissions = TCrossUserTable::get_form_submissions(
            $params['user_type'],
            $params['status'],
            $params['per_page'],
            $offset
        );

        wp_send_json_success(array(
            'submissions' => $submissions,
            'pagination' => array(
                'page' => $params['page'],
                'per_page' => $params['per_page'],
                'total' => count($submissions)
            )
        ));
    }

/**
 * 更新提交狀態
 */
public function update_submission_status() {
    if (!$this->verify_admin_permission()) return;

    $params = $this->get_request_params(
        array(
            'submission_id' => 'int',
            'status' => 'text'
        ),
        array(
            'admin_notes' => array('type' => 'textarea', 'default' => ''),
            'user_id' => array('type' => 'int', 'default' => 0)
        )
    );

    if ($params === false) return;

    $allowed_statuses = array('pending', 'approved', 'rejected', 'under_review', 'archived');
    if (!in_array($params['status'], $allowed_statuses)) {
        wp_send_json_error('無效的狀態值');
        return;
    }

    global $wpdb;

    // 首先從 elementor submission ID 獲取對應的 tcross record
    $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
    $elementor_table = $wpdb->prefix . 'e_submissions';

    // 前端傳遞的是 Elementor submission ID，需要查找對應的 TCross 記錄
    // 先記錄查詢參數
    error_log("TCross: 查詢參數 - Elementor ID: {$params['submission_id']}, User ID: {$params['user_id']}");

    // 先嘗試不加用戶ID限制的查詢，看看是否存在記錄
    $all_records = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $tcross_table WHERE elementor_submission_id = %d",
        $params['submission_id']
    ));
    error_log("TCross: 找到 " . count($all_records) . " 筆記錄，詳情: " . print_r($all_records, true));

    // 直接使用 Elementor submission ID 查找，不限制用戶ID
    $tcross_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tcross_table WHERE elementor_submission_id = %d",
        $params['submission_id']
    ));

    // 如果找到多筆記錄，取第一筆並警告
    if ($tcross_record) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tcross_table WHERE elementor_submission_id = %d",
            $params['submission_id']
        ));

        if ($count > 1) {
            error_log("TCross: 警告 - 找到 {$count} 筆記錄使用相同的 Elementor ID {$params['submission_id']}，使用第一筆");
        }
    }

    error_log("TCross: 查詢結果: " . ($tcross_record ? "找到記錄 ID: {$tcross_record->id}" : "未找到記錄"));

    if (!$tcross_record) {
        // 收集調試信息
        $debug_info = array();
        $debug_info[] = "查詢參數 - Elementor ID: {$params['submission_id']}, User ID: {$params['user_id']}";
        $debug_info[] = "找到 " . count($all_records) . " 筆 TCross 記錄";

        foreach ($all_records as $i => $record) {
            $debug_info[] = "記錄 " . ($i+1) . ": ID={$record->id}, submitted_by_user_id={$record->submitted_by_user_id}, status={$record->submission_status}";
        }

        // 檢查 Elementor 記錄
        $elementor_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $elementor_table WHERE id = %d",
            $params['submission_id']
        ));

        if ($elementor_record) {
            $debug_info[] = "Elementor 記錄存在: user_id={$elementor_record->user_id}, post_id={$elementor_record->post_id}";
        } else {
            $debug_info[] = "Elementor 記錄不存在";
        }

        $debug_message = "調試信息:\n" . implode("\n", $debug_info);

        wp_send_json_error($debug_message);
        return;
    }

    $reviewed_by = get_current_user_id();

    // 1. 更新 tcross_form_submissions 表（使用 TCross 記錄的 ID）
    $tcross_result = $wpdb->update(
        $tcross_table,
        array(
            'submission_status' => $params['status'],
            'admin_notes' => $params['admin_notes'],
            'reviewed_at' => current_time('mysql'),
            'reviewed_by' => $reviewed_by
        ),
        array('id' => $tcross_record->id), // 使用 TCross 記錄的 ID
        array('%s', '%s', '%s', '%d'),
        array('%d')
    );

    // 2. 同時更新 wp_e_submissions 表的狀態（使用 Elementor 提交 ID）
    $elementor_status = $this->convert_to_elementor_status($params['status']);
    $elementor_result = $wpdb->update(
        $elementor_table,
        array('status' => $elementor_status),
        array('id' => $params['submission_id']), // 使用前端傳遞的 Elementor ID
        array('%s'),
        array('%d')
    );

    // 記錄更新操作的詳細信息
    error_log("TCross: 更新狀態 - TCross ID: {$tcross_record->id}, Elementor ID: {$params['submission_id']}, 新狀態: {$params['status']}");

    if ($tcross_result !== false && $elementor_result !== false) {
        // 3. 同時更新用戶的 meta 資料（保持向後兼容）
        if ($tcross_record->submitted_by_user_id) {
            update_user_meta($tcross_record->submitted_by_user_id, 'tcross_application_status', $params['status']);

            if (!empty($params['admin_notes'])) {
                update_user_meta($tcross_record->submitted_by_user_id, 'tcross_admin_notes', $params['admin_notes']);
            }
        }

        // 4. 處理狀態變更後續操作
        $this->handle_status_change($tcross_record, $params['status']);

        wp_send_json_success(array(
            'message' => '狀態更新成功',
            'tcross_updated' => $tcross_result,
            'elementor_updated' => $elementor_result,
            'elementor_status' => $elementor_status
        ));
    } else {
        $error_msg = '';
        if ($tcross_result === false) {
            $error_msg .= 'TCross表更新失敗: ' . $wpdb->last_error . '; ';
        }
        if ($elementor_result === false) {
            $error_msg .= 'Elementor表更新失敗: ' . $wpdb->last_error;
        }

        wp_send_json_error('狀態更新失敗: ' . $error_msg);
    }
}

/**
 * 將內部狀態轉換為 Elementor 狀態
 */
private function convert_to_elementor_status($internal_status) {
    $status_mapping = array(
        'pending' => 'new',
        'under_review' => 'new',
        'approved' => 'approved',
        'rejected' => 'rejected'
    );

    return $status_mapping[$internal_status] ?? 'new';
}

/**
 * 處理審核通過
 */
private function handle_approval($tcross_record) {
    error_log("TCross: Approved submission {$tcross_record->id} for user {$tcross_record->submitted_by_user_id}");

    // 創建 Portfolio
    $portfolio_id = $this->create_portfolio_for_submission($tcross_record);

    if ($portfolio_id) {
        // 更新 tcross_form_submissions 記錄的 portfolio_id
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tcross_form_submissions',
            array(
                'portfolio_id' => $portfolio_id,
                'portfolio_status' => 'active'
            ),
            array('id' => $tcross_record->id),
            array('%d', '%s'),
            array('%d')
        );

        // 更新用戶 meta
        update_user_meta($tcross_record->submitted_by_user_id, 'tcross_portfolio_id', $portfolio_id);

        error_log("TCross: Created portfolio {$portfolio_id} for submission {$tcross_record->id}");
    } else {
        error_log("TCross: Failed to create portfolio for submission {$tcross_record->id}");
    }

    // 發送通過通知郵件
    $this->send_approval_notification($tcross_record);
}

/**
 * 為提交記錄創建 Portfolio
 */
private function create_portfolio_for_submission($tcross_record) {
    // 獲取 Elementor 表單數據
    $form_data = TCrossUserTable::get_elementor_submission_data($tcross_record->elementor_submission_id, $tcross_record->user_type);

    if (!$form_data) {
        error_log("TCross: No form data found for submission {$tcross_record->elementor_submission_id}");
        return false;
    }

    if ($tcross_record->user_type === 'green_teacher') {
        return $this->create_green_teacher_portfolio($form_data, $tcross_record);
    } elseif ($tcross_record->user_type === 'demand_unit') {
        return $this->create_demand_unit_portfolio($form_data, $tcross_record);
    }

    return false;
}

/**
 * 創建綠照師 Portfolio
 */
private function create_green_teacher_portfolio($form_data, $tcross_record) {
    // 獲取作者姓名
    $author_name = '';
    if (isset($form_data['name']['value']) && !empty($form_data['name']['value'])) {
        $author_name = $form_data['name']['value'];
    } else {
        // 如果表單中沒有姓名，嘗試從用戶資料獲取
        $user = get_user_by('id', $tcross_record->submitted_by_user_id);
        if ($user) {
            $author_name = $user->display_name ?: $user->user_login;
        } else {
            $author_name = '綠照師 #' . $tcross_record->submitted_by_user_id;
        }
    }

    $post_data = array(
        'post_title' => $author_name,  // 使用作者姓名作為標題
        'post_content' => $this->generate_green_teacher_content($form_data),
        'post_status' => 'publish',
        'post_type' => 'portfolio',
        'post_author' => $tcross_record->submitted_by_user_id,
        'meta_input' => $this->prepare_green_teacher_meta($form_data)
    );

    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id) || !$post_id) {
        error_log('TCross: Failed to create green teacher portfolio: ' .
            (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'));
        return false;
    }

    // 設置分類 (term_id: 29 for 綠照師)
    wp_set_object_terms($post_id, array(29), 'portfolio_category');

    // 處理精選圖片
    if (!empty($form_data['field_dc61789']['value'])) {
        $this->set_featured_image($post_id, $form_data['field_dc61789']['value']);
    }

    return $post_id;
}

/**
 * 創建需求單位 Portfolio
 */
private function create_demand_unit_portfolio($form_data, $tcross_record) {
    // 獲取單位名稱
    $unit_name = '';
    if (isset($form_data['name']['value']) && !empty($form_data['name']['value'])) {
        $unit_name = $form_data['name']['value'];
    } else {
        // 如果表單中沒有單位名稱，嘗試從用戶資料獲取
        $user = get_user_by('id', $tcross_record->submitted_by_user_id);
        if ($user) {
            $unit_name = $user->display_name ?: $user->user_login;
        } else {
            $unit_name = '需求單位 #' . $tcross_record->submitted_by_user_id;
        }
    }

    $post_data = array(
        'post_title' => $unit_name,  // 使用單位名稱作為標題
        'post_content' => $this->generate_demand_unit_content($form_data),
        'post_status' => 'publish',
        'post_type' => 'portfolio',
        'post_author' => $tcross_record->submitted_by_user_id,
        'meta_input' => $this->prepare_demand_unit_meta($form_data)
    );

    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id) || !$post_id) {
        error_log('TCross: Failed to create demand unit portfolio: ' .
            (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'));
        return false;
    }

    // 設置分類 (term_id: 30 for 需求單位)
    wp_set_object_terms($post_id, array(30), 'portfolio_category');

        // 處理精選圖片
    if (!empty($form_data['field_dc61789']['value'])) {
        $this->set_featured_image($post_id, $form_data['field_dc61789']['value']);
    }

    return $post_id;
}

/**
 * 處理審核拒絕
 */
private function handle_rejection($tcross_record) {
    error_log("TCross: Rejected submission {$tcross_record->id} for user {$tcross_record->submitted_by_user_id}");

    // 發送拒絕通知郵件
    $this->send_rejection_notification($tcross_record);
}

/**
 * 發送審核通過通知
 */
private function send_approval_notification($tcross_record) {
    if (!$tcross_record->submitted_by_user_id) return;

    $user = get_user_by('id', $tcross_record->submitted_by_user_id);
    if (!$user) return;

    $type_names = array(
        'green_teacher' => '綠照師',
        'demand_unit' => '需求單位'
    );

    $type_name = $type_names[$tcross_record->user_type] ?? $tcross_record->user_type;
    $subject = "申請審核通過 - TCross {$type_name}";

    $message = "親愛的 {$user->display_name}，\n\n";
    $message .= "恭喜您！您的{$type_name}申請已經審核通過。\n\n";
    $message .= "您現在可以使用您的帳號登入系統，開始使用所有功能。\n\n";
    $message .= "如有任何問題，請隨時與我們聯繫。\n\n";
    $message .= "TCross 團隊";

    wp_mail($user->user_email, $subject, $message);
}

/**
 * 發送審核拒絕通知
 */
private function send_rejection_notification($tcross_record) {
    if (!$tcross_record->submitted_by_user_id) return;

    $user = get_user_by('id', $tcross_record->submitted_by_user_id);
    if (!$user) return;

    $type_names = array(
        'green_teacher' => '綠照師',
        'demand_unit' => '需求單位'
    );

    $type_name = $type_names[$tcross_record->user_type] ?? $tcross_record->user_type;
    $subject = "申請審核結果 - TCross {$type_name}";

    $message = "親愛的 {$user->display_name}，\n\n";
    $message .= "很遺憾，您的{$type_name}申請未能通過審核。\n\n";
    $message .= "您可以重新提交申請，或聯繫我們了解更多詳情。\n\n";
    $message .= "如有任何問題，請隨時與我們聯繫。\n\n";
    $message .= "TCross 團隊";

    wp_mail($user->user_email, $subject, $message);
}

    /**
     * 處理狀態變更後續操作
     */
//    private function handle_status_change($submission_id, $status) {
//        $submission_service = new TCrossSubmissionService();
//
//        if ($status === 'approved') {
//            $submission_service->approveSubmission($submission_id);
//        } elseif ($status === 'rejected') {
//            $submission_service->rejectSubmission($submission_id);
//        }
//    }
private function handle_status_change($tcross_record, $status) {
    $submission_service = new TCrossSubmissionService();

    if ($status === 'approved') {
        $submission_service->approveSubmission($tcross_record->elementor_submission_id);
    } elseif ($status === 'rejected') {
        $submission_service->rejectSubmission($tcross_record->elementor_submission_id);
    }
}

    /**
     * 獲取提交詳情
     */
    public function get_submission_details() {
        if (!$this->verify_admin_permission()) return;

        $params = $this->get_request_params(array('submission_id' => 'int'));
        if ($params === false) return;

        $submission = TCrossUserTable::get_form_submission($params['submission_id']);

        if ($submission) {
            wp_send_json_success($submission);
        } else {
            wp_send_json_error('找不到提交記錄');
        }
    }

    /**
     * 獲取用戶提交狀態
     */
    public function get_user_submission_status() {
        if (!$this->verify_user_permission('tcross_user_nonce')) return;

        $user_id = get_current_user_id();
        $user_type_data = TCrossUserTable::get_user_type($user_id);

        if (!$user_type_data) {
            wp_send_json_error('用戶類型未設定');
            return;
        }

        $user_type = $user_type_data->user_type;
        $status_data = TCrossUserStatus::getUserSubmissionStatus($user_id, $user_type);

        wp_send_json_success($status_data);
    }

    /**
     * 獲取用戶提交歷史
     * 顯示用戶所有提交記錄，包括已下架的
     */
    public function get_user_submission_history() {
        if (!$this->verify_user_permission('tcross_user_nonce')) return;

        $user_id = get_current_user_id();
        global $wpdb;

        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
        $elementor_table = $wpdb->prefix . 'e_submissions';

        // 獲取用戶所有提交記錄（包括已下架）
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, e.created_at as submitted_at 
             FROM $tcross_table t
             LEFT JOIN $elementor_table e ON t.elementor_submission_id = e.id
             WHERE t.submitted_by_user_id = %d 
             ORDER BY t.created_at DESC",
            $user_id
        ));

        $history = array();
        foreach ($submissions as $submission) {
            $status_info = array(
                'id' => $submission->id,
                'user_type' => $submission->user_type,
                'submission_type' => $submission->submission_type,
                'status' => $submission->submission_status,
                'is_current_active' => $submission->is_current_active,
                'submitted_at' => $submission->submitted_at ?: $submission->created_at,
                'updated_at' => $submission->updated_at,
                'admin_notes' => $submission->admin_notes,
                'portfolio_id' => $submission->portfolio_id,
                'portfolio_status' => $submission->portfolio_status
            );

            // 添加下架相關資訊
            if ($submission->submission_status === 'archived') {
                $status_info['archived_at'] = $submission->archived_at;
                $status_info['archived_reason'] = $submission->archived_reason;
            }

            $history[] = $status_info;
        }

        wp_send_json_success(array(
            'history' => $history,
            'total_submissions' => count($history),
            'active_submissions' => count(array_filter($history, function($h) { return $h['is_current_active']; }))
        ));
    }
}

/**
 * 提交處理服務類
 * 將業務邏輯從 API 類中分離出來
 */
class TCrossSubmissionService {

    public function processDelayedSubmission($current_user_id, $user_type, $post_id, $submission_type, $replaces_id) {
        global $wpdb;

        error_log("TCross: Handle delayed submission - User: {$current_user_id}, Type: {$user_type}, Post: {$post_id}, Submission Type: {$submission_type}");

        $elementor_table = $wpdb->prefix . 'e_submissions';

        // 查找最新的提交記錄
        $latest_submission = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $elementor_table 
             WHERE user_id = %d AND post_id = %d 
             ORDER BY created_at DESC LIMIT 1",
            $current_user_id, $post_id
        ));

        if (!$latest_submission) {
            error_log('TCross: Could not find latest Elementor submission');
            return false;
        }

        $elementor_submission_id = $latest_submission->id;
        error_log('TCross: Processing Elementor submission ID: ' . $elementor_submission_id);

        // 檢查是否已經處理過
        if ($this->isSubmissionProcessed($elementor_submission_id)) {
            error_log('TCross: Submission already processed');
            return false;
        }

        // 如果是綠照師修正提交，先處理舊提交
        if ($user_type === 'green_teacher' && $submission_type === 'revision') {
            $this->handleRevisionSubmission($current_user_id);
        }

        // 創建新的提交記錄
        $tcross_id = TCrossUserStatus::createSubmissionRecord(
            $elementor_submission_id,
            $user_type,
            $current_user_id,
            $submission_type,
            $replaces_id
        );

        if ($tcross_id) {
            error_log('TCross: Form submission processed with TCross ID: ' . $tcross_id);
            return $tcross_id;
        } else {
            error_log('TCross: Failed to process form submission');
            return false;
        }
    }

    /**
     * 直接下架用戶之前的提交記錄
     */
    private function archiveUserPreviousSubmissions($current_user_id) {
        global $wpdb;

        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
        $elementor_table = $wpdb->prefix . 'e_submissions';

        error_log("TCross: 開始查找用戶 {$current_user_id} 需要下架的記錄");

        // 查找所有需要下架的記錄
        $active_submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, e.id as elementor_id FROM $tcross_table t
             LEFT JOIN $elementor_table e ON t.elementor_submission_id = e.id
             WHERE t.submitted_by_user_id = %d 
             AND t.user_type = 'green_teacher'
             AND t.submission_status IN ('approved', 'pending', 'under_review')
             AND t.submission_status != 'archived'",
            $current_user_id
        ));

        error_log("TCross: 找到 " . count($active_submissions) . " 筆需要下架的記錄");

        foreach ($active_submissions as $submission) {
            error_log("TCross: 正在下架記錄 - TCross ID: {$submission->id}, Elementor ID: {$submission->elementor_submission_id}, 當前狀態: {$submission->submission_status}");

            // 1. 更新 TCross 表
            $tcross_result = $wpdb->update(
                $tcross_table,
                array(
                    'submission_status' => 'archived',
                    'is_current_active' => 0,
                    'admin_notes' => ($submission->admin_notes ? $submission->admin_notes . "\n\n" : "") . "[系統]: 因用戶重新送審而下架 (" . current_time('Y-m-d H:i:s') . ")",
                    'updated_at' => current_time('mysql'),
                    'archived_at' => current_time('mysql'),
                    'archived_reason' => 'user_resubmission'
                ),
                array('id' => $submission->id),
                array('%s', '%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );

            error_log("TCross: TCross 表更新結果: " . ($tcross_result !== false ? "成功" : "失敗 - " . $wpdb->last_error));

            // 2. 更新 Elementor 表
            if ($submission->elementor_submission_id) {
                $elementor_result = $wpdb->update(
                    $elementor_table,
                    array('status' => 'archived'),
                    array('id' => $submission->elementor_submission_id),
                    array('%s'),
                    array('%d')
                );

                error_log("TCross: Elementor 表更新結果: " . ($elementor_result !== false ? "成功" : "失敗 - " . $wpdb->last_error));
            }

            // 3. 下架 Portfolio
            if ($submission->portfolio_id) {
                $portfolio_result = wp_update_post(array(
                    'ID' => $submission->portfolio_id,
                    'post_status' => 'draft'
                ));

                if (!is_wp_error($portfolio_result)) {
                    // 更新 portfolio_status
                    $wpdb->update(
                        $tcross_table,
                        array('portfolio_status' => 'archived'),
                        array('id' => $submission->id),
                        array('%s'),
                        array('%d')
                    );

                    error_log("TCross: Portfolio {$submission->portfolio_id} 已下架");
                } else {
                    error_log("TCross: Portfolio {$submission->portfolio_id} 下架失敗: " . $portfolio_result->get_error_message());
                }
            }
        }

        error_log("TCross: 完成下架操作，共處理 " . count($active_submissions) . " 筆記錄");
    }

    /**
     * 檢查提交是否已經處理過
     */
    private function isSubmissionProcessed($elementor_submission_id) {
        global $wpdb;

        $existing_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tcross_form_submissions WHERE elementor_submission_id = %d",
            $elementor_submission_id
        ));

        return !empty($existing_record);
    }

    /**
     * 處理修正提交 - 改進版本
     * 將舊提交狀態設為"已下架"，並隱藏相關portfolio
     */
    private function handleRevisionSubmission($current_user_id) {
        global $wpdb;
        
        // 獲取當前用戶所有已通過或待審核的綠照師提交
        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
        $elementor_table = $wpdb->prefix . 'e_submissions';
        
        $active_submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, e.id as elementor_id FROM $tcross_table t
             LEFT JOIN $elementor_table e ON t.elementor_submission_id = e.id
             WHERE t.submitted_by_user_id = %d 
             AND t.user_type = 'green_teacher'
             AND t.submission_status IN ('approved', 'pending', 'under_review')
             AND t.submission_status != 'archived'",
            $current_user_id
        ));
        
        foreach ($active_submissions as $submission) {
            // 將狀態改為「已下架」
            $wpdb->update(
                $tcross_table,
                array(
                    'submission_status' => 'archived',
                    'is_current_active' => 0,
                    'admin_notes' => $submission->admin_notes ? $submission->admin_notes . "\n\n[系統]: 因用戶重新送審而下架 (" . current_time('Y-m-d H:i:s') . ")" : "[系統]: 因用戶重新送審而下架 (" . current_time('Y-m-d H:i:s') . ")",
                    'updated_at' => current_time('mysql'),
                    'archived_at' => current_time('mysql'),
                    'archived_reason' => 'user_resubmission'
                ),
                array('id' => $submission->id),
                array('%s', '%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            // 同時更新 Elementor 表的狀態
            if ($submission->elementor_id) {
                $wpdb->update(
                    $elementor_table,
                    array('status' => 'archived'),
                    array('id' => $submission->elementor_id),
                    array('%s'),
                    array('%d')
                );
            }
            
            // 隱藏相關的 Portfolio（設為草稿狀態）
            if ($submission->portfolio_id) {
                $portfolio_update_result = wp_update_post(array(
                    'ID' => $submission->portfolio_id,
                    'post_status' => 'draft',
                    'post_modified' => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', 1)
                ));
                
                if (!is_wp_error($portfolio_update_result)) {
                    // 更新 portfolio_status
                    $wpdb->update(
                        $tcross_table,
                        array('portfolio_status' => 'archived'),
                        array('id' => $submission->id),
                        array('%s'),
                        array('%d')
                    );
                    
                    // 添加 portfolio meta 記錄下架原因
                    update_post_meta($submission->portfolio_id, '_tcross_archived_reason', 'user_resubmission');
                    update_post_meta($submission->portfolio_id, '_tcross_archived_at', current_time('mysql'));
                    
                    error_log("TCross: Archived portfolio {$submission->portfolio_id} for user {$current_user_id} revision");
                } else {
                    error_log("TCross: Failed to archive portfolio {$submission->portfolio_id}: " . $portfolio_update_result->get_error_message());
                }
            }
            
            // 更新用戶 meta 狀態
            update_user_meta($current_user_id, 'tcross_last_archived_submission_id', $submission->id);
            update_user_meta($current_user_id, 'tcross_application_status', 'revision_pending');
            
            error_log("TCross: Archived submission {$submission->id} (status: {$submission->submission_status}) for user {$current_user_id} revision");
        }
        
        // 如果有下架的提交，記錄總數
        if (count($active_submissions) > 0) {
            update_user_meta($current_user_id, 'tcross_archived_submissions_count', count($active_submissions));
            error_log("TCross: Successfully archived " . count($active_submissions) . " submissions for user {$current_user_id}");
            return true;
        } else {
            error_log("TCross: 沒有找到需要下架的記錄");
            return false;
        }
    }

    /**
     * 批准提交
     */
    public function approveSubmission($submission_id) {
        $submission = TCrossUserTable::get_form_submission($submission_id);

        if (!$submission) {
            error_log('TCross: Submission not found: ' . $submission_id);
            return false;
        }

        $user_id = $submission->submitted_by_user_id;
        if (!$user_id) {
            error_log('TCross: No user ID associated with submission: ' . $submission_id);
            return false;
        }

        // 更新用戶申請狀態為已通過
        update_user_meta($user_id, 'tcross_application_status', 'approved');

        // 根據用戶類型創建 Portfolio
        $portfolio_service = new TCrossPortfolioService();

        if ($submission->user_type === 'green_teacher') {
            $portfolio_id = $portfolio_service->createGreenTeacherPortfolio($submission);
        } elseif ($submission->user_type === 'demand_unit') {
            $portfolio_id = $portfolio_service->createDemandUnitPortfolio($submission);
        }

        if ($portfolio_id) {
            update_user_meta($user_id, 'tcross_portfolio_id', $portfolio_id);
            error_log("TCross: Created portfolio {$portfolio_id} for user {$user_id}");
        }

        // 發送通過通知郵件
        $this->sendApprovalEmail($user_id, $submission->user_type);

        error_log("TCross: Approved application for user {$user_id}");
        return true;
    }

    /**
     * 拒絕提交
     */
    public function rejectSubmission($submission_id) {
        $submission = TCrossUserTable::get_form_submission($submission_id);

        if (!$submission) {
            error_log('TCross: Submission not found: ' . $submission_id);
            return false;
        }

        $user_id = $submission->submitted_by_user_id;
        if (!$user_id) {
            error_log('TCross: No user ID associated with submission: ' . $submission_id);
            return false;
        }

        // 更新用戶申請狀態為已拒絕
        update_user_meta($user_id, 'tcross_application_status', 'rejected');

        // 發送拒絕通知郵件
        $this->sendRejectionEmail($user_id, $submission->user_type);

        error_log("TCross: Rejected application for user {$user_id}");
        return true;
    }

    /**
     * 發送審核通過郵件
     */
    private function sendApprovalEmail($user_id, $user_type) {
        $user = get_user_by('id', $user_id);
        if (!$user) return;

        $type_names = array(
            'green_teacher' => '綠照師',
            'demand_unit' => '需求單位'
        );

        $type_name = $type_names[$user_type] ?? $user_type;
        $subject = "申請審核通過 - TCross {$type_name}";

        $message = "親愛的 {$user->display_name}，\n\n";
        $message .= "恭喜您！您的{$type_name}申請已經審核通過。\n\n";
        $message .= "您現在可以使用您的帳號登入系統，開始使用所有功能。\n\n";
        $message .= "登入網址：" . wp_login_url() . "\n\n";
        $message .= "如有任何問題，請隨時與我們聯繫。\n\n";
        $message .= "TCross 團隊";

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * 發送申請拒絕郵件
     */
    private function sendRejectionEmail($user_id, $user_type) {
        $user = get_user_by('id', $user_id);
        if (!$user) return;

        $type_names = array(
            'green_teacher' => '綠照師',
            'demand_unit' => '需求單位'
        );

        $type_name = $type_names[$user_type] ?? $user_type;
        $subject = "申請審核結果 - TCross {$type_name}";

        $message = "親愛的 {$user->display_name}，\n\n";
        $message .= "很遺憾，您的{$type_name}申請未能通過審核。\n\n";
        $message .= "您可以重新提交申請，或聯繫我們了解更多詳情。\n\n";
        $message .= "如有任何問題，請隨時與我們聯繫。\n\n";
        $message .= "TCross 團隊";

        wp_mail($user->user_email, $subject, $message);
    }
}

/**
 * Portfolio 管理服務類
 * 專門處理 Portfolio 創建和管理
 */
class TCrossPortfolioService {
    
    /**
     * 當前處理的 submission 記錄
     */
    private $current_submission;

    /**
     * 創建綠照師 Portfolio
     */
    public function createGreenTeacherPortfolio($submission) {
        if (!$submission || !$submission->form_data) {
            error_log('TCross: No submission data for portfolio creation');
            return false;
        }

        // 設置當前處理的 submission
        $this->current_submission = $submission;
        $form_data = $submission->form_data;
        
        // 獲取作者姓名
        $author_name = '';
        if (isset($form_data['name']['value']) && !empty($form_data['name']['value'])) {
            $author_name = $form_data['name']['value'];
        } else {
            // 如果表單中沒有姓名，嘗試從用戶資料獲取
            $user = get_user_by('id', $submission->submitted_by_user_id);
            if ($user) {
                $author_name = $user->display_name ?: $user->user_login;
            } else {
                $author_name = '綠照師 #' . ($submission->submitted_by_user_id ?: get_current_user_id());
            }
        }

        $post_data = array(
            'post_title' => $author_name,  // 使用作者姓名作為標題
            'post_content' => $this->generateGreenTeacherContent($form_data),
            'post_status' => 'publish',
            'post_type' => 'portfolio',
            'post_author' => $submission->submitted_by_user_id ?? 1,
            'meta_input' => $this->prepareGreenTeacherMeta($form_data)
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id) || !$post_id) {
            error_log('TCross: Failed to create green teacher portfolio: ' .
                (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'));
            return false;
        }

        // 設置分類和標籤
        $this->setPortfolioTerms($post_id, $form_data, 'green_teacher');

        // 處理精選圖片
        if (!empty($form_data['field_dc61789']['value'])) {
            $this->setFeaturedImage($post_id, $form_data['field_dc61789']['value']);
        }

        error_log("TCross: Successfully created green teacher portfolio {$post_id} with title: {$author_name}");
        return $post_id;
    }

    /**
     * 創建需求單位 Portfolio
     */
    public function createDemandUnitPortfolio($submission) {
        if (!$submission || !$submission->form_data) {
            error_log('TCross: No submission data for demand unit portfolio creation');
            return false;
        }

        // 設置當前處理的 submission
        $this->current_submission = $submission;
        $form_data = $submission->form_data;
        
        // 獲取單位名稱
        $unit_name = '';
        if (isset($form_data['name']['value']) && !empty($form_data['name']['value'])) {
            $unit_name = $form_data['name']['value'];
        } else {
            // 如果表單中沒有單位名稱，嘗試從用戶資料獲取
            $user = get_user_by('id', $submission->submitted_by_user_id);
            if ($user) {
                $unit_name = $user->display_name ?: $user->user_login;
            } else {
                $unit_name = '需求單位 #' . ($submission->submitted_by_user_id ?: get_current_user_id());
            }
        }

        $post_data = array(
            'post_title' => $unit_name,  // 使用單位名稱作為標題
            'post_content' => $this->generateDemandUnitContent($form_data),
            'post_status' => 'publish',
            'post_type' => 'portfolio',
            'post_author' => $submission->submitted_by_user_id ?? 1,
            'meta_input' => $this->prepareDemandUnitMeta($form_data)
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id) || !$post_id) {
            error_log('TCross: Failed to create demand unit portfolio: ' .
                (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'));
            return false;
        }

        $this->setPortfolioTerms($post_id, $form_data, 'demand_unit');

                // 處理精選圖片
        if (!empty($form_data['field_dc61789']['value'])) {
            $this->setFeaturedImage($post_id, $form_data['field_dc61789']['value']);
        }

        error_log("TCross: Successfully created demand unit portfolio {$post_id} with title: {$unit_name}");
        return $post_id;
    }

    /**
     * 生成綠照師 Portfolio 內容
     */
    private function generateGreenTeacherContent($form_data) {
        // 使用原有的內容生成邏輯，但進行重構和優化
        $content = $this->generatePortfolioLayout($form_data, 'green_teacher');
        $content .= $this->addReviewsSection('green_teacher');
        $content .= $this->addResponsiveCSS('green_teacher');

        return $content;
    }

    /**
     * 生成需求單位 Portfolio 內容
     */
    private function generateDemandUnitContent($form_data) {
        $content = $this->generatePortfolioLayout($form_data, 'demand_unit');
        $content .= $this->addReviewsSection('demand_unit');
        $content .= $this->addResponsiveCSS('demand_unit');

        return $content;
    }

    /**
     * 生成 Portfolio 佈局
     */
    private function generatePortfolioLayout($form_data, $type) {
        if ($type === 'green_teacher') {
            return $this->generateGreenTeacherLayout($form_data);
        } else {
            return $this->generateDemandUnitLayout($form_data);
        }
    }

    /**
     * 生成綠照師佈局 - 修改版
     */
  private function generateGreenTeacherLayout($form_data) {
    // 外層容器：設定最大寬度 1200px
    $layout = '<div class="tcross-portfolio-wrapper" style="max-width: 1200px !important; width: 100% !important; margin: 0 auto !important; box-sizing: border-box !important;">';

    // 主容器：左右兩欄布局
    $layout .= '<div class="elementor-element elementor-element-81b10e8" data-id="81b10e8" data-element_type="container" style="width: 100% !important; padding: 50px !important; clear: both; margin: 0 auto; box-sizing: border-box; background: white !important; border-radius: 12px !important; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15) !important; display: flex !important; gap: 30px !important;">';

    // 左邊整個區塊：包含圖片和info
    $layout .= '<div class="left-column" style="flex: 0 0 350px !important; display: flex !important; flex-direction: column !important; gap: 30px !important;">';

    // 左上：頭像區塊
    $layout .= $this->generateImageSection($form_data);

    // 左下：info區域
    $layout .= '<div id="info" class="info-placeholder" style="flex: 1 !important; max-width: 350px !important; min-height: 386px !important; background: white !important; display: flex !important; align-items: center !important; justify-content: center !important; color: #6c757d !important;">';
    $layout .= '<span></span>';
    $layout .= '</div>';

    $layout .= '</div>'; // 結束左邊區塊

    // 右邊區塊：資料表格
    $layout .= '<div class="right-column" style="flex: 1 !important; min-width: 0 !important;">';
    $layout .= $this->generateDataSection($form_data, 'green_teacher', $this->current_submission);
    $layout .= '</div>';

    $layout .= '</div>'; // 結束主容器

    $layout .= '</div>'; // 結束外層容器

    return $layout;
}
    /**
     * 生成需求單位佈局
     */
    private function generateDemandUnitLayout($form_data) {
        // 外層容器：設定最大寬度 800px
        $layout = '<div class="tcross-portfolio-wrapper" style="max-width: 800px !important; width: 100% !important; margin: 0 auto !important; box-sizing: border-box !important;">';
        
        $layout .= '<div class="elementor-element elementor-element-81b10e8 e-con-full e-flex e-con e-parent" data-id="81b10e8" data-element_type="container" style="width: 100% !important; padding: 50px !important; clear: both; margin: 0 auto; box-sizing: border-box;">';

        $layout .= $this->generateDataSection($form_data, 'demand_unit', $this->current_submission);

        $layout .= '</div>';
        
        $layout .= '</div>'; // 結束外層容器

        return $layout;
    }

    /**
     * 生成圖片區塊 - 修改版
     */
    private function generateImageSection($form_data) {
        $image_src = $form_data['field_dc61789']['value'] ?? 'https://tcross.wpsite.tw/wp-content/uploads/2025/07/29854249_33y_85n6snp0dlgnichibsoj0-轉換-03-scaled-1.png';

        return '<div class="elementor-element elementor-element-72a0377 e-con-full e-flex e-con e-child image-section" data-id="72a0377" data-element_type="container" style="flex: 0 0 288px !important; max-width: 288px !important;">
                <div class="elementor-element elementor-element-41ce569 elementor-widget elementor-widget-theme-post-featured-image elementor-widget-image" data-id="41ce569" data-element_type="widget" id="field_dc61789" data-widget_type="theme-post-featured-image.default">
                <img decoding="async" width="350" height="386" src="' . esc_url($image_src) . '" class="attachment-large size-large wp-image-697" alt="師資照片" style="width: 288px !important; height: 386px !important; object-fit: cover; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); display: block !important;">
                </div></div>';
    }

    /**
     * 生成數據區塊 - 修改版（移除flex樣式，但保持原本寬度）
     */
    private function generateDataSection($form_data, $type, $submission = null) {
        $fields = $this->getFieldsForType($type);
        
        // 保持原本的寬度，不要全寬
        $data_section = '<div class="elementor-element elementor-element-f8557a3 e-con-full e-flex e-con e-child" data-id="f8557a3" data-element_type="container" style="max-width: 700px !important;">';
        $data_section .= '<div class="portfolio-data-table" style="display: grid; grid-template-columns: 150px 1fr; gap: 15px 20px; align-items: start;">';

        foreach ($fields as $field_key => $field_config) {
            $value = $this->getFieldValue($form_data, $field_config['source'], $field_config['default'] ?? '', $submission);
            $data_section .= $this->generateDataRow($field_config['label'], $value, $field_key);
        }

        $data_section .= '</div></div>';

        return $data_section;
    }

    /**
     * 獲取不同類型的欄位配置
     */
    private function getFieldsForType($type) {
        if ($type === 'green_teacher') {
            return array(
                'submission_id' => array('label' => '師資編號', 'source' => 'user_id'),
                'name' => array('label' => '姓名', 'source' => 'name'),
                'gender' => array('label' => '性別', 'source' => 'field_781bfc9'),
                'education' => array('label' => '學經歷', 'source' => 'field_d55c6b4'),
//                'contact' => array('label' => '聯繫方式', 'source' => 'contact_combined'),
                'license' => array('label' => '證照', 'source' => 'license_combined'),
                'expertise' => array('label' => '綠色活動專長', 'source' => 'expertise_combined'),
                'target_group' => array('label' => '可帶領族群', 'source' => 'target_combined'),
                'training' => array('label' => '進修訓練', 'source' => 'training_combined'),
                'service_area' => array('label' => '可服務區域', 'source' => 'area_combined'),
                'cooperation' => array('label' => '合作方式', 'source' => 'field_3877e1a'),
                'service_status' => array('label' => '近一年服務單位狀況', 'source' => 'field_4c044af'),
                'video_link' => array('label' => '影片連結', 'source' => 'field_7eb67a1'),
                'course_link' => array('label' => '課程連結', 'source' => 'field_1a8e4b7'),
                'language' => array('label' => '語言能力', 'source' => 'field_97b2536'),
                'other_language' => array('label' => '其他語言能力', 'source' => 'field_3183410'),
            );
        } else {
            return array(
                'unit_id' => array('label' => '綠照需求單位編號', 'source' => 'user_id'),
                'unit_name' => array('label' => '申請單位名稱', 'source' => 'name'),
                'contact_person' => array('label' => '聯絡人姓名', 'source' => 'field_8ca1f60'),
                'position' => array('label' => '職稱', 'source' => 'field_e6c4bc3'),
                'phone' => array('label' => '聯絡電話', 'source' => 'field_3183411'),
                'email' => array('label' => '電子郵件', 'source' => 'email'),
                'line_id' => array('label' => 'LINE ID', 'source' => 'field_ae2b1cc'),
                'service_area' => array('label' => '可服務區域', 'source' => 'field_410e99d'),
                'address' => array('label' => '地址', 'source' => 'field_bb29f05'),
                'class_time' => array('label' => '預定上課時間', 'source' => 'field_9958b1c'),
                'time_period' => array('label' => '預定上課時間段', 'source' => 'field_0fdcf08'),
                'time_detail' => array('label' => '時間詳細說明', 'source' => 'field_f80259e'),
                'target_type' => array('label' => '對象類型', 'source' => 'field_b3308c1'),
                'target_detail' => array('label' => '對象詳細說明', 'source' => 'field_7502fc4'),
                'participant_count' => array('label' => '參加人數', 'source' => 'field_a0dea9a'),
                'target_description' => array('label' => '對象說明', 'source' => 'field_4a3ee18'),
                'expected_goal' => array('label' => '預期目標', 'source' => 'field_7293927'),
                'course_type' => array('label' => '課程類型', 'source' => 'field_d4920bd'),
                'resources' => array('label' => '可提供配合資源', 'source' => 'field_6435ede'),
                'fee_range' => array('label' => '可支應之講師費範圍', 'source' => 'field_3749a9c'),
                'material_fee' => array('label' => '材料費、交通補助款', 'source' => 'field_bb4ebd2'),
                'payment_method' => array('label' => '請款方式', 'source' => 'field_37ccf39'),
                'previous_cooperation' => array('label' => '是否已合作過綠照師', 'source' => 'field_d411003'),
                'multiple_teachers' => array('label' => '是否開放媒合複數師資選擇', 'source' => 'field_164d567'),
                'accept_recommendation' => array('label' => '是否願意接受平台推薦之師資', 'source' => 'field_05e2d38'),
                'language' => array('label' => '語言能力', 'source' => 'field_97b2536'),
                'other_language' => array('label' => '其他語言能力', 'source' => 'field_3183410'),
            );
        }
    }

    /**
     * 獲取欄位值
     */
    private function getFieldValue($form_data, $source, $default = '', $submission = null) {
        switch ($source) {
            case 'user_id':
                // 優先使用提交記錄中的用戶 ID，否則使用當前用戶 ID
                if ($submission && isset($submission->submitted_by_user_id)) {
                    return $submission->submitted_by_user_id;
                }
                return get_current_user_id();
            case 'contact_combined':
                return $this->getCombinedContact($form_data);
            case 'license_combined':
                return $this->getCombinedLicense($form_data);
            case 'expertise_combined':
                return $this->getCombinedExpertise($form_data);
            case 'target_combined':
                return $this->getCombinedTarget($form_data);
            case 'training_combined':
                return $this->getCombinedTraining($form_data);
            case 'area_combined':
                return $this->getCombinedArea($form_data);
            default:
                return $form_data[$source]['value'] ?? $default;
        }
    }

    /**
     * 組合聯繫方式
     */
    private function getCombinedContact($form_data) {
        $contact_info = '';
        if (!empty($form_data['field_bb29f05']['value'])) {
            $contact_info .= '電話：' . $form_data['field_bb29f05']['value'];
        }
        if (!empty($form_data['field_ae2b1cc']['value'])) {
            if (!empty($contact_info)) $contact_info .= '<br>';
            $contact_info .= 'LINE ID：' . $form_data['field_ae2b1cc']['value'];
        }
        if (!empty($form_data['email']['value'])) {
            if (!empty($contact_info)) $contact_info .= '<br>';
            $contact_info .= 'Email：' . $form_data['email']['value'];
        }
        return $contact_info ?: '請在這裡輸入標題';
    }

    // 其他組合方法可以類似實現...

    /**
     * 生成數據行
     */
    private function generateDataRow($label, $value, $field_id) {
        return '<div class="data-label" style="font-weight: bold; color: #2c5530; padding: 8px 0;">' . esc_html($label) . '</div>' .
               '<div class="data-value" id="' . esc_attr($field_id) . '" style="padding: 8px 0; border-bottom: 1px solid #e5e5e5;">' . $value . '</div>';
    }

    /**
     * 添加評價區塊
     */
    private function addReviewsSection($type) {
        return '<div style="max-width: 800px; width: 100%; margin: 30px auto; padding: 0 50px; box-sizing: border-box;">' .
               '[vp_universal_reviews type="' . $type . '"]' .
               '</div>';
    }

    /**
     * 添加響應式 CSS - 修改版（保持原本寬度）
     */
private function addResponsiveCSS($type) {
    return '<style>
    .entry-title, .elementor-heading-title, h1.entry-title {
        text-align: center !important;
        margin-bottom: 30px !important;
    }
    
    /* 主容器 - 左右布局 */
    .elementor-element-81b10e8 {
        max-width: 1200px !important;
        width: 100% !important;
        padding: 50px !important;
        clear: both !important;
        margin: 0 auto !important;
        box-sizing: border-box !important;
        background: white !important;
        border-radius: 12px !important;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15) !important;
        position: relative !important;
        display: flex !important;
        gap: 30px !important;
        align-items: flex-start !important;
    }
    
    /* 左邊欄位樣式 */
    .left-column {
        flex: 0 0 350px !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 30px !important;
    }
    
    /* 右邊欄位樣式 */
    .right-column {
        flex: 1 !important;
        min-width: 0 !important;
    }
    
    /* 圖片區塊樣式 */
    .image-section {
        flex: 0 0 288px !important;
        max-width: 288px !important;
        width: 100% !important;
    }
    
    .image-section img {
        width: 100% !important;
        height: 386px !important;
        object-fit: cover !important;
        border-radius: 8px !important;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
        display: block !important;
    }
    
    /* Info 區域樣式 */
    .info-placeholder {
        flex: 1 !important;
        max-width: 350px !important;
        min-height: 386px !important;
        background-color: #f8f9fa !important;
        border: 2px dashed #dee2e6 !important;
        border-radius: 8px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #6c757d !important;
        font-style: italic !important;
        font-size: 14px !important;
    }
    
    /* 資料表格樣式 */
    .portfolio-data-table {
        display: grid !important;
        grid-template-columns: 150px 1fr !important;
        gap: 15px 20px !important;
        align-items: start !important;
    }
    
    .data-label {
        font-weight: bold !important;
        color: #2c5530 !important;
        padding: 8px 0 !important;
    }
    
    .data-value {
        padding: 8px 0 !important;
        border-bottom: 1px solid #e5e5e5 !important;
    }
    
    /* 平板響應式 */
    @media (max-width: 1024px) {
        .elementor-element-81b10e8 {
            max-width: 95% !important;
            padding: 40px !important;
            gap: 25px !important;
        }
        
        .left-column {
            flex: 0 0 300px !important;
        }
        
        .image-section {
            flex: 0 0 250px !important;
            max-width: 250px !important;
        }
        
        .image-section img {
            height: 330px !important;
        }
        
        .info-placeholder {
            min-height: 330px !important;
        }
    }
    
    /* 手機響應式 - 改為上下布局 */
    @media (max-width: 768px) {
        .elementor-element-81b10e8 {
            flex-direction: column !important;
            padding: 30px !important;
            gap: 20px !important;
        }
        
        .left-column {
            flex: none !important;
            width: 100% !important;
            gap: 20px !important;
        }
        
        .right-column {
            width: 100% !important;
        }
        
        .image-section {
            flex: none !important;
            max-width: 100% !important;
            display: flex !important;
            justify-content: center !important;
        }
        
        .image-section img {
            width: 250px !important;
            height: 330px !important;
        }
        
        .info-placeholder {
            min-height: 200px !important;
        }
        
        .portfolio-data-table {
            grid-template-columns: 120px 1fr !important;
            gap: 10px 15px !important;
        }
    }
    
    /* 小螢幕手機 */
    @media (max-width: 480px) {
        .elementor-element-81b10e8 {
            padding: 20px !important;
        }
        
        .image-section img {
            width: 200px !important;
            height: 266px !important;
        }
        
        .info-placeholder {
            min-height: 150px !important;
            font-size: 12px !important;
        }
        
        .portfolio-data-table {
            grid-template-columns: 100px 1fr !important;
            gap: 8px 12px !important;
        }
        
        .data-label, .data-value {
            font-size: 13px !important;
        }
    }
    </style>';
}

    /**
     * 準備綠照師 Meta 數據
     */
    private function prepareGreenTeacherMeta($form_data) {
        return array_merge($this->getBasicMeta($form_data), array(
            'vp_portfolio_type' => 'green_teacher',
            'vp_portfolio_status' => 'active'
        ));
    }

    /**
     * 準備需求單位 Meta 數據
     */
    private function prepareDemandUnitMeta($form_data) {
        return array_merge($this->getBasicMeta($form_data), array(
            'vp_portfolio_type' => 'demand_unit',
            'vp_portfolio_status' => 'active'
        ));
    }

    /**
     * 獲取基本 Meta 數據
     */
    private function getBasicMeta($form_data) {
        $meta = array();

        foreach ($form_data as $field_id => $field_data) {
            if (isset($field_data['value'])) {
                $meta[$field_id] = $field_data['value'];
            }
        }

        return $meta;
    }

    /**
     * 設置 Portfolio 分類和標籤
     */
    private function setPortfolioTerms($post_id, $form_data, $type) {
        if ($type === 'green_teacher') {
            wp_set_object_terms($post_id, array(29), 'portfolio_category');
        } else {
            wp_set_object_terms($post_id, array(30), 'portfolio_category');
        }

        error_log("TCross: Set portfolio category for {$type} post {$post_id}");
    }

    /**
     * 設置精選圖片
     */
    private function setFeaturedImage($post_id, $file_url) {
        if (empty($file_url) || empty($post_id)) {
            error_log("TCross: Missing file_url or post_id");
            return false;
        }

        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);

        if (!file_exists($file_path)) {
            error_log("TCross: Image file not found at: {$file_path}");
            return false;
        }

        $filetype = wp_check_filetype(basename($file_path), null);
        if (!$filetype['type']) {
            error_log("TCross: Invalid file type for: {$file_path}");
            return false;
        }

        $attachment = array(
            'guid'           => $file_url,
            'post_mime_type' => $filetype['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attachment_id = wp_insert_attachment($attachment, $file_path);

        if (is_wp_error($attachment_id)) {
            error_log("TCross: Failed to create attachment: " . $attachment_id->get_error_message());
            return false;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        $result = set_post_thumbnail($post_id, $attachment_id);

        if ($result) {
            error_log("TCross: Successfully set featured image {$attachment_id} for portfolio {$post_id}");
            return $attachment_id;
        } else {
            error_log("TCross: Failed to set featured image for portfolio {$post_id}");
            return false;
        }
    }

    /**
     * 獲取組合證照資訊
     */
    private function getCombinedLicense($form_data) {
        $license_info = $form_data['field_84fd647']['value'] ?? '';
        if (!empty($form_data['field_55a66b7']['value'])) {
            if (!empty($license_info)) $license_info .= '<br>';
            $license_info .= $form_data['field_55a66b7']['value'];
        }
        return $license_info ?: '請在這裡輸入標題';
    }

    /**
     * 獲取組合專長資訊
     */
    private function getCombinedExpertise($form_data) {
        $expertise = $form_data['field_9958b1c']['value'] ?? '';
        if (!empty($form_data['field_f80259e']['value'])) {
            if (!empty($expertise)) $expertise .= '<br>';
            $expertise .= $form_data['field_f80259e']['value'];
        }
        return $expertise ?: '請在這裡輸入標題';
    }

    /**
     * 獲取組合目標族群資訊
     */
    private function getCombinedTarget($form_data) {
        $target_group = $form_data['field_b3308c1']['value'] ?? '';
        if (!empty($form_data['field_7502fc4']['value'])) {
            if (!empty($target_group)) $target_group .= '<br>';
            $target_group .= $form_data['field_7502fc4']['value'];
        }
        return $target_group ?: '請在這裡輸入標題';
    }

    /**
     * 獲取組合訓練資訊
     */
    private function getCombinedTraining($form_data) {
        $training = $form_data['field_d4920bd']['value'] ?? '';
        if (!empty($form_data['field_3749a9b']['value'])) {
            if (!empty($training)) $training .= '<br>';
            $training .= $form_data['field_3749a9b']['value'];
        }
        return $training ?: '請在這裡輸入標題';
    }

    /**
     * 獲取組合服務區域資訊
     */
    private function getCombinedArea($form_data) {
        $service_area = $form_data['field_410e99d']['value'] ?? '';
        if (!empty($form_data['field_712b0a0']['value'])) {
            if (!empty($service_area)) $service_area .= '<br>';
            $service_area .= $form_data['field_712b0a0']['value'];
        }
        return $service_area ?: '請在這裡輸入標題';
    }
}

/**
 * 通知服務類
 * 處理所有郵件通知
 */
class TCrossNotificationService {

    /**
     * 發送管理員通知郵件
     */
    public function sendAdminNotification($submission_id, $user_type, $form_data, $elementor_submission = null) {
        $admin_email = get_option('admin_email');

        $type_names = array(
            'green_teacher' => '綠照師',
            'demand_unit' => '需求單位'
        );

        $type_name = $type_names[$user_type] ?? $user_type;
        $subject = "新的{$type_name}申請 - TCross";

        $message = "您收到一個新的{$type_name}申請：\n\n";
        $message .= "申請編號：{$submission_id}\n";
        $message .= "申請類型：{$type_name}\n";

        if ($elementor_submission) {
            $message .= "提交時間：" . $elementor_submission->created_at . "\n";
            if ($elementor_submission->user_id) {
                $user = get_user_by('id', $elementor_submission->user_id);
                if ($user) {
                    $message .= "提交用戶：{$user->display_name} ({$user->user_email})\n";
                }
            } else {
                $message .= "提交用戶：訪客\n";
            }
        }

        $message .= "\n";

        // 添加基本資訊
        if (isset($form_data['name'])) {
            $message .= "申請人姓名：" . $form_data['name']['value'] . "\n";
        }
        if (isset($form_data['email'])) {
            $message .= "電子郵件：" . $form_data['email']['value'] . "\n";
        }

        $message .= "\n請登入後台查看完整申請資料並進行審核。\n";
        $message .= admin_url('admin.php?page=tcross-user-manager');

        wp_mail($admin_email, $subject, $message);
    }
}

/**
 * 驗證服務類
 * 統一處理各種驗證邏輯
 */
class TCrossValidationService {

    /**
     * 驗證用戶註冊資料
     */
    public function validateRegistrationData($data) {
        $errors = array();

        // 驗證必填欄位
        $required_fields = array('user_type', 'username', 'email', 'password');
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $errors[] = "請填寫所有必填欄位：{$field}";
            }
        }

        // 驗證用戶類型
        if (!empty($data['user_type']) && !in_array($data['user_type'], array('demand_unit', 'green_teacher'))) {
            $errors[] = '無效的用戶類型';
        }

        // 驗證密碼確認
        if (!empty($data['password']) && !empty($data['confirm_password']) && $data['password'] !== $data['confirm_password']) {
            $errors[] = '密碼確認不一致';
        }

        // 檢查用戶名是否已存在
        if (!empty($data['username']) && username_exists($data['username'])) {
            $errors[] = '用戶名已存在';
        }

        // 檢查電子郵件是否已存在
        if (!empty($data['email']) && email_exists($data['email'])) {
            $errors[] = '電子郵件已被註冊';
        }

        return $errors;
    }

    /**
     * 驗證狀態更新資料
     */
    public function validateStatusUpdate($data, $allowed_statuses) {
        $errors = array();

        if (empty($data['status'])) {
            $errors[] = '狀態不能為空';
        } elseif (!in_array($data['status'], $allowed_statuses)) {
            $errors[] = '無效的狀態值';
        }

        return $errors;
    }
}

/**
 * 快取服務類
 * 處理資料快取以提升效能
 */
class TCrossCacheService {

    private static $cache = array();

    /**
     * 獲取快取資料
     */
    public static function get($key) {
        return self::$cache[$key] ?? null;
    }

    /**
     * 設置快取資料
     */
    public static function set($key, $value, $ttl = 3600) {
        self::$cache[$key] = array(
            'value' => $value,
            'expires' => time() + $ttl
        );
    }

    /**
     * 檢查快取是否過期
     */
    public static function isExpired($key) {
        if (!isset(self::$cache[$key])) {
            return true;
        }

        return time() > self::$cache[$key]['expires'];
    }

    /**
     * 清除快取
     */
    public static function clear($key = null) {
        if ($key === null) {
            self::$cache = array();
        } else {
            unset(self::$cache[$key]);
        }
    }

    /**
     * 獲取用戶統計資料（帶快取）
     */
    public static function getUserStats() {
        $cache_key = 'tcross_user_stats';

        if (!self::isExpired($cache_key)) {
            $cached = self::get($cache_key);
            if ($cached !== null) {
                return $cached['value'];
            }
        }

        $stats = TCrossUserTable::get_user_type_stats();
        self::set($cache_key, $stats, 300); // 快取 5 分鐘

        return $stats;
    }
}

/**
 * 工具類
 * 提供各種實用方法
 */
class TCrossUtils {

    /**
     * 格式化檔案大小
     */
    public static function formatFileSize($bytes) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 生成唯一 ID
     */
    public static function generateUniqueId($prefix = '') {
        return $prefix . uniqid() . '_' . wp_rand(1000, 9999);
    }

    /**
     * 清理 HTML 內容
     */
    public static function sanitizeHtmlContent($content) {
        $allowed_tags = array(
            'a' => array('href' => array(), 'title' => array(), 'target' => array()),
            'br' => array(),
            'strong' => array(),
            'em' => array(),
            'p' => array(),
            'div' => array('class' => array(), 'style' => array()),
            'span' => array('class' => array(), 'style' => array()),
            'img' => array('src' => array(), 'alt' => array(), 'width' => array(), 'height' => array())
        );

        return wp_kses($content, $allowed_tags);
    }

    /**
     * 記錄錯誤到日誌
     */
    public static function logError($message, $context = array()) {
        $log_message = '[TCross] ' . $message;
        if (!empty($context)) {
            $log_message .= ' Context: ' . json_encode($context);
        }
        error_log($log_message);
    }

    /**
     * 檢查是否為有效的電子郵件
     */
    public static function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * 生成安全的隨機字串
     */
    public static function generateSecureRandomString($length = 32) {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        } else {
            return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $length)), 0, $length);
        }
    }
}

// 初始化 API
new TCrossUserAPI();
?>