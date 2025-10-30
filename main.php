<?php
/**
 * Plugin Name: TCross User Manager
 * Description: 結合 WooCommerce 的雙類型用戶註冊系統，支援需求單位和綠照師註冊。
 * Version: 1.0.1
 * Author: TCross Team
 * Text Domain: tcross-user-manager
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * Network: false
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * WooCommerce requires at least: 5.0
 * WooCommerce tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class TCrossUserManager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
        $this->include_files();
    }
    
    private function init_hooks() {
        // 檢查 WooCommerce 是否存在
        add_action('admin_notices', array($this, 'check_woocommerce'));
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // WooCommerce 相關 hooks - 只在 WooCommerce 存在時加載
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_register_form_start', array($this, 'add_user_type_selection'));
            add_action('woocommerce_created_customer', array($this, 'save_user_type'));
            
            // 額外的 hooks 確保在不同情況下都能加載
            add_action('woocommerce_register_form', array($this, 'add_user_type_selection'));
            add_action('wp_footer', array($this, 'add_user_type_selection_fallback'));
        }
        
        // 自定義註冊表單處理
        add_action('wp_ajax_tcross_register_user', array($this, 'handle_custom_registration'));
        add_action('wp_ajax_nopriv_tcross_register_user', array($this, 'handle_custom_registration'));
        
        // 添加按鈕顯示控制
        add_action('wp_footer', array($this, 'add_button_visibility_script'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_button_control_script'));
        
        // 添加註冊確認視窗功能
        add_action('wp_enqueue_scripts', array($this, 'enqueue_registration_modal_assets'));
        add_action('wp_ajax_tcross_get_registration_notice', array($this, 'get_registration_notice'));
        add_action('wp_ajax_nopriv_tcross_get_registration_notice', array($this, 'get_registration_notice'));
        
        // 添加 Shortcode 支援
        add_shortcode('tcross_user_button', array($this, 'user_button_shortcode'));
        add_shortcode('tcross_conditional_content', array($this, 'conditional_content_shortcode'));
    }
    
    /**
     * 檢查 WooCommerce 是否存在
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p><strong>TCross User Manager:</strong> 需要先安裝並啟用 WooCommerce 外掛程式。</p></div>';
        }
    }
    
    private function include_files() {
        require_once plugin_dir_path(__FILE__) . 'table.php';
        require_once plugin_dir_path(__FILE__) . 'user-status.php';
        require_once plugin_dir_path(__FILE__) . 'api.php';
        require_once plugin_dir_path(__FILE__) . 'admin-page.php';
    }
    
    public function init() {
        // 創建資料庫表格
        TCrossUserTable::create_table();
        
        // 初始化 API 處理器
        new TCrossUserAPI();
    }
    
    public function enqueue_button_control_script() {
        // 在所有前台頁面載入腳本
        if (!is_admin()) {
            wp_enqueue_script('jquery');
            
            // 傳遞用戶類型資訊到前台
            $current_user_type = '';
            $application_status = 'none';
            
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $user_type_data = TCrossUserTable::get_user_type($user_id);
                if ($user_type_data) {
                    $current_user_type = $user_type_data->user_type;
                    
                    // 使用新的狀態管理系統
                    $status_data = TCrossUserStatus::getUserSubmissionStatus($user_id, $current_user_type);
                    
                    // 轉換為前端需要的格式
                    if ($status_data['has_pending']) {
                        $application_status = $status_data['submission_type'] === 'revision' ? 'revision' : 'pending';
                    } elseif ($status_data['has_active'] || $status_data['has_any_approved']) {
                        $application_status = 'approved';
                    } elseif ($status_data['has_rejected']) {
                        $application_status = 'rejected';
                    } else {
                        $application_status = 'none';
                    }
                }
            }
            
            // 傳遞 AJAX URL 和 nonce
            wp_localize_script('jquery', 'tcross_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('tcross_user_nonce'),
                'user_type' => $current_user_type,
                'application_status' => $application_status,
                'is_logged_in' => is_user_logged_in()
            ));
        }
    }
    
    /**
     * 添加按鈕顯示控制腳本
     */
    public function add_button_visibility_script() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // 等待 tcross_ajax 變數加載
            if (typeof tcross_ajax !== 'undefined') {
                var userType = tcross_ajax.user_type;
                var isLoggedIn = tcross_ajax.is_logged_in;
                var applicationStatus = tcross_ajax.application_status;
                
                // 調試信息
                console.log('TCross Debug - User Type:', userType);
                console.log('TCross Debug - Is Logged In:', isLoggedIn);
                console.log('TCross Debug - Application Status:', applicationStatus);
                
                // 控制按鈕顯示邏輯
                function controlButtonVisibility() {
                    var joinGreenTeacherBtn = $('#join-green-teacher');
                    var joinDemandUnitBtn = $('#join-demand-unit');
                    
                    if (!isLoggedIn) {
                        // 未登入用戶，隱藏所有按鈕，顯示登入提示
                        joinGreenTeacherBtn.hide();
                        joinDemandUnitBtn.hide();
                        
                        // 添加登入提示
                        if (!$('.tcross-login-notice').length) {
                            var loginNotice = '<div class="tcross-login-notice" style="background: #f0f8ff; border: 1px solid #0073aa; padding: 15px; margin: 10px 0; border-radius: 5px;">' +
                                '<p><strong>📝 歡迎加入綠照行列！</strong></p>' +
                                '<p>想提出綠照服務需求，或成為我們的綠照師夥伴嗎？<br>請先註冊或登入您的帳號，開始您的綠照之旅！</p>' +
                                '<p><a href="/my-account" class="button">立即註冊</a> <a href="/my-account" class="button button-primary">登入</a></p>' +
                                '</div>';
                            
                            if (joinGreenTeacherBtn.length) {
                                joinGreenTeacherBtn.parent().append(loginNotice);
                            } else if (joinDemandUnitBtn.length) {
                                joinDemandUnitBtn.parent().append(loginNotice);
                            }
                        }
                        return;
                    }
                    
                    // 移除登入提示
                    $('.tcross-login-notice').remove();
                    
                    // 已登入用戶，根據類型顯示對應按鈕
                    switch(userType) {
                        case 'green_teacher':
                            // 綠照師只顯示「加入綠照夥伴」按鈕
                            joinGreenTeacherBtn.show();
                            joinDemandUnitBtn.hide();
                            updateButtonStatus(joinGreenTeacherBtn, applicationStatus);
                            break;
                            
                        case 'demand_unit':
                            // 需求單位只顯示「加入綠照地圖」按鈕
                            joinGreenTeacherBtn.hide();
                            joinDemandUnitBtn.show();
                            updateButtonStatus(joinDemandUnitBtn, applicationStatus);
                            break;
                            
                        default:
                            // 未定義類型，隱藏按鈕並顯示提示
                            joinGreenTeacherBtn.hide();
                            joinDemandUnitBtn.hide();
                            
                            if (!$('.tcross-type-notice').length) {
                                var typeNotice = '<div class="tcross-type-notice" style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin: 10px 0; border-radius: 5px;">' +
                                    '<p><strong>請先完成用戶類型設定</strong></p>' +
                                    '<p>您的帳號尚未設定用戶類型，請聯繫管理員或重新註冊並選擇用戶類型。</p>' +
                                    '</div>';
                                
                                if (joinGreenTeacherBtn.length) {
                                    joinGreenTeacherBtn.parent().append(typeNotice);
                                } else if (joinDemandUnitBtn.length) {
                                    joinDemandUnitBtn.parent().append(typeNotice);
                                }
                            }
                            break;
                    }
                }
                
                // 更新按鈕狀態和文字
                function updateButtonStatus(button, status) {
					var spanElement = button.find('.elementor-button-content-wrapper');
                    var originalText = spanElement.text().replace(/(.+?)\1+/, '$1').trim();
                    var originalHref = button.attr('href');
                    
                    switch(status) {
                        case 'pending':
                            button.find('span').html('申請審核中...');
                            button.css({
                                'background-color': '#ffb900',
                                'color': 'white',
                                'cursor': 'not-allowed',
                                'opacity': '0.7'
                            });
                            button.attr('href', 'javascript:void(0)');
                            button.off('click').on('click', function(e) {
                                e.preventDefault();
                                alert('您的申請正在審核中，請耐心等待管理員審核。');
                            });
                            break;
                            
                        case 'revision':
                            button.find('span').html('修正審核中...');
                            button.css({
                                'background-color': '#ff8c00',
                                'color': 'white',
                                'cursor': 'not-allowed',
                                'opacity': '0.7'
                            });
                            button.attr('href', 'javascript:void(0)');
                            button.off('click').on('click', function(e) {
                                e.preventDefault();
                                alert('您的修正申請正在審核中，請耐心等待管理員審核。');
                            });
                            break;
                            
                        case 'approved':
                            if (userType === 'green_teacher') {
                                // 綠照師：顯示修正資料按鈕
                                button.find('span').html('修正資料');
                                button.css({
                                    'background-color': '#46b450',
                                    'color': 'white',
                                    'cursor': 'pointer',
                                    'opacity': '1'
                                });
                                button.attr('href', 'javascript:void(0)');
                                button.off('click').on('click', function(e) {
                                    e.preventDefault();
                                    handleGreenTeacherRevision(button);
                                });
                            } else if (userType === 'demand_unit') {
                                // 需求單位：可以繼續提交新需求
                                button.find('span').html('提交新需求');
                                button.css({
                                    'background-color': '#46b450',
                                    'color': 'white',
                                    'cursor': 'pointer',
                                    'opacity': '1'
                                });
                                var originalHref = button.data('original-href') || button.attr('href');
                                button.attr('href', originalHref + '?mode=new_demand');
                            }
                            break;
                            
                        case 'rejected':
                            button.find('span').html('申請被拒絕 - 重新申請');
                            button.css({
                                'background-color': '#dc3232',
                                'color': 'white'
                            });
                            // 保持原有連結，允許重新申請
                            break;
                            
                        default:
                            // 未申請或其他狀態，保持原樣
                            button.find('span').html(originalText);
                            button.attr('href', originalHref);
                            button.css({
                                'background-color': '',
                                'color': '',
                                'cursor': '',
                                'opacity': ''
                            });
                            break;
                    }
                }
                
                // 處理綠照師修正資料請求
                function handleGreenTeacherRevision(button) {
                    var confirmMessage = '修正資料後，待資料審核後將會重新上架。\n\n' +
                                       '您當前的 Portfolio 將會立即下架，直到新的申請審核通過。\n\n' +
                                       '確定要繼續嗎？';

                    if (confirm(confirmMessage)) {
                        // 先隱藏當前的 Portfolio
                        hideCurrentPortfolio(function() {
                            // Portfolio 隱藏成功後，跳轉到表單頁面
                            var revisionUrl = 'https://gc-express.org/%E5%8A%A0%E5%85%A5%E7%B6%A0%E7%85%A7%E5%A4%A5%E4%BC%B4/?mode=revision';
                            window.location.href = revisionUrl;
                        });
                    }
                }

                // 隱藏當前用戶的 Portfolio
                function hideCurrentPortfolio(callback) {
                    jQuery.ajax({
                        url: tcross_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'tcross_hide_user_portfolio',
                            nonce: tcross_ajax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                console.log('Portfolio 已隱藏:', response.data.message);
                            } else {
                                console.log('隱藏 Portfolio 失敗:', response.data);
                            }
                            // 無論成功或失敗都繼續跳轉
                            if (callback) callback();
                        },
                        error: function(xhr, status, error) {
                            console.log('隱藏 Portfolio 請求失敗:', error);
                            // 即使失敗也繼續跳轉
                            if (callback) callback();
                        }
                    });
                }
                
                // 初始化按鈕顯示
                controlButtonVisibility();
                
                // 監聴頁面動態加載（針對 SPA 或 AJAX 加載的內容）
                $(document).on('DOMNodeInserted', function(e) {
                    if ($(e.target).find('#join-green-teacher, #join-demand-unit').length > 0) {
                        setTimeout(controlButtonVisibility, 100);
                    }
                });
            }
        });
        </script>
        <?php
    }
    /**
     * 添加管理選單
     */
    public function add_admin_menu() {
        add_menu_page(
            'TCross 用戶管理',
            'TCross 用戶',
            'manage_options',
            'tcross-user-manager',
            array('TCrossAdminPage', 'display_admin_page'),
            'dashicons-groups',
            30
        );
    }
    
    /**
     * 在 WooCommerce 註冊表單中添加用戶類型選擇
     */
    public function add_user_type_selection() {
        // 檢查是否在排除的頁面
        $current_url = $_SERVER['REQUEST_URI'];
        $excluded_pages = array(
            '綠照地圖',
            'green-map', 
            '加入綠照夥伴',
            '加入綠照地圖',
            'join-green',
            'portfolio'
        );
        
        foreach ($excluded_pages as $excluded) {
            if (strpos($current_url, $excluded) !== false) {
                return; // 在排除頁面中，不添加用戶類型選擇
            }
        }
        
        // 確保隱藏字段正確插入到表單中
        ?>
        <script>
        jQuery(document).ready(function($) {
            // 確保隱藏字段存在並插入到正確位置
            if ($('#tcross-user-type').length === 0) {
                var targetForm = $('form.woocommerce-form-register, form.register, form[action*="register"]').first();
                if (targetForm.length > 0) {
                    targetForm.append('<input type="hidden" id="tcross-user-type" name="tcross_user_type" value="" required>');
                    console.log('TCross: 主要方案 - 隱藏字段已插入到表單中');
                } else {
                    $('body').append('<input type="hidden" id="tcross-user-type" name="tcross_user_type" value="" required>');
                    console.log('TCross: 主要方案 - 隱藏字段已插入到 body 中');
                }
            }
        });
        </script>
        
        <input type="hidden" id="tcross-user-type-backup" name="tcross_user_type" value="" required>
        
        <script>
        jQuery(document).ready(function($) {
            console.log('TCross: 開始初始化用戶類型選擇');
            
            // 等待頁面完全加載
            setTimeout(function() {
                // 在註冊按鈕前添加用戶類型選擇
                var userTypeHtml = '<div id="tcross-user-type-selection" style="margin: 20px 0; border: 2px solid green; padding: 15px; background: #f9f9f9;">' +
                    '<h3 style="margin-top: 0;">請選擇註冊類型</h3>' +
                    '<div class="user-type-buttons">' +
                        '<button type="button" id="select-demand-unit" class="user-type-btn" data-type="demand_unit">' +
                            '註冊需求單位' +
                        '</button>' +
                        '<button type="button" id="select-green-teacher" class="user-type-btn" data-type="green_teacher">' +
                            '註冊綠照師' +
                        '</button>' +
                    '</div>' +
                    '<div id="type-selection-error" style="color: red; display: none;">請選擇註冊類型</div>' +
                '</div>';
                
                console.log('TCross: 尋找註冊按鈕...');
                
                // 嘗試多種選擇器來找到註冊按鈕
                var selectors = [
                    '.woocommerce-form-register__submit',
                    'button[name="register"]',
                    'input[name="register"]',
                    'button[value="註冊"]',
                    'input[value="註冊"]',
                    'form.woocommerce-form-register button[type="submit"]',
                    'form.woocommerce-form-register input[type="submit"]',
                    'form.register button[type="submit"]',
                    'form.register input[type="submit"]',
                    '.woocommerce-Button',
                    'button.woocommerce-button',
                    'input.woocommerce-button'
                ];
                
                var buttonFound = false;
                
                for (var i = 0; i < selectors.length; i++) {
                    var elements = $(selectors[i]);
                    console.log('TCross: 檢查選擇器 "' + selectors[i] + '" - 找到 ' + elements.length + ' 個元素');
                    
                    if (elements.length > 0) {
                        console.log('TCross: 使用選擇器 "' + selectors[i] + '" 插入用戶類型選擇');
                        elements.first().before(userTypeHtml);
                        buttonFound = true;
                        break;
                    }
                }
                
                if (!buttonFound) {
                    console.log('TCross: 找不到註冊按鈕，嘗試插入到表單末尾');
                    var forms = $('form.woocommerce-form-register, form.register, form[action*="register"]');
                    console.log('TCross: 找到 ' + forms.length + ' 個表單');
                    
                    if (forms.length > 0) {
                        forms.first().append(userTypeHtml);
                        buttonFound = true;
                    } else {
                        // 最後手段：插入到頁面中任何包含 "register" 的表單
                        var anyForm = $('form').filter(function() {
                            return $(this).html().indexOf('register') > -1 || $(this).html().indexOf('註冊') > -1;
                        });
                        
                        if (anyForm.length > 0) {
                            console.log('TCross: 在包含註冊的表單中插入');
                            anyForm.first().append(userTypeHtml);
                            buttonFound = true;
                        }
                    }
                }
                
                if (buttonFound) {
                    console.log('TCross: 用戶類型選擇已成功插入');
                } else {
                    console.log('TCross: 警告 - 無法找到合適的位置插入用戶類型選擇');
                    // 強制插入到 body 末尾作為最後手段
                    $('body').append('<div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999; background: white; padding: 20px; border: 2px solid red;">' + userTypeHtml + '</div>');
                }
            }, 500); // 延遲 500ms 確保頁面元素已加載
            
            // 添加樣式
            $('<style>' +
                '.user-type-buttons { display: flex; gap: 15px; margin: 10px 0; flex-wrap: wrap; }' +
                '.user-type-btn { padding: 12px 25px; border: 2px solid #000; background: #fff; color: #000; cursor: pointer; border-radius: 5px; font-size: 16px; transition: all 0.3s ease; }' +
                '.user-type-btn:hover { background: #f5f5f5; border-color: #333; }' +
                '.user-type-btn.selected { background: #28a745; color: #fff; border: none; }' +
                '@media (max-width: 480px) { .user-type-buttons { flex-direction: column; } .user-type-btn { width: 100%; } }' +
            '</style>').appendTo('head');
            
            // 初始化用戶類型選擇功能
            initUserTypeSelection();
        });
        
        // 用戶類型選擇功能
        function initUserTypeSelection() {
            // 確保 jQuery 可用
            if (typeof jQuery === 'undefined') {
                console.error('TCross: jQuery 未加載 - initUserTypeSelection');
                return;
            }
            
            jQuery(function($) {
                // 從 localStorage 恢復選擇狀態
                function restoreUserTypeSelection() {
                    try {
                        var savedType = localStorage.getItem('tcross_selected_user_type');
                        if (savedType) {
                            $('.user-type-btn[data-type="' + savedType + '"]').addClass('selected');
                            $('#tcross-user-type').val(savedType);
                            $('#type-selection-error').hide();
                        }
                    } catch(e) {
                        console.warn('TCross: localStorage 不可用:', e);
                    }
                }
                
                // 保存選擇到 localStorage
                function saveUserTypeSelection(type) {
                    try {
                        localStorage.setItem('tcross_selected_user_type', type);
                    } catch(e) {
                        console.warn('TCross: 無法保存到 localStorage:', e);
                    }
                }
                
                // 清除保存的選擇
                function clearUserTypeSelection() {
                    try {
                        localStorage.removeItem('tcross_selected_user_type');
                    } catch(e) {
                        console.warn('TCross: 無法清除 localStorage:', e);
                    }
                }
                
                // 初始化時恢復選擇
                restoreUserTypeSelection();
                
                // 處理按鈕點擊
                $(document).off('click.tcross-main', '.user-type-btn').on('click.tcross-main', '.user-type-btn', function() {
                    var selectedType = $(this).data('type');
                    
                    $('.user-type-btn').removeClass('selected');
                    $(this).addClass('selected');
                    $('#tcross-user-type').val(selectedType);
                    $('#type-selection-error').hide();
                    
                    // 保存選擇到 localStorage
                    saveUserTypeSelection(selectedType);
                });
                
                // 監聽表單字段變化，確保選擇狀態不會丟失
                $('form.woocommerce-form-register input, form.woocommerce-form-register select').on('change blur', function() {
                    // 延遲執行以確保其他事件處理完成
                    setTimeout(function() {
                        restoreUserTypeSelection();
                    }, 100);
                });
                
                // 驗證表單提交 - 加強版本
                $(document).off('submit.tcross-main', 'form.woocommerce-form-register, form.register, form[action*="register"]').on('submit.tcross-main', 'form.woocommerce-form-register, form.register, form[action*="register"]', function(e) {
                    var userType = $('#tcross-user-type').val();
                    
                    console.log('TCross: 表單提交驗證 - 用戶類型:', userType);
                    
                    if (!userType || userType === '') {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // 顯示錯誤訊息
                        $('#type-selection-error').show().text('請先選擇註冊類型');
                        
                        // 彈出提示框
                        alert('請先選擇註冊類型（需求單位或綠照師）');
                        
                        // 滾動到選擇區域
                        if ($('#tcross-user-type-selection').length > 0) {
                            $('html, body').animate({
                                scrollTop: $('#tcross-user-type-selection').offset().top - 100
                            }, 500);
                        }
                        
                        console.log('TCross: 阻止表單提交 - 未選擇用戶類型');
                        return false;
                    }
                    
                    console.log('TCross: 表單驗證通過，允許提交');
                    
                    // 確保所有同名字段都有相同的值
                    $('input[name="tcross_user_type"]').val(userType);
                    console.log('TCross: 已設置所有 tcross_user_type 字段的值為:', userType);
                    
                    // 表單提交時立即清除保存的選擇
                    clearUserTypeSelection();
                    
                    return true;
                });
                
                // 處理頁面刷新或離開時的情況
                $(window).on('beforeunload', function() {
                    // 頁面刷新或離開時清除 localStorage
                    clearUserTypeSelection();
                });
                
                // 處理 AJAX 表單提交後的狀態恢復
                $(document).ajaxComplete(function(event, xhr, settings) {
                    // 檢查是否是 WooCommerce 相關的 AJAX 請求
                    if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1) {
                        setTimeout(function() {
                            restoreUserTypeSelection();
                        }, 200);
                    }
                });
            });
        }
        </script>
        <?php
    }
    
    /**
     * 備用方案：在頁面底部添加用戶類型選擇（如果主要方法失敗）
     */
    public function add_user_type_selection_fallback() {
        // 只在真正的註冊頁面執行，排除綠照地圖等其他頁面
        $current_url = $_SERVER['REQUEST_URI'];
        
        // 排除特定頁面
        $excluded_pages = array(
            '綠照地圖',
            'green-map',
            '加入綠照夥伴',
            '加入綠照地圖',
            'join-green',
            'portfolio'
        );
        
        foreach ($excluded_pages as $excluded) {
            if (strpos($current_url, $excluded) !== false) {
                return;
            }
        }
        
        // 只在包含註冊表單的頁面執行
        if (!is_account_page() && !is_page() && !strpos($current_url, 'register') && !strpos($current_url, 'my-account')) {
            return;
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
    console.log('TCross: 執行備用用戶類型選擇插入');

    // 檢查是否已經存在用戶類型選擇
    if ($('#tcross-user-type-selection').length > 0) {
        console.log('TCross: 用戶類型選擇已存在，跳過備用方案');
        return;
    }

    // 檢查是否存在註冊相關的表單或元素
    var hasRegisterForm = $('form.woocommerce-form-register, form.register, form[action*="register"], input[name="register"], button[name="register"]').length > 0;
    var hasWooCommerceRegister = $('.woocommerce-form-register').length > 0;
    // var hasRegisterText = $('body').text().indexOf('註冊') > -1 || $('body').text().indexOf('register') > -1;

    // 排除特定內容的頁面
    // var hasExcludedContent = $('body').text().indexOf('綠照地圖') > -1 ||
    //                        $('body').text().indexOf('加入綠照夥伴') > -1 ||
    //                        $('body').text().indexOf('加入綠照地圖') > -1 ||
    //                        window.location.href.indexOf('portfolio') > -1;

    console.log('TCross: 檢測到註冊表單:', hasRegisterForm);
    console.log('TCross: 檢測到 WooCommerce 註冊:', hasWooCommerceRegister);
    // console.log('TCross: 檢測到註冊文字:', hasRegisterText);
    // console.log('TCross: 檢測到排除內容:', hasExcludedContent);

    // *** 修復的檢查邏輯 ***
    // if ((hasRegisterForm || hasWooCommerceRegister || hasRegisterText) && !hasExcludedContent) {
    if (hasRegisterForm || hasWooCommerceRegister) {

        console.log('TCross: 執行備用插入方案');

        // 確保隱藏字段存在並正確插入到表單中
        if ($('#tcross-user-type').length === 0) {
            // 嘗試插入到註冊表單中
            var targetForm = $('form.woocommerce-form-register, form.register, form[action*="register"]').first();
            if (targetForm.length > 0) {
                targetForm.append('<input type="hidden" id="tcross-user-type" name="tcross_user_type" value="" required>');
                console.log('TCross: 隱藏字段已插入到表單中');
            } else {
                $('body').append('<input type="hidden" id="tcross-user-type" name="tcross_user_type" value="" required>');
                console.log('TCross: 隱藏字段已插入到 body 中');
            }
        } else {
            // 確保現有字段也是必填的
            $('#tcross-user-type').attr('required', true);
            console.log('TCross: 現有隱藏字段已設置為必填');
        }

        // 創建用戶類型選擇 HTML
        var userTypeHtml = '<div id="tcross-user-type-selection" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 2px solid green; border-radius: 8px; position: relative; z-index: 1000;">' +
            '<h3 style="margin-top: 0; color: green; font-size: 18px;">請選擇註冊類型</h3>' +
            '<div class="user-type-buttons" style="display: flex; gap: 15px; margin: 10px 0; flex-wrap: wrap;">' +
                '<button type="button" id="select-demand-unit" class="user-type-btn" data-type="demand_unit" style="padding: 12px 25px; border: 2px solid #dc3545; background: #fff; color: #dc3545; cursor: pointer; border-radius: 5px; font-size: 16px; transition: all 0.3s ease;">' +
                    '註冊需求單位' +
                '</button>' +
                '<button type="button" id="select-green-teacher" class="user-type-btn" data-type="green_teacher" style="padding: 12px 25px; border: 2px solid #28a745; background: #fff; color: #28a745; cursor: pointer; border-radius: 5px; font-size: 16px; transition: all 0.3s ease;">' +
                    '註冊綠照師' +
                '</button>' +
            '</div>' +
            '<div id="type-selection-error" style="color: red; display: none; font-weight: bold; margin-top: 10px;">請選擇註冊類型</div>' +
        '</div>';

        // 添加 CSS 樣式
        $('<style>' +
            '.user-type-btn:hover { opacity: 0.8; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.2); }' +
            '.user-type-btn.selected { background: #28a745 !important; color: #fff !important; border-color: #28a745 !important; }' +
            '.user-type-btn.selected[data-type="demand_unit"] { background: #dc3545 !important; border-color: #dc3545 !important; }' +
            '@media (max-width: 480px) { .user-type-buttons { flex-direction: column; } .user-type-btn { width: 100%; } }' +
        '</style>').appendTo('head');

        // 嘗試插入到合適的位置
        var insertSuccess = false;

        // 方法1：插入到註冊按鈕前
        var registerButtons = $('.woocommerce-form-register__submit, button[name="register"], input[name="register"], button[value="註冊"], input[value="註冊"]');
        if (registerButtons.length > 0) {
            registerButtons.first().before(userTypeHtml);
            insertSuccess = true;
            console.log('TCross: 用戶類型選擇已插入到註冊按鈕前');
        }

        // 方法2：插入到表單末尾
        if (!insertSuccess) {
            var forms = $('form.woocommerce-form-register, form.register, form[action*="register"]');
            if (forms.length > 0) {
                forms.first().append(userTypeHtml);
                insertSuccess = true;
                console.log('TCross: 用戶類型選擇已插入到表單末尾');
            }
        }

        // 方法3：插入到頁面上的 WooCommerce 區域
        if (!insertSuccess) {
            var wooArea = $('.woocommerce, .woocommerce-account, .woocommerce-page');
            if (wooArea.length > 0) {
                wooArea.first().prepend(userTypeHtml);
                insertSuccess = true;
                console.log('TCross: 用戶類型選擇已插入到 WooCommerce 區域');
            }
        }

        // 方法4：強制插入到頁面頂部
        if (!insertSuccess) {
            $('body').prepend('<div style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; background: white; padding: 20px; border: 3px solid red; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">' +
                '<p style="color: red; font-weight: bold; margin: 0 0 10px;">請選擇註冊類型：</p>' +
                userTypeHtml +
                '<button onclick="$(this).parent().remove();" style="position: absolute; top: 5px; right: 10px; background: red; color: white; border: none; width: 25px; height: 25px; border-radius: 50%; cursor: pointer;">×</button>' +
            '</div>');
            console.log('TCross: 強制插入用戶類型選擇到頁面頂部');
        }

        // 初始化用戶類型選擇功能
        initUserTypeSelectionAdvanced();

    } else {
        console.log('TCross: 未檢測到註冊相關內容，跳過備用方案');
        console.log('TCross: 詳細檢查結果:', {
            hasRegisterForm: hasRegisterForm,
            hasWooCommerceRegister: hasWooCommerceRegister,
            // hasRegisterText: hasRegisterText,
            // hasExcludedContent: hasExcludedContent,
            currentUrl: window.location.href
        });
    }
});

// 增強版用戶類型選擇功能
function initUserTypeSelectionAdvanced() {
    console.log('TCross: 初始化增強版用戶類型選擇功能');

    // 確保 jQuery 可用
    if (typeof jQuery === 'undefined') {
        console.error('TCross: jQuery 未加載');
        return;
    }

    // 使用 jQuery 避免 $ 衝突
    jQuery(function($) {
        // 處理按鈕點擊
        $(document).off('click.tcross', '.user-type-btn').on('click.tcross', '.user-type-btn', function() {
            var selectedType = $(this).data('type');
            console.log('TCross: 選擇了用戶類型:', selectedType);

            $('.user-type-btn').removeClass('selected');
            $(this).addClass('selected');

            // 設置隱藏字段的值
            $('#tcross-user-type, input[name="tcross_user_type"]').val(selectedType);
            $('#type-selection-error').hide();

            // 保存到 sessionStorage（頁面級別的記憶）
            try {
                sessionStorage.setItem('tcross_selected_user_type', selectedType);
            } catch(e) {
                console.warn('TCross: sessionStorage 不可用:', e);
            }

            console.log('TCross: 用戶類型已設置為:', selectedType);
        });

        // 從 sessionStorage 恢復選擇
        try {
            var savedType = sessionStorage.getItem('tcross_selected_user_type');
            if (savedType) {
                $('.user-type-btn[data-type="' + savedType + '"]').addClass('selected');
                $('#tcross-user-type, input[name="tcross_user_type"]').val(savedType);
                console.log('TCross: 從 sessionStorage 恢復用戶類型:', savedType);
            }
        } catch(e) {
            console.warn('TCross: 無法讀取 sessionStorage:', e);
        }

        // 增強版表單提交驗證
        $(document).off('submit.tcross').on('submit.tcross', 'form', function(e) {
            // 只處理包含註冊相關元素的表單
            var $form = $(this);
            var isRegisterForm = $form.hasClass('woocommerce-form-register') ||
                                $form.hasClass('register') ||
                                ($form.attr('action') && $form.attr('action').indexOf('register') !== -1) ||
                                $form.find('input[name="register"], button[name="register"]').length > 0;

            if (!isRegisterForm) {
                return true; // 不是註冊表單，允許正常提交
            }

            var userType = $('#tcross-user-type').val() || $('input[name="tcross_user_type"]').val();

            console.log('TCross: 表單提交驗證 - 用戶類型:', userType);
            console.log('TCross: 表單類型檢查 - 是註冊表單:', isRegisterForm);

            if (!userType || userType === '') {
                e.preventDefault();
                e.stopPropagation();

                // 顯示錯誤訊息
                $('#type-selection-error').show().text('請先選擇註冊類型（需求單位或綠照師）');

                // 彈出提示框
                alert('請先選擇註冊類型：\n\n• 需求單位：有綠色教育需求的組織或個人\n• 綠照師：提供綠色教育服務的講師');

                // 滾動到選擇區域
                if ($('#tcross-user-type-selection').length > 0) {
                    $('html, body').animate({
                        scrollTop: $('#tcross-user-type-selection').offset().top - 100
                    }, 500);

                    // 高亮顯示選擇區域
                    $('#tcross-user-type-selection').animate({
                        backgroundColor: '#ffeeee'
                    }, 200).animate({
                        backgroundColor: '#f9f9f9'
                    }, 200);
                }

                console.log('TCross: 阻止表單提交 - 未選擇用戶類型');
                return false;
            }

            // 確保所有相關字段都有值
            $('#tcross-user-type, input[name="tcross_user_type"]').val(userType);

            console.log('TCross: 表單驗證通過，用戶類型:', userType);

            // 提交成功後清除 sessionStorage
            try {
                sessionStorage.removeItem('tcross_selected_user_type');
            } catch(e) {
                console.warn('TCross: 無法清除 sessionStorage:', e);
            }

            return true;
        });

        console.log('TCross: 用戶類型選擇功能初始化完成');
    });
}
        </script>
        <?php
    }
    
    /**
     * 保存用戶類型到資料庫
     */
    public function save_user_type($customer_id) {
        // 記錄調試信息
        error_log('TCross: save_user_type called for user ID: ' . $customer_id);
        error_log('TCross: POST data: ' . print_r($_POST, true));
        error_log('TCross: REQUEST data: ' . print_r($_REQUEST, true));
        
        // 檢查多種可能的字段名
        $user_type = '';
        if (isset($_POST['tcross_user_type']) && !empty($_POST['tcross_user_type'])) {
            $user_type = sanitize_text_field($_POST['tcross_user_type']);
        } elseif (isset($_REQUEST['tcross_user_type']) && !empty($_REQUEST['tcross_user_type'])) {
            $user_type = sanitize_text_field($_REQUEST['tcross_user_type']);
        }
        
        error_log('TCross: Extracted user type: ' . $user_type);
        
        if (!empty($user_type)) {
            error_log('TCross: Saving user type: ' . $user_type . ' for user ID: ' . $customer_id);
            
            // 驗證用戶類型值
            if (!in_array($user_type, array('green_teacher', 'demand_unit'))) {
                error_log('TCross: Invalid user type: ' . $user_type);
                return;
            }
            
            // 保存到 wp_usermeta
            $meta_result = update_user_meta($customer_id, 'tcross_user_type', $user_type);
            error_log('TCross: update_user_meta result: ' . ($meta_result ? 'success' : 'failed'));
            
            // 保存到自定義表格
            $table_result = TCrossUserTable::insert_user_type($customer_id, $user_type);
            error_log('TCross: insert_user_type result: ' . ($table_result ? 'success' : 'failed'));
            
            // 驗證保存結果
            $saved_meta = get_user_meta($customer_id, 'tcross_user_type', true);
            error_log('TCross: Verification - saved meta value: ' . $saved_meta);
            
            // 如果保存失敗，嘗試重新保存
            if ($saved_meta !== $user_type) {
                error_log('TCross: First save failed, retrying...');
                sleep(1); // 等待一秒
                $retry_result = update_user_meta($customer_id, 'tcross_user_type', $user_type);
                $retry_saved = get_user_meta($customer_id, 'tcross_user_type', true);
                error_log('TCross: Retry result: ' . ($retry_result ? 'success' : 'failed') . ', saved value: ' . $retry_saved);
            }
            
            // 清除 localStorage 中的臨時選擇
            ?>
            <script>
            if (typeof(Storage) !== "undefined") {
                localStorage.removeItem('tcross_selected_user_type');
            }
            </script>
            <?php
            
            // 不改變用戶角色，保持為一般使用者（customer）
        } else {
            error_log('TCross: No user type found in POST data or empty value');
            
            // 如果 POST 中沒有用戶類型，嘗試從 localStorage 恢復（通過 AJAX）
            ?>
            <script>
            jQuery(document).ready(function($) {
                if (typeof(Storage) !== "undefined") {
                    var savedType = localStorage.getItem('tcross_selected_user_type');
                    if (savedType) {
                        // 通過 AJAX 更新用戶類型
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: {
                                action: 'tcross_update_user_type',
                                user_id: <?php echo $customer_id; ?>,
                                user_type: savedType,
                                nonce: '<?php echo wp_create_nonce('tcross_user_nonce'); ?>'
                            },
                            success: function(response) {
                                console.log('TCross: User type updated via AJAX:', response);
                                localStorage.removeItem('tcross_selected_user_type');
                            },
                            error: function(xhr, status, error) {
                                console.error('TCross: Failed to update user type via AJAX:', error);
                            }
                        });
                    }
                }
            });
            </script>
            <?php
        }
    }
    
    /**
     * 載入註冊模態視窗資源
     */
    public function enqueue_registration_modal_assets() {
        // 只在前台頁面載入
        if (is_admin()) {
            return;
        }
        
        // 載入 CSS
        wp_enqueue_style(
            'tcross-registration-modal',
            plugin_dir_url(__FILE__) . 'assets/css/registration-modal.css',
            array(),
            '1.0.0'
        );
        
        // 載入 JavaScript
        wp_enqueue_script(
            'tcross-registration-modal',
            plugin_dir_url(__FILE__) . 'assets/js/registration-modal.js',
            array('jquery'),
            '1.0.0',
            true
        );
    }
    
    /**
     * 獲取註冊須知內容
     */
    public function get_registration_notice() {
        // 驗證 nonce
        if (!wp_verify_nonce($_POST['nonce'], 'tcross_user_nonce')) {
            wp_send_json_error('安全驗證失敗');
        }
        
        // 獲取設定選項
        $options = get_option('tcross_user_manager_options', array());
        
        $response = array(
            'title' => $options['registration_notice_title'] ?? '註冊確認',
            'content' => $options['registration_notice_content'] ?? $this->get_default_registration_notice()
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * 獲取預設註冊須知內容
     */
    private function get_default_registration_notice() {
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
     * 處理自定義註冊邏輯
     */
    public function handle_custom_registration() {
        // 驗證 nonce
        if (!wp_verify_nonce($_POST['nonce'], 'tcross_user_nonce')) {
            wp_die('安全驗證失敗');
        }
        
        $user_type = sanitize_text_field($_POST['user_type']);
        $email = sanitize_email($_POST['email']);
        $username = sanitize_user($_POST['username']);
        $password = $_POST['password'];
        
        // 這裡可以添加額外的註冊邏輯
        
        wp_send_json_success(array(
            'message' => '註冊成功',
            'user_type' => $user_type
        ));
    }
    
    /**
     * 修改 WooCommerce 註冊表單
     */
    public function modify_register_form() {
        // 可以在這裡添加額外的表單欄位
    }

    /**
     * Shortcode: 根據用戶類型顯示按鈕
     * 用法: [tcross_user_button type=\"green_teacher\" url=\"/join-green\" text=\"加入綠照夥伴\" show_for=\"demand_unit,guest\"]
     */
    public function user_button_shortcode($atts) {
        $atts = shortcode_atts(array(
            'type' => '', // 按鈕類型
            'url' => '#',
            'text' => '點擊這裧',
            'show_for' => 'all', // 顯示給哪些用戶類型：all, guest, green_teacher, demand_unit
            'class' => 'tcross-button',
            'id' => ''
        ), $atts);

        $current_user_type = '';
        $is_logged_in = false;

        if (is_user_logged_in()) {
            $is_logged_in = true;
            $user_id = get_current_user_id();
            $user_type_data = TCrossUserTable::get_user_type($user_id);
            if ($user_type_data) {
                $current_user_type = $user_type_data->user_type;
            }
        }

        // 檢查是否應該顯示此按鈕
        $show_for_array = explode(',', $atts['show_for']);
        $should_show = false;

        foreach ($show_for_array as $show_condition) {
            $show_condition = trim($show_condition);

            if ($show_condition === 'all') {
                $should_show = true;
                break;
            } elseif ($show_condition === 'guest' && !$is_logged_in) {
                $should_show = true;
                break;
            } elseif ($show_condition === $current_user_type) {
                $should_show = true;
                break;
            }
        }

        if (!$should_show) {
            return '';
        }

        $id_attr = $atts['id'] ? 'id=\"' . esc_attr($atts['id']) . '\"' : '';

        return sprintf(
            '<a href=\"%s\" class=\"%s\" %s><span>%s</span></a>',
            esc_url($atts['url']),
            esc_attr($atts['class']),
            $id_attr,
            esc_html($atts['text'])
        );
    }

    /**
     * Shortcode: 條件式內容顯示
     * 用法: [tcross_conditional_content show_for=\"green_teacher,guest\"]內容[/tcross_conditional_content]
     */
    public function conditional_content_shortcode($atts, $content = '') {
        $atts = shortcode_atts(array(
            'show_for' => 'all', // 顯示給哪些用戶類型
            'hide_for' => '' // 隱藏不顯示給哪些用戶類型
        ), $atts);

        $current_user_type = '';
        $is_logged_in = false;

        if (is_user_logged_in()) {
            $is_logged_in = true;
            $user_id = get_current_user_id();
            $user_type_data = TCrossUserTable::get_user_type($user_id);
            if ($user_type_data) {
                $current_user_type = $user_type_data->user_type;
            }
        }

        // 檢查隱藏條件
        if (!empty($atts['hide_for'])) {
            $hide_for_array = explode(',', $atts['hide_for']);
            foreach ($hide_for_array as $hide_condition) {
                $hide_condition = trim($hide_condition);

                if ($hide_condition === 'guest' && !$is_logged_in) {
                    return '';
                } elseif ($hide_condition === $current_user_type) {
                    return '';
                }
            }
        }

        // 檢查顯示條件
        $show_for_array = explode(',', $atts['show_for']);
        $should_show = false;

        foreach ($show_for_array as $show_condition) {
            $show_condition = trim($show_condition);

            if ($show_condition === 'all') {
                $should_show = true;
                break;
            } elseif ($show_condition === 'guest' && !$is_logged_in) {
                $should_show = true;
                break;
            } elseif ($show_condition === $current_user_type) {
                $should_show = true;
                break;
            }
        }

        if ($should_show) {
            return do_shortcode($content);
        }

        return '';
    }
}


// 初始化插件
function tcross_user_manager_init() {
    return TCrossUserManager::get_instance();
}

// WordPress 初始化後啟動插件
add_action('plugins_loaded', 'tcross_user_manager_init');

// 啟用插件時創建用戶角色
register_activation_hook(__FILE__, 'tcross_create_user_roles');

function tcross_create_user_roles() {
    // 創建需求單位角色
    add_role('demand_unit', '需求單位', array(
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
    ));
    
    // 創建綠照師角色
    add_role('green_teacher', '綠照師', array(
        'read' => true,
        'edit_posts' => true,
        'publish_posts' => true,
        'delete_posts' => false,
    ));
}

// 停用插件時清理
register_deactivation_hook(__FILE__, 'tcross_cleanup_user_roles');

function tcross_cleanup_user_roles() {
    remove_role('demand_unit');
    remove_role('green_teacher');
}
?>