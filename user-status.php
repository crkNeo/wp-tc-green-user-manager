<?php
/**
 * TCross User Manager - User Status Management
 * 方案一：完全基於提交記錄的狀態管理
 */

if (!defined('ABSPATH')) {
    exit;
}

class TCrossUserStatus {

    /**
     * 獲取綠照師當前生效的提交
     */
    public static function getGreenTeacherActiveSubmission($user_id) {
        global $wpdb;
        
        $elementor_table = $wpdb->prefix . 'e_submissions';
        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, t.submission_status, t.submission_type, t.is_current_active, 
                    t.portfolio_id, t.portfolio_status, t.admin_notes
             FROM $elementor_table e
             LEFT JOIN $tcross_table t ON e.id = t.elementor_submission_id
             WHERE e.user_id = %d 
             AND e.post_id = 1254
             AND COALESCE(t.submission_status, 'pending') = 'approved'
             AND COALESCE(t.is_current_active, 1) = 1
             ORDER BY e.created_at DESC LIMIT 1",
            $user_id
        ));
    }

    /**
     * 獲取需求單位的所有提交
     */
    public static function getDemandUnitSubmissions($user_id, $limit = 10) {
        global $wpdb;
        
        $elementor_table = $wpdb->prefix . 'e_submissions';
        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, t.submission_status, t.submission_type, t.portfolio_id, 
                    t.portfolio_status, t.admin_notes
             FROM $elementor_table e
             LEFT JOIN $tcross_table t ON e.id = t.elementor_submission_id
             WHERE e.user_id = %d 
             AND e.post_id = 1325
             ORDER BY e.created_at DESC LIMIT %d",
            $user_id, $limit
        ));
    }

    /**
     * 檢查用戶是否有待審核的提交
     */
    public static function hasPendingSubmission($user_id, $user_type) {
        global $wpdb;
        
        $elementor_table = $wpdb->prefix . 'e_submissions';
        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
        
        $post_id = ($user_type === 'green_teacher') ? 1254 : 1325;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $elementor_table e
             LEFT JOIN $tcross_table t ON e.id = t.elementor_submission_id
             WHERE e.user_id = %d 
             AND e.post_id = %d
             AND COALESCE(t.submission_status, 'pending') IN ('pending', 'under_review')",
            $user_id, $post_id
        ));

        return $count > 0;
    }

    /**
     * 獲取用戶的最新待審核提交
     */
    public static function getLatestPendingSubmission($user_id, $user_type) {
        global $wpdb;
        
        $elementor_table = $wpdb->prefix . 'e_submissions';
        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
        
        $post_id = ($user_type === 'green_teacher') ? 1254 : 1325;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, t.submission_status, t.submission_type, t.admin_notes
             FROM $elementor_table e
             LEFT JOIN $tcross_table t ON e.id = t.elementor_submission_id
             WHERE e.user_id = %d 
             AND e.post_id = %d
             AND COALESCE(t.submission_status, 'pending') IN ('pending', 'under_review')
             ORDER BY e.created_at DESC LIMIT 1",
            $user_id, $post_id
        ));
    }

    /**
     * 檢查用戶是否有被拒絕的提交
     */
    public static function hasRejectedSubmission($user_id, $user_type) {
        global $wpdb;
        
        $elementor_table = $wpdb->prefix . 'e_submissions';
        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
        
        $post_id = ($user_type === 'green_teacher') ? 1254 : 1325;

        // 只檢查最新的一筆被拒絕的申請（排除已有通過的申請）
        $latest_submission = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, t.submission_status
             FROM $elementor_table e
             LEFT JOIN $tcross_table t ON e.id = t.elementor_submission_id
             WHERE e.user_id = %d 
             AND e.post_id = %d
             ORDER BY e.created_at DESC LIMIT 1",
            $user_id, $post_id
        ));

        // 如果最新提交是被拒絕的，且沒有待審核的申請，則返回 true
        if ($latest_submission && 
            ($latest_submission->submission_status === 'rejected' || 
             (empty($latest_submission->submission_status) && strpos($latest_submission->status, 'rejected') !== false))) {
            
            // 附加檢查：确保沒有待審核的申請
            $has_pending = self::hasPendingSubmission($user_id, $user_type);
            return !$has_pending;
        }

        return false;
    }

    /**
     * 獲取用戶的最新被拒絕提交
     */
    public static function getLatestRejectedSubmission($user_id, $user_type) {
        global $wpdb;
        
        $elementor_table = $wpdb->prefix . 'e_submissions';
        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';
        
        $post_id = ($user_type === 'green_teacher') ? 1254 : 1325;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, t.submission_status, t.submission_type, t.admin_notes
             FROM $elementor_table e
             LEFT JOIN $tcross_table t ON e.id = t.elementor_submission_id
             WHERE e.user_id = %d 
             AND e.post_id = %d
             AND t.submission_status = 'rejected'
             ORDER BY e.created_at DESC LIMIT 1",
            $user_id, $post_id
        ));
    }

    /**
     * 檢查需求單位是否有任何通過的提交
     */
    public static function hasAnyApprovedSubmission($user_id) {
        global $wpdb;
        
        $elementor_table = $wpdb->prefix . 'e_submissions';
        $tcross_table = $wpdb->prefix . 'tcross_form_submissions';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $elementor_table e
             LEFT JOIN $tcross_table t ON e.id = t.elementor_submission_id
             WHERE e.user_id = %d 
             AND e.post_id = 1325
             AND COALESCE(t.submission_status, 'pending') = 'approved'",
            $user_id
        ));

        return $count > 0;
    }

    /**
     * 獲取用戶提交狀態摘要（用於前端按鈕控制）
     */
    public static function getUserSubmissionStatus($user_id, $user_type) {
        $status = array(
            'has_pending' => false,
            'has_active' => false,
            'has_any_approved' => false,
            'has_rejected' => false,
            'pending_submission' => null,
            'active_submission' => null,
            'rejected_submission' => null,
            'submission_type' => null
        );

        if ($user_type === 'green_teacher') {
            // 綠照師邏輯
            $status['has_pending'] = self::hasPendingSubmission($user_id, $user_type);
            $status['has_rejected'] = self::hasRejectedSubmission($user_id, $user_type);
            
            if ($status['has_pending']) {
                $pending = self::getLatestPendingSubmission($user_id, $user_type);
                $status['pending_submission'] = $pending;
                $status['submission_type'] = $pending->submission_type ?? 'initial';
            }
            
            if ($status['has_rejected']) {
                $rejected = self::getLatestRejectedSubmission($user_id, $user_type);
                $status['rejected_submission'] = $rejected;
            }

            $active = self::getGreenTeacherActiveSubmission($user_id);
            if ($active) {
                $status['has_active'] = true;
                $status['active_submission'] = $active;
                $status['active_submission_id'] = $active->id;
            }

        } elseif ($user_type === 'demand_unit') {
            // 需求單位邏輯
            $status['has_pending'] = self::hasPendingSubmission($user_id, $user_type);
            $status['has_any_approved'] = self::hasAnyApprovedSubmission($user_id);
            $status['has_rejected'] = self::hasRejectedSubmission($user_id, $user_type);
            
            if ($status['has_pending']) {
                $pending = self::getLatestPendingSubmission($user_id, $user_type);
                $status['pending_submission'] = $pending;
            }
            
            if ($status['has_rejected']) {
                $rejected = self::getLatestRejectedSubmission($user_id, $user_type);
                $status['rejected_submission'] = $rejected;
            }
        }

        return $status;
    }

    /**
     * 創建新的提交記錄
     */
    public static function createSubmissionRecord($elementor_submission_id, $user_type, $user_id, $submission_type = 'initial', $replaces_id = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tcross_form_submissions';

        // 如果是綠照師的修正提交，先將舊的設為非當前生效
        if ($user_type === 'green_teacher' && $submission_type === 'revision' && $replaces_id) {
            self::deactivateSubmission($replaces_id);
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'elementor_submission_id' => $elementor_submission_id,
                'user_type' => $user_type,
                'submission_status' => 'pending',
                'submitted_by_user_id' => $user_id,
                'submission_type' => $submission_type,
                'is_current_active' => ($user_type === 'green_teacher') ? 1 : 0, // 需求單位不需要 current_active
                'replaces_submission_id' => $replaces_id,
                'portfolio_status' => 'none'
            ),
            array('%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s')
        );

        if ($result) {
            error_log("TCross: Created submission record for Elementor submission {$elementor_submission_id}");
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * 將提交設為非當前生效（用於綠照師修正時）
     */
    public static function deactivateSubmission($elementor_submission_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tcross_form_submissions';

        $result = $wpdb->update(
            $table_name,
            array(
                'is_current_active' => 0,
                'portfolio_status' => 'archived'
            ),
            array('elementor_submission_id' => $elementor_submission_id),
            array('%d', '%s'),
            array('%d')
        );

        if ($result !== false) {
            error_log("TCross: Deactivated submission {$elementor_submission_id}");
            
            // 立即下架對應的 Portfolio
            self::archivePortfolio($elementor_submission_id);
        }

        return $result !== false;
    }

    /**
     * 下架 Portfolio
     */
    public static function archivePortfolio($elementor_submission_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tcross_form_submissions';

        // 獲取 portfolio_id
        $portfolio_id = $wpdb->get_var($wpdb->prepare(
            "SELECT portfolio_id FROM $table_name WHERE elementor_submission_id = %d",
            $elementor_submission_id
        ));

        if ($portfolio_id) {
            // 將 Portfolio 設為草稿狀態（下架）
            wp_update_post(array(
                'ID' => $portfolio_id,
                'post_status' => 'draft'
            ));

            error_log("TCross: Archived portfolio {$portfolio_id} for submission {$elementor_submission_id}");
        }
    }

    /**
     * 刪除 Portfolio
     */
    public static function deletePortfolio($elementor_submission_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tcross_form_submissions';

        // 獲取 portfolio_id
        $portfolio_id = $wpdb->get_var($wpdb->prepare(
            "SELECT portfolio_id FROM $table_name WHERE elementor_submission_id = %d",
            $elementor_submission_id
        ));

        if ($portfolio_id) {
            // 刪除 Portfolio
            wp_delete_post($portfolio_id, true);

            // 更新記錄
            $wpdb->update(
                $table_name,
                array(
                    'portfolio_id' => null,
                    'portfolio_status' => 'deleted'
                ),
                array('elementor_submission_id' => $elementor_submission_id),
                array('%d', '%s'),
                array('%d')
            );

            error_log("TCross: Deleted portfolio {$portfolio_id} for submission {$elementor_submission_id}");
        }
    }

    /**
     * 更新提交狀態
     */
    public static function updateSubmissionStatus($elementor_submission_id, $status, $portfolio_id = null, $admin_notes = '') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tcross_form_submissions';

        $update_data = array(
            'submission_status' => $status,
            'reviewed_at' => current_time('mysql'),
            'reviewed_by' => get_current_user_id()
        );

        if (!empty($admin_notes)) {
            $update_data['admin_notes'] = $admin_notes;
        }

        if ($portfolio_id) {
            $update_data['portfolio_id'] = $portfolio_id;
            $update_data['portfolio_status'] = 'active';
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('elementor_submission_id' => $elementor_submission_id),
            array_fill(0, count($update_data), '%s'),
            array('%d')
        );

        return $result !== false;
    }
}
?>