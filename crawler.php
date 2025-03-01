<?php
/*
Plugin Name: SmartSync Crawler
Description: 爬取 Jarvis 網站商品資訊
Version: 1.0
Author: VinsKao
*/

function crawler_crawl_data() {
    // 只在管理員觸發時執行
    if (!isset($_POST['crawler_action']) || $_POST['crawler_action'] !== 'start_crawl') {
        return;
    }
    
    $start_url = 'https://www.jarvis.com.tw/aqara智能居家/';
    $product_data = crawler_process_page($start_url);

    // 儲存結果到 WordPress 選項
    update_option('crawler_last_results', $product_data);
    update_option('crawler_last_run', current_time('mysql'));
    
    // 重定向回管理頁面
    wp_redirect(admin_url('admin.php?page=crawler-admin&crawl_complete=1'));
    exit;
}

function crawler_process_page($url) {
    $html = crawler_fetch_html($url);
    if (!$html) {
        return false;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $product_urls = [];
    $product_elements = $xpath->query('//.ty-grid-list__item a');
    foreach ($product_elements as $element) {
        $product_urls[] = $element->getAttribute('href');
    }

    $all_product_data = [];
    foreach ($product_urls as $product_url) {
        $all_product_data[] = crawler_process_product_page($product_url);
    }

    // 處理下一頁
    $next_page_element = $xpath->query('//link[@rel="next"]/@href')->item(0);
    if ($next_page_element) {
        $next_page_url = $next_page_element->nodeValue;
        $all_product_data = array_merge($all_product_data, crawler_process_page($next_page_url));
    }

    return $all_product_data;
}

function crawler_process_product_page($url) {
    $html = crawler_fetch_html($url);
    if (!$html) {
        return false;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $product_data = [];
    $product_data['title'] = crawler_get_text($xpath, '//h1[@class="ty-mainbox-title"]');
    $product_data['note'] = crawler_get_texts($xpath, '//.ty-product-feature-list__item');
    $product_data['price'] = crawler_get_text($xpath, '//.ty-price-num');
    $product_data['price_actual'] = crawler_get_text($xpath, '//.ty-price-num[@class="actual"]');
    $product_data['qa_text'] = crawler_get_texts($xpath, '//.ty-qa__item-text');
    $product_data['intro_text'] = crawler_get_texts($xpath, '//.ty-product-feature-list__description');
    $product_data['product_image'] = crawler_get_attribute($xpath, '//.ty-product-feature-list__image img', 'src');
    $product_data['spec_image'] = crawler_get_attribute($xpath, '//.ty-product-spec-list__image img', 'src');
    $product_data['videolink_text'] = crawler_get_attribute($xpath, '//.ty-product-video iframe', 'src');

    return $product_data;
}

function crawler_fetch_html($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

function crawler_get_text($xpath, $query) {
    $element = $xpath->query($query)->item(0);
    return $element ? trim($element->nodeValue) : '';
}

function crawler_get_texts($xpath, $query) {
    $elements = $xpath->query($query);
    $texts = [];
    foreach ($elements as $element) {
        $texts[] = trim($element->nodeValue);
    }
    return $texts;
}

function crawler_get_attribute($xpath, $query, $attribute) {
    $element = $xpath->query($query)->item(0);
    return $element ? $element->getAttribute($attribute) : '';
}

// 移除原有的 init hook
remove_action('init', 'crawler_crawl_data');

// 新增管理頁面
function crawler_admin_menu() {
    add_menu_page(
        'SmartSync Crawler', 
        'SmartSync', 
        'manage_options',
        'crawler-admin',
        'crawler_admin_page',
        'dashicons-filter',
        20
    );
}
add_action('admin_menu', 'crawler_admin_menu');

// 管理頁面內容
function crawler_admin_page() {
    ?>
    <div class="wrap">
        <h1>SmartSync Crawler</h1>
        
        <?php if (isset($_GET['crawl_complete']) && $_GET['crawl_complete'] == 1): ?>
            <div class="notice notice-success">
                <p>爬蟲已完成運行！</p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="run_crawler">
            <input type="hidden" name="crawler_action" value="start_crawl">
            <?php wp_nonce_field('run_crawler_nonce', 'crawler_nonce'); ?>
            
            <p>點擊以下按鈕開始爬取 Jarvis 網站商品資訊。</p>
            
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="開始爬蟲">
            </p>
        </form>
        
        <?php
        // 顯示上次爬蟲結果
        $last_run = get_option('crawler_last_run');
        $last_results = get_option('crawler_last_results');
        
        if ($last_run) {
            echo '<h2>上次爬蟲時間</h2>';
            echo '<p>' . $last_run . '</p>';
            
            if ($last_results) {
                echo '<h2>爬蟲結果</h2>';
                echo '<pre>';
                print_r($last_results);
                echo '</pre>';
            }
        }
        ?>
    </div>
    <?php
}

// 處理提交的表單
function crawler_handle_post() {
    // 檢查安全性
    if (!isset($_POST['crawler_nonce']) || !wp_verify_nonce($_POST['crawler_nonce'], 'run_crawler_nonce')) {
        wp_die('安全檢查失敗');
    }
    
    // 執行爬蟲
    crawler_crawl_data();
}
add_action('admin_post_run_crawler', 'crawler_handle_post');

// 添加WP-CLI命令
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('smartsync crawl', 'crawler_cli_command');
    
    function crawler_cli_command() {
        // 確認是否在 WordPress 環境中
        if (!defined('ABSPATH')) {
            WP_CLI::error('必須在 WordPress 安裝目錄中執行此命令');
            return;
        }
        
        $start_url = 'https://www.jarvis.com.tw/aqara智能居家/';
        $product_data = crawler_process_page($start_url);
        
        if ($product_data === false) {
            WP_CLI::error('爬蟲執行失敗');
            return;
        }
        
        // 儲存結果到 WordPress 選項
        update_option('crawler_last_results', $product_data);
        update_option('crawler_last_run', current_time('mysql'));
        
        WP_CLI::success('爬蟲已完成運行！共爬取 ' . count($product_data) . ' 筆資料');
    }
}
?>