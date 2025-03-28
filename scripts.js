/**
 * 確保jQuery正確加載並執行手風琴功能
 * 這段代碼確保即便WordPress沒有正確加載jQuery也能正常工作
 */

// 方法1: 確保jQuery已加載後執行
function initCustomerizedAccordion() {
    if (typeof jQuery === 'undefined') {
        // 如果jQuery未加載，嘗試在1秒後再次檢查
        setTimeout(initCustomerizedAccordion, 1000);
        return;
    }
    
    (function($) {
        $(document).ready(function() {
            // 初始化手風琴功能
            $('.customerized-accordion .customerized-accordion-title').on('click', function() {
                var $accordionItem = $(this).closest('.customerized-accordion-item');
                var $content = $accordionItem.find('.customerized-accordion-content');
                
                if ($(this).hasClass('active')) {
                    $(this).removeClass('active');
                    $content.slideUp(200, 'swing');
                } else {
                    // Close all other accordion items
                    $(this).closest('.customerized-accordion').find('.customerized-accordion-title').removeClass('active');
                    $(this).closest('.customerized-accordion').find('.customerized-accordion-content').slideUp(200, 'swing');
                    
                    // Open clicked accordion item
                    $(this).addClass('active');
                    $content.slideDown(200, 'swing');
                }
            });
            
            // 確保頁面加載時手風琴是關閉狀態
            $('.customerized-accordion .customerized-accordion-content').hide();
            
            console.log('Customerized Accordion initialized with jQuery version: ' + $.fn.jquery);
        });
    })(jQuery);
}

// 啟動初始化過程
initCustomerizedAccordion();

// 方法2: 動態加載jQuery如果它不存在
function loadjQuery(callback) {
    if (typeof jQuery === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://code.jquery.com/jquery-3.6.4.min.js';
        script.integrity = 'sha256-oP6HI9z1XaZNBrJURtCoUT5SUnxFr8s3BzRl+cbzUq8=';
        script.crossOrigin = 'anonymous';
        script.onload = callback;
        document.head.appendChild(script);
    } else {
        callback();
    }
}

// 使用方法2作為備份
if (typeof jQuery === 'undefined') {
    loadjQuery(function() {
        console.log('jQuery dynamically loaded');
        initCustomerizedAccordion();
    });
}

// WordPress正規的jQuery依賴註冊方式 (PHP代碼，供參考)
/*
function enqueue_accordion_script() {
    wp_enqueue_script('jquery'); // 確保jQuery加載
    wp_enqueue_script(
        'custom-elementor-accordion', 
        get_template_directory_uri() . '/path/to/accordion-script.js',
        array('jquery'),
        '1.0.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'enqueue_accordion_script');
*/