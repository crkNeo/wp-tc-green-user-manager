/**
 * TCross Registration Modal JavaScript (修復版)
 * 處理註冊確認視窗的邏輯
 */

(function($) {
    'use strict';
    
    var TCrossRegistrationModal = {
        
        // 配置選項
        config: {
            modalId: 'tcross-registration-modal',
            animationDuration: 300,
            defaultTitle: '註冊確認'
        },
        
        // 初始化狀態
        initialized: false,
        eventsbound: false,
        
        // 獲取預設內容
        getDefaultContent: function() {
            var content = '<h4>請仔細閱讀以下注意事項：</h4>';
            content += '<ul>';
            content += '<li>請確保您提供的資料真實有效</li>';
            content += '<li>註冊後您將收到確認郵件，請檢查您的信箱</li>';
            content += '<li>如有任何問題，請聯繫客服人員</li>';
            content += '<li>註冊即表示您同意我們的服務條款和隱私政策</li>';
            content += '</ul>';
            content += '<p><strong>確認後將完成註冊程序。</strong></p>';
            return content;
        },
        
        // 初始化
        init: function() {
            if (this.initialized) {
                console.log('TCross: 已初始化，跳過');
                return;
            }
            
            this.createModal();
            this.bindEvents();
            
            // 延遲綁定表單事件，確保DOM已完全載入
            var self = this;
            setTimeout(function() {
                self.interceptRegistrationForms();
                self.addFormDebugging();
            }, 1000);
            
            this.initialized = true;
            console.log('TCross Registration Modal initialized');
        },
        
        // 添加表單調試功能
        addFormDebugging: function() {
            var self = this;
            
            // 檢查頁面上的表單
            var forms = $('form');
            console.log('TCross: 頁面上找到 ' + forms.length + ' 個表單');
            
            forms.each(function(index, form) {
                var $form = $(form);
                var isRegisterForm = $form.hasClass('woocommerce-form-register') || 
                                   $form.hasClass('register') || 
                                   ($form.attr('action') && $form.attr('action').indexOf('register') !== -1);
                
                console.log('表單 ' + (index + 1) + ':', {
                    classes: form.className,
                    action: $form.attr('action') || '(無action)',
                    isRegisterForm: isRegisterForm
                });
                
                if (isRegisterForm) {
                    console.log('TCross: 找到註冊表單，手動綁定click事件到註冊按鈕');
                    var submitBtn = $form.find('button[type="submit"], input[type="submit"], .woocommerce-form-register__submit');
                    console.log('TCross: 找到 ' + submitBtn.length + ' 個提交按鈕');
                    
                    submitBtn.on('click.tcross', function(e) {
                        console.log('TCross: 註冊按鈕被點擊');
                        
                        // 檢查表單驗證
                        var userType = $('#tcross-user-type').val() || $('input[name="tcross_user_type"]').val();
                        console.log('TCross: 按鈕點擊時用戶類型:', userType);
                        
                        if (userType && userType !== '') {
                            if (!$form.data('tcross-confirmed')) {
                                e.preventDefault();
                                e.stopPropagation();
                                console.log('TCross: 攔截按鈕點擊，顯示確認視窗');
                                
                                self.currentForm = $form;
                                self.loadModalContent(function() {
                                    self.showModal();
                                });
                                return false;
                            }
                        }
                    });
                }
            });
        },
        
        // 創建模態視窗 HTML
        createModal: function() {
            if ($('#' + this.config.modalId).length > 0) {
                return; // 模態視窗已存在
            }
            
            var modalHtml = 
                '<div id="' + this.config.modalId + '" class="tcross-registration-modal">' +
                    '<div class="tcross-modal-content">' +
                        '<div class="tcross-modal-header">' +
                            '<h3 id="tcross-modal-title">' + this.config.defaultTitle + '</h3>' +
                            '<span class="tcross-modal-close">&times;</span>' +
                        '</div>' +
                        '<div class="tcross-modal-body">' +
                            '<div id="tcross-modal-content">' + this.getDefaultContent() + '</div>' +
                        '</div>' +
                        '<div class="tcross-modal-footer">' +
                            '<button type="button" class="tcross-modal-btn tcross-modal-btn-cancel" id="tcross-modal-cancel">取消</button>' +
                            '<button type="button" class="tcross-modal-btn tcross-modal-btn-confirm" id="tcross-modal-confirm">確認註冊</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            
            $('body').append(modalHtml);
        },
        
        // 綁定事件
        bindEvents: function() {
            var self = this;
            
            // 關閉按鈕
            $(document).on('click', '.tcross-modal-close, #tcross-modal-cancel', function(e) {
                e.preventDefault();
                self.clearCurrentForm(); // 取消時清空表單引用
                self.hideModal();
            });
            
            // 確認按鈕
            $(document).on('click', '#tcross-modal-confirm', function(e) {
                e.preventDefault();
                self.confirmRegistration();
            });
            
            // 點擊背景關閉
            $(document).on('click', '#' + this.config.modalId, function(e) {
                if (e.target === this) {
                    self.hideModal();
                }
            });
            
            // ESC 鍵關閉
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#' + self.config.modalId).hasClass('show')) {
                    self.hideModal();
                }
            });
        },
        
        // 攔截註冊表單提交
        interceptRegistrationForms: function() {
            var self = this;
            
            if (this.eventsbound) {
                console.log('TCross: 表單事件已綁定，跳過');
                return;
            }
            
            // 攔截 WooCommerce 註冊表單
            $(document).on('submit.tcross', 'form.woocommerce-form-register, form.register, form[action*="register"]', function(e) {
                var $form = $(this);
                
                console.log('TCross: 表單提交事件觸發', $form);
                
                // 檢查是否已經確認過
                if ($form.data('tcross-confirmed') === true) {
                    console.log('TCross: 註冊已確認，允許提交');
                    return true; // 允許正常提交
                }
                
                // 檢查是否選擇了用戶類型
                var userType = $('#tcross-user-type').val() || $('input[name="tcross_user_type"]').val();
                console.log('TCross: 檢查用戶類型:', userType);
                
                if (!userType || userType === '') {
                    console.log('TCross: 用戶類型未選擇，讓原有邏輯處理');
                    // 用戶類型驗證失敗，讓原有邏輯處理
                    return true;
                }
                
                // 阻止表單提交，顯示確認視窗
                e.preventDefault();
                e.stopPropagation();
                
                console.log('TCross: 攔截註冊表單，顯示確認視窗');
                
                // 儲存表單引用
                self.currentForm = $form;
                
                // 載入並顯示模態視窗
                self.loadModalContent(function() {
                    self.showModal();
                });
                
                return false;
            });
            
            this.eventsbound = true;
            console.log('TCross: 表單事件紁定完成');
        },
        
        // 載入模態視窗內容
        loadModalContent: function(callback) {
            var self = this;
            
            // 檢查是否有 tcross_ajax 變數（WordPress環境）
            if (typeof tcross_ajax !== 'undefined') {
                // 從後台獲取註冊須知內容
                $.ajax({
                    url: tcross_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'tcross_get_registration_notice',
                        nonce: tcross_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            $('#tcross-modal-title').text(data.title || self.config.defaultTitle);
                            $('#tcross-modal-content').html(data.content || self.getDefaultContent());
                            console.log('TCross: 註冊須知內容已載入');
                        } else {
                            console.warn('TCross: 無法載入註冊須知，使用預設內容');
                            $('#tcross-modal-title').text(self.config.defaultTitle);
                            $('#tcross-modal-content').html(self.getDefaultContent());
                        }
                        
                        if (callback) callback();
                    },
                    error: function(xhr, status, error) {
                        console.error('TCross: 載入註冊須知失敗:', error);
                        $('#tcross-modal-title').text(self.config.defaultTitle);
                        $('#tcross-modal-content').html(self.getDefaultContent());
                        
                        if (callback) callback();
                    }
                });
            } else {
                // 測試環境，直接使用預設內容
                $('#tcross-modal-title').text(self.config.defaultTitle);
                $('#tcross-modal-content').html(self.getDefaultContent());
                if (callback) callback();
            }
        },
        
        // 顯示模態視窗
        showModal: function() {
            var $modal = $('#' + this.config.modalId);
            $modal.removeClass('closing').addClass('show');
            
            // 禁用背景滾動
            $('body').css('overflow', 'hidden');
            
            // 聚焦到確認按鈕
            setTimeout(function() {
                $('#tcross-modal-confirm').focus();
            }, this.config.animationDuration);
            
            console.log('TCross: 註冊確認視窗已顯示');
        },
        
        // 隱藏模態視窗
        hideModal: function() {
            var self = this;
            var $modal = $('#' + this.config.modalId);
            
            $modal.addClass('closing');
            
            setTimeout(function() {
                $modal.removeClass('show closing');
                $('body').css('overflow', '');
                // 注意：不再在這裡清空currentForm，由confirmRegistration方法負責清空
                console.log('TCross: 註冊確認視窗已關閉');
            }, this.config.animationDuration);
        },
        
        // 清空當前表單引用
        clearCurrentForm: function() {
            this.currentForm = null;
        },
        
        // 確認註冊
        confirmRegistration: function() {
            var self = this;
            
            if (!this.currentForm) {
                console.error('TCross: 沒有找到要提交的表單');
                this.hideModal();
                return;
            }
            
            // 顯示載入狀態
            var $confirmBtn = $('#tcross-modal-confirm');
            $confirmBtn.addClass('loading').text('處理中...');
            
            console.log('TCross: 用戶確認註冊，準備提交表單');
            
            // 保存表單引用，避免在hideModal中被清空
            var formToSubmit = this.currentForm;
            
            // 標記表單已確認
            formToSubmit.data('tcross-confirmed', true);
            
            // 延遲一點時間讓用戶看到載入狀態
            setTimeout(function() {
                console.log('TCross: 正在提交註冊表單');
                
                // 隱藏模態視窗
                self.hideModal();
                
                // 提交原始表單
                setTimeout(function() {
                    try {
                        // 檢查表單是否仍然存在於DOM中
                        if (formToSubmit.length && formToSubmit.is('form')) {
                            console.log('TCross: 提交表單 - 觸發按鈕點擊');
                            console.log('TCross: 表單元素:', formToSubmit[0]);
                            console.log('TCross: 表單狀態 - tcross-confirmed:', formToSubmit.data('tcross-confirmed'));
                            
                            // 尋找提交按鈕並觸發點擊
                            var submitBtn = formToSubmit.find('button[type="submit"], input[type="submit"], .woocommerce-form-register__submit').first();
                            if (submitBtn.length > 0) {
                                console.log('TCross: 找到提交按鈕，觸發點擊');
                                submitBtn.trigger('click');
                            } else {
                                console.log('TCross: 找不到提交按鈕，使用submit()');
                                formToSubmit[0].submit();
                            }
                            
                        } else {
                            console.error('TCross: 表單不存在或無效');
                        }
                    } catch (error) {
                        console.error('TCross: 表單提交失敗:', error);
                        
                        // 備用方法：直接調用原生submit
                        try {
                            console.log('TCross: 嘗試備用提交方法');
                            formToSubmit[0].submit();
                        } catch (backupError) {
                            console.error('TCross: 備用提交方法也失敗:', backupError);
                            alert('註冊提交失敗，請重新嘗試');
                        }
                    }
                    
                    // 重置按鈕狀態
                    $confirmBtn.removeClass('loading').text('確認註冊');
                    
                    // 清空表單引用
                    self.clearCurrentForm();
                }, 100);
                
            }, 500); // 減少延遲時間
        },
        
        // 手動顯示註冊須知（可用於其他地方調用）
        showNotice: function(title, content) {
            this.createModal();
            
            if (title) {
                $('#tcross-modal-title').text(title);
            }
            
            if (content) {
                $('#tcross-modal-content').html(content);
            }
            
            this.showModal();
        }
    };
    
    // 當 DOM 準備好時初始化
    $(document).ready(function() {
        // 確保 tcross_ajax 變數存在或在測試環境
        if (typeof tcross_ajax !== 'undefined' || window.location.href.indexOf('test-registration-modal.html') > -1) {
            TCrossRegistrationModal.init();
        } else {
            console.warn('TCross: tcross_ajax 變數未定義，將在 1 秒後重試');
            setTimeout(function() {
                if (typeof tcross_ajax !== 'undefined') {
                    TCrossRegistrationModal.init();
                } else {
                    console.error('TCross: 無法初始化註冊模態視窗，tcross_ajax 變數缺失');
                }
            }, 1000);
        }
    });
    
    // 將模組暴露到全域範圍（可選）
    window.TCrossRegistrationModal = TCrossRegistrationModal;
    
})(jQuery);
