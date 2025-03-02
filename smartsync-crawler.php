<?php
/*
Plugin Name: SmartSync Crawler
Description: 爬取 Jarvis 網站商品資訊
Version: 1.0
Author: VinsKao
*/

// 主要爬蟲函數
function crawler_crawl_data() {
    if (!isset($_POST['crawler_action']) || $_POST['crawler_action'] !== 'start_crawl') return;
    
    set_time_limit(300);
    ini_set('memory_limit', '256M');
    
    try {
        // 創建輸出目錄
        $output_dir = __DIR__ . '/output';
        if (!file_exists($output_dir)) mkdir($output_dir, 0755, true);
        
        // 爬取分類連結
        $main_url = 'https://www.jarvis.com.tw/aqara智能居家/';
        $category_urls = crawler_get_category_urls($main_url);
        file_put_contents("$output_dir/category_urls.txt", implode("\n", $category_urls));
        
        // 準備 CSV 檔案
        $csv_file = "$output_dir/jarvis_products_" . date('Y-m-d_H-i-s') . '.csv';
        $fp = fopen($csv_file, 'w');
        fputs($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($fp, ['名稱', '簡短內容說明', '原價', '特價', '描述', '圖片', '購買備註', '外部網址']);
        fclose($fp);
        
        // 批次處理
        $start_time = time();
        $total_products = 0;
        $batch_size = 3;
        $all_images = [];
        
        for ($i = 0; $i < count($category_urls); $i += $batch_size) {
            if (time() - $start_time > 240) { // 4分鐘超時
                throw new Exception("執行時間過長，已處理 $i 個分類，總共 " . count($category_urls) . " 個。");
            }
            
            $batch_categories = array_slice($category_urls, $i, $batch_size);
            $batch_product_count = 0;
            
            foreach ($batch_categories as $category_url) {
                $category_product_data = crawler_process_page($category_url, $all_images);
                
                if (is_array($category_product_data)) {
                    $fp = fopen($csv_file, 'a');
                    foreach ($category_product_data as $row) {
                        // 準備 CSV 資料
                        $notes = is_array($row['note']) ? implode('; ', $row['note']) : $row['note'];
                        $description = (is_array($row['qa_text']) ? implode('; ', $row['qa_text']) : $row['qa_text']) . '; ' . 
                                     (is_array($row['intro_text']) ? implode('; ', $row['intro_text']) : $row['intro_text']);
                        $all_img = isset($row['images']) && is_array($row['images']) ? implode('; ', $row['images']) : '';
                        $aqara_img = isset($row['aqara_images']) && is_array($row['aqara_images']) ? implode('; ', $row['aqara_images']) : '';
                        $images = !empty($aqara_img) ? $aqara_img : $all_img;
                        fputcsv($fp, [
                            $row['title'],
                            $notes,
                            $row['price'],
                            $row['price_actual'],
                            $description,
                            $images,
                            $row['spec_image'],
                            $row['videolink_text']
                        ]);
                        $batch_product_count++;
                    }
                    fclose($fp);
                }
            }
            
            // 清理記憶體
            gc_collect_cycles();
            $total_products += $batch_product_count;
        }
        
        // 儲存圖片列表
        file_put_contents("$output_dir/all_images.txt", implode("\n", $all_images));
        
        // 儲存結果到 WordPress 選項
        update_option('crawler_last_run', current_time('mysql'));
        update_option('crawler_last_product_count', $total_products);
        update_option('crawler_last_csv', $csv_file);
        
        // 下載 CSV 檔案
        header('Content-Description: File Transfer');
        header('Content-Type: application/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($csv_file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($csv_file));
        readfile($csv_file);
        exit;
    } catch (Exception $e) {
        $error_message = '爬蟲執行過程中發生錯誤: ' . $e->getMessage();
        error_log($error_message);
        wp_die($error_message, '爬蟲錯誤', ['response' => 500]);
    }
}

// 獲取分類連結
function crawler_get_category_urls($url) {
    $url = add_query_arg('items_per_page', '96', $url);
    $html = crawler_fetch_html($url);
    if (!$html) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $category_urls = [];
    
    // 查找所有子選單連結
    $category_links = $xpath->query('//a[contains(@class, "ty-menu__submenu-link")]');
    foreach ($category_links as $link) {
        $href = $link->getAttribute('href');
        $text = trim($link->nodeValue);
        
        if (stripos($text, 'Aqara') !== false || stripos($href, 'aqara') !== false) {
            $href = crawler_normalize_url($href);
            if ($href) {
                $category_urls[] = add_query_arg('items_per_page', '96', $href);
            }
        }
    }
    
    // 檢查分頁
    $pagination_links = $xpath->query('//a[contains(@class, "ty-pagination__item")]');
    // 移除重複的 URL
    foreach ($pagination_links as $link) {
        $href = $link->getAttribute('href');
        if (!empty($href) && strpos($href, 'page=') !== false) {
            $href = crawler_normalize_url($href);
            if ($href) {
                $category_urls[] = add_query_arg('items_per_page', '96', $href);
            }
        }
    }
    
    $category_urls[] = $url;
    return array_unique($category_urls);
}

// 處理分類頁面
function crawler_process_page($url, &$all_images) {
    $html = crawler_fetch_html($url);
    if (!$html) return false;

    // 創建調試目錄
    $debug_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/crawler_debug' : __DIR__ . '/crawler_debug';
    if (!file_exists($debug_dir)) mkdir($debug_dir, 0755, true);
    
    // 保存原始 HTML 文件
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // 收集圖片 (限制數量)
    $images = $xpath->query('//img');
    $img_count = 0;
    $max_images = 100;
    
    // 收集圖片
    foreach ($images as $img) {
        if ($img_count >= $max_images) break;
        $src = $img->getAttribute('src');
        // 處理圖片 URL
        if (!empty($src)) {
            if (strpos($src, 'http') !== 0) {
                $src = (($src[0] == '/') ? 'https://www.jarvis.com.tw' : 'https://www.jarvis.com.tw/') . $src;
            }
            $all_images[] = $src;
            $img_count++;
        }
    }

    // 收集產品連結
    $product_urls = [];
    $product_selectors = [
        '//.ty-grid-list__item a',
        '//div[contains(@class, "grid-list__item")]//a',
        '//div[contains(@class, "product-item")]//a',
        '//div[contains(@class, "product")]//a[contains(@href, "product")]',
        '//a[contains(@href, "/product/")]',
        '//a[contains(@class, "product")]',
        '//a[contains(@href, "aqara")]'
    ];
    
    foreach ($product_selectors as $selector) {
        $elements = $xpath->query($selector);
        foreach ($elements as $element) {
            $href = $element->getAttribute('href');
            if (!empty($href)) {
                if (strpos($href, 'http') !== 0) {
                    $href = (($href[0] == '/') ? 'https://www.jarvis.com.tw' : 'https://www.jarvis.com.tw/') . $href;
                }
                $product_urls[] = $href;
            }
        }
    }
    
    // 去除重複的 URL 並限制數量
    $product_urls = array_slice(array_unique($product_urls), 0, 20);
    
    // 處理每個產品頁面
    $all_product_data = [];
    foreach ($product_urls as $product_url) {
        $product_data = crawler_process_product_page($product_url, $all_images);
        if ($product_data) {
            $all_product_data[] = $product_data;
        }
    }

    return $all_product_data;
}

// 處理產品頁面
function crawler_process_product_page($url, &$all_images) {
    $html = crawler_fetch_html($url);
    if (!$html) return false;

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // 定義選擇器
    $selectors = [
        'title' => ['//h1[@class="ty-mainbox-title"]', '//h1[contains(@class, "product-title")]', '//h1'],
        'note' => ['//.ty-product-feature-list__item', '//div[contains(@class, "product-features")]//li'],
        'price' => ['//.ty-price-num', '//span[contains(@class, "price")]'],
        'price_actual' => ['//.ty-price-num[@class="actual"]', '//span[contains(@class, "sale-price")]'],
        'qa_text' => ['//.ty-qa__item-text', '//div[contains(@class, "qa")]//p'],
        'intro_text' => ['//.ty-product-feature-list__description', '//div[contains(@class, "product-description")]//p'],
        'product_image' => ['//.ty-product-feature-list__image img', '//div[contains(@class, "product-image")]//img'],
        'spec_image' => ['//.ty-product-spec-list__image img', '//div[contains(@class, "product-spec")]//img'],
        'videolink_text' => ['//.ty-product-video iframe', '//iframe[contains(@src, "youtube")]']
    ];
    
    // 找出最佳選擇器
    $best_selectors = [];
    foreach ($selectors as $field => $selector_list) {
        foreach ($selector_list as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $best_selectors[$field] = $selector;
                break;
            }
        }
    }

    // 收集圖片
    $images = $xpath->query('//img');
    $product_images = [];
    $aqara_images = [];
    
    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        $alt = $img->getAttribute('alt');
        
        if (!empty($src)) {
            if (strpos($src, 'http') !== 0) {
                $src = (($src[0] == '/') ? 'https://www.jarvis.com.tw' : 'https://www.jarvis.com.tw/') . $src;
            }
            
            if (strpos($alt, 'Aqara') !== false) {
                $aqara_images[] = $src;
            }
            
            $product_images[] = $src;
            $all_images[] = $src;
        }
    }

    // 提取產品資料
    $product_data = [];
    
    // 標題
    if (isset($best_selectors['title'])) {
        $product_data['title'] = crawler_get_text($xpath, $best_selectors['title']);
    } else {
        $potential_titles = [$xpath->query('//h1')->item(0), $xpath->query('//title')->item(0)];
        foreach ($potential_titles as $title_element) {
            if ($title_element) {
                $product_data['title'] = trim($title_element->nodeValue);
                break;
            }
        }
        if (empty($product_data['title'])) $product_data['title'] = basename($url);
    }
    
    // 其他資料
    $product_data['note'] = isset($best_selectors['note']) ? crawler_get_texts($xpath, $best_selectors['note']) : [];
    $product_data['price'] = isset($best_selectors['price']) ? crawler_get_text($xpath, $best_selectors['price']) : '';
    $product_data['price_actual'] = isset($best_selectors['price_actual']) ? crawler_get_text($xpath, $best_selectors['price_actual']) : $product_data['price'];
    $product_data['qa_text'] = isset($best_selectors['qa_text']) ? crawler_get_texts($xpath, $best_selectors['qa_text']) : [];
    $product_data['intro_text'] = isset($best_selectors['intro_text']) ? crawler_get_texts($xpath, $best_selectors['intro_text']) : [];
    $product_data['product_image'] = isset($best_selectors['product_image']) ? crawler_get_attribute($xpath, $best_selectors['product_image'], 'src') : '';
    $product_data['spec_image'] = isset($best_selectors['spec_image']) ? crawler_get_attribute($xpath, $best_selectors['spec_image'], 'src') : '';
    $product_data['videolink_text'] = isset($best_selectors['videolink_text']) ? crawler_get_attribute($xpath, $best_selectors['videolink_text'], 'src') : '';
    $product_data['images'] = $product_images;
    $product_data['aqara_images'] = $aqara_images;

    return $product_data;
}

// 獲取 HTML 內容
function crawler_fetch_html($url) {
    // 使用 file_get_contents 作為備選方案
    if (!function_exists('curl_init')) {
        $opts = ['http' => ['method' => 'GET', 'header' => 'User-Agent: Mozilla/5.0', 'timeout' => 15]];
        $context = stream_context_create($opts);
        return @file_get_contents($url, false, $context);
    }

    // 使用 cURL
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $html = curl_exec($ch);
    
    if (curl_errno($ch)) {
        curl_close($ch);
        return false;
    }
    
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($status_code < 400) ? $html : false;
}

// 輔助函數
function crawler_get_text($xpath, $query) {
    $element = $xpath->query($query)->item(0);
    return $element ? trim($element->nodeValue) : '';
}

function crawler_get_texts($xpath, $query) {
    $elements = $xpath->query($query);
    $texts = [];
    foreach ($elements as $element) $texts[] = trim($element->nodeValue);
    return $texts;
}

function crawler_get_attribute($xpath, $query, $attribute) {
    $element = $xpath->query($query)->item(0);
    return $element ? $element->getAttribute($attribute) : '';
}

// WordPress 管理頁面
function crawler_admin_menu() {
    add_menu_page('SmartSync Crawler', 'SmartSync', 'manage_options', 'crawler-admin', 'crawler_admin_page', 'dashicons-filter', 20);
}
add_action('admin_menu', 'crawler_admin_menu');

function crawler_admin_page() {
    ?>
    <div class="wrap">
        <h1>SmartSync Crawler</h1>
        
        <?php if (isset($_GET['crawl_complete']) && $_GET['crawl_complete'] == 1): ?>
            <div class="notice notice-success"><p>爬蟲已完成運行！</p></div>
        <?php endif; ?>
        
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="run_crawler">
            <input type="hidden" name="crawler_action" value="start_crawl">
            <?php wp_nonce_field('run_crawler_nonce', 'crawler_nonce'); ?>
            
            <p>點擊以下按鈕開始爬取 Jarvis 網站商品資訊並下載 CSV 檔案。本次爬蟲將先進入各分類頁面並優先收集所有圖片，並特別識別標記為「Aqara」系列的圖片。</p>
            
            <div class="notice notice-warning">
                <p><strong>注意事項：</strong></p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>爬蟲過程可能需要較長時間，請耐心等待。</li>
                    <li>每個分類頁面最多處理 20 個產品，以避免超時錯誤。</li>
                    <li>分類連結將保存在 output/category_urls.txt 文件中。</li>
                    <li>如果處理時間過長，爬蟲會自動中斷並下載已爬取的部分數據。</li>
                </ul>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="開始爬蟲並下載 CSV">
            </p>
        </form>
        
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="get_categories_only">
            <?php wp_nonce_field('get_categories_nonce', 'categories_nonce'); ?>
            
            <p>如果您只想獲取分類連結而不進行完整爬蟲，請點擊以下按鈕：</p>
            
            <p class="submit">
                <input type="submit" name="submit" id="submit_categories" class="button button-secondary" value="僅獲取分類連結">
            </p>
        </form>
        
        <?php
        $last_run = get_option('crawler_last_run');
        if ($last_run) {
            echo '<h2>上次爬蟲時間</h2><p>' . $last_run . '</p>';
            
            $product_count = get_option('crawler_last_product_count');
            if ($product_count) {
                echo '<h2>爬取的產品數量</h2><p>' . $product_count . ' 筆</p>';
            }
            
            $output_dir = __DIR__ . '/output';
            $categories_file = $output_dir . '/category_urls.txt';
            if (file_exists($categories_file)) {
                echo '<h2>分類連結</h2>';
                echo '<p>分類連結已保存到: ' . $categories_file . '</p>';
                
                // 添加下載按鈕
                echo '<p><a href="' . admin_url('admin-post.php?action=download_category_urls') . '" class="button button-secondary">下載分類連結檔案</a></p>';
                
                if (filesize($categories_file) < 10240) {
                    echo '<pre style="max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;">';
                    echo htmlspecialchars(file_get_contents($categories_file));
                    echo '</pre>';
                } else {
                    echo '<p>檔案太大，請直接查看檔案內容。</p>';
                }
            }
        }
        ?>
    </div>
    <?php
}

// 處理表單提交
function crawler_handle_post() {
    if (!isset($_POST['crawler_nonce']) || !wp_verify_nonce($_POST['crawler_nonce'], 'run_crawler_nonce')) {
        wp_die('安全檢查失敗');
    }
    crawler_crawl_data();
}
add_action('admin_post_run_crawler', 'crawler_handle_post');

// WP-CLI 命令
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('smartsync crawl', function() {
        if (!defined('ABSPATH')) {
            WP_CLI::error('必須在 WordPress 安裝目錄中執行此命令');
            return;
        }
        
        $main_url = 'https://www.jarvis.com.tw/aqara智能居家/';
        $category_urls = crawler_get_category_urls($main_url);
        $all_product_data = [];
        $all_images = [];
        
        foreach ($category_urls as $category_url) {
            WP_CLI::log('正在處理分類：' . $category_url);
            $category_product_data = crawler_process_page($category_url, $all_images);
            if (is_array($category_product_data)) {
                $all_product_data = array_merge($all_product_data, $category_product_data);
            }
        }
        
        if (empty($all_product_data)) {
            WP_CLI::error('爬蟲執行失敗');
            return;
        }
        
        update_option('crawler_last_results', $all_product_data);
        update_option('crawler_last_images', $all_images);
        update_option('crawler_last_run', current_time('mysql'));
        
        $filename = 'jarvis_products_' . date('Y-m-d_H-i-s') . '.csv';
        $file_path = WP_CONTENT_DIR . '/' . $filename;
        
        $output = fopen($file_path, 'w');
        fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM
        
        fputcsv($output, ['名稱', '簡短內容說明', '原價', '特價', '描述', '圖片', '購買備註', '外部網址']);
        
        foreach ($all_product_data as $row) {
            $notes = is_array($row['note']) ? implode('; ', $row['note']) : $row['note'];
            $description = (is_array($row['qa_text']) ? implode('; ', $row['qa_text']) : $row['qa_text']) . '; ' . 
                          (is_array($row['intro_text']) ? implode('; ', $row['intro_text']) : $row['intro_text']);
            $all_img = isset($row['images']) && is_array($row['images']) ? implode('; ', $row['images']) : '';
            $aqara_img = isset($row['aqara_images']) && is_array($row['aqara_images']) ? implode('; ', $row['aqara_images']) : '';
            $images = !empty($aqara_img) ? $aqara_img : $all_img;
            
            fputcsv($output, [
                $row['title'],
                $notes,
                $row['price'],
                $row['price_actual'],
                $description,
                $images,
                $row['spec_image'],
                $row['videolink_text']
            ]);
        }
        
        fclose($output);
        
        WP_CLI::success('爬蟲已完成運行！共爬取 ' . count($all_product_data) . ' 筆資料，收集 ' . count($all_images) . ' 張圖片。CSV 文件已保存到：' . $file_path);
    });
}

// 新增共用函數
function crawler_normalize_url($url) {
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    if (strpos($url, 'http') !== 0 && !empty($url) && $url != '#') {
        return (($url[0] == '/') ? 'https://www.jarvis.com.tw' : 'https://www.jarvis.com.tw/') . $url;
    }
    return $url;
}

function crawler_download_file($file_path, $mime_type = 'text/plain') {
    if (!file_exists($file_path)) {
        wp_die('檔案不存在');
    }
    
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}

function crawler_write_csv($file_path, $data, $headers = null) {
    $fp = fopen($file_path, 'w');
    fputs($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
    
    if ($headers) {
        fputcsv($fp, $headers);
    }
    
    foreach ($data as $row) {
        $notes = is_array($row['note']) ? implode('; ', $row['note']) : $row['note'];
        $description = (is_array($row['qa_text']) ? implode('; ', $row['qa_text']) : $row['qa_text']) . '; ' . 
                      (is_array($row['intro_text']) ? implode('; ', $row['intro_text']) : $row['intro_text']);
        $all_img = isset($row['images']) && is_array($row['images']) ? implode('; ', $row['images']) : '';
        $aqara_img = isset($row['aqara_images']) && is_array($row['aqara_images']) ? implode('; ', $row['aqara_images']) : '';
        $images = !empty($aqara_img) ? $aqara_img : $all_img;
        
        fputcsv($fp, [
            $row['title'],
            $notes,
            $row['price'],
            $row['price_actual'],
            $description,
            $images,
            $row['spec_image'],
            $row['videolink_text']
        ]);
    }
    
    fclose($fp);
}

// 修改下載處理函數
function crawler_download_category_urls() {
    $output_dir = __DIR__ . '/output';
    $categories_file = $output_dir . '/category_urls.txt';
    crawler_download_file($categories_file, 'text/plain');
}

function crawler_get_categories_only() {
    if (!isset($_POST['categories_nonce']) || !wp_verify_nonce($_POST['categories_nonce'], 'get_categories_nonce')) {
        wp_die('安全檢查失敗');
    }
    
    try {
        $output_dir = __DIR__ . '/output';
        if (!file_exists($output_dir)) mkdir($output_dir, 0755, true);
        
        $main_url = 'https://www.jarvis.com.tw/aqara智能居家/';
        $category_urls = crawler_get_category_urls($main_url);
        
        $categories_file = "$output_dir/category_urls.txt";
        file_put_contents($categories_file, implode("\n", $category_urls));
        update_option('crawler_last_run', current_time('mysql'));
        
        crawler_download_file($categories_file, 'text/plain');
    } catch (Exception $e) {
        wp_die('獲取分類連結時發生錯誤: ' . $e->getMessage(), '爬蟲錯誤', ['response' => 500]);
    }
}
add_action('admin_post_get_categories_only', 'crawler_get_categories_only');
?>