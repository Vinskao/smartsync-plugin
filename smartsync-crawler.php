<?php
/*
Plugin Name: SmartSync Crawler
Description: 爬取 Jarvis 網站商品資訊
Version: 1.9
Author: VinsKao
*/

// 註冊管理菜單
function crawler_admin_menu() {
    add_menu_page(
        'SmartSync Crawler', // 頁面標題
        'SmartSync',         // 菜單標題
        'manage_options',    // 所需權限
        'smart_crawler',     // 菜單 slug
        'crawler_admin_page',// 回調函數
        'dashicons-filter',  // 圖標
        20                   // 菜單位置
    );
}
add_action('admin_menu', 'crawler_admin_menu');

// 獲取所有產品 URL
function crawler_get_product_urls($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return [];
    
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $product_urls = [];

    // 精確匹配 class 為 product-title 的連結
    $product_links = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " product-title ")]');
    
    foreach ($product_links as $link) {
            $href = crawler_normalize_url($link->getAttribute('href'));
        if (!empty($href) && strpos($href, 'http') === 0) {
            // 排除以 https://www.jarvis.com.tw/優惠專區/ 開頭的 URL
            if (strpos($href, 'https://www.jarvis.com.tw/優惠專區/') !== 0) {
                // 對 URL 進行編碼處理，避免中文字亂碼
                $encoded_href = urlencode($href);
                $product_urls[] = urldecode($encoded_href); // 解碼後存儲，確保可讀性
            }
        }
    }

    // 使用 array_unique 過濾重複的 URL
    return array_values(array_unique($product_urls));
}

// 獲取產品名稱
function crawler_get_product_name($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '';
        
        $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
        $xpath = new DOMXPath($dom);
    $title = $xpath->query('//title');

    if ($title->length > 0) {
        return trim($title->item(0)->nodeValue);
    }

    return '';
}

// 獲取產品簡短描述
function crawler_get_product_short_description($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $description = $xpath->query('//meta[@name="description"]');

    if ($description->length > 0) {
        return trim($description->item(0)->getAttribute('content'));
    }

    return '';
}

// 獲取產品描述（修改版 - 移除重複的「賣場需知」段落）
function crawler_get_styled_description($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '';
    
    // 使用DOM提取並重建圖片標籤
    $dom_images = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom_images->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    $xpath_images = new DOMXPath($dom_images);
    $img_nodes = $xpath_images->query('//img[contains(@src, ".png")]');
    
    $png_html = '';
    foreach ($img_nodes as $img) {
        $src = $img->getAttribute('src');
        // 排除不需要的圖片路徑
        if (strpos($src, '/footer_icon/') !== false ||
            strpos($src, '/logos/') !== false ||
            strpos($src, '/ICON/') !== false ||
            strpos($src, 'download') !== false ||
            strpos($src, 'meta property') !== false) {
            continue;
        }
        
        // 重建圖片標籤，確保屬性格式正確
        $new_img = $dom_images->createElement('img');
        $new_img->setAttribute('src', $src);
        $new_img->setAttribute('alt', $img->getAttribute('alt'));
        $new_img->setAttribute('title', $img->getAttribute('title'));
        
        // 處理class屬性（移除多餘空格和引號）
        $class = trim(preg_replace('/\s+/', ' ', $img->getAttribute('class')));
        if (!empty($class)) {
            $new_img->setAttribute('class', $class);
        }
        
        $png_html .= $dom_images->saveHTML($new_img);
    }

    // 使用DOM提取樣式區域，保留整段HTML元素
    $dom_style = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom_style->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    $xpath_style = new DOMXPath($dom_style);
    $style_elements = $xpath_style->query("//*[contains(@style, \"font-family: 'Microsoft JhengHei', SimHei, Arial;\")]");
    
    // 使用集合來追蹤已處理過的父元素以避免重複
    $processed_parents = [];
    $style_html = '';
    
    if ($style_elements->length > 0) {
        foreach ($style_elements as $element) {
            // 保留整段HTML元素，而不是只保留span
            $parent = $element->parentNode;
            while ($parent && $parent->nodeName != 'body') {
                // 使用nodeValue檢查是否已經處理過相似內容
                $content = $parent->nodeValue;
                $hash = md5($content);
                
                if (!isset($processed_parents[$hash])) {
                    $processed_parents[$hash] = true;
                    $style_html .= $dom_style->saveHTML($parent);
                }
                break; // 只保留最外層的父元素
            }
        }
    }
    
    // 合併內容並在圖片和文字之間加入換行符號，然後徹底移除超連結
    $final_html = $png_html . '<br>' . $style_html;
    
    // 使用正則徹底移除所有超連結（包括嵌套情況）
    $final_html = preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $final_html);
    
    // 規範化屬性引號（處理多餘引號問題）
    $final_html = preg_replace('/\s*=\s*""/', '=""', $final_html); // 統一引號格式
    $final_html = preg_replace('/(<img[^>]+)\s+>/', '$1>', $final_html); // 移除多餘空格
    
    // 移除重複的「賣場需知」段落
    $final_html = preg_replace('/(<p><span[^>]*><strong><span[^>]*>賣場需知<\/span><\/strong><\/span>.*?)<p><span[^>]*><strong><span[^>]*>賣場需知<\/span><\/strong><\/span>/s', '$1', $final_html);
    
    return trim($final_html);
}

// 獲取產品實際價格
function crawler_get_product_actual_price($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $price = $xpath->query('//div[@itemprop="offers"]//meta[@itemprop="price"]');

    if ($price->length > 0) {
        return trim($price->item(0)->getAttribute('content'));
    }

    return '';
}

// 獲取產品原價
function crawler_get_original_price($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $price_spans = $xpath->query('//span[@class="ty-strike"]');

    if ($price_spans->length === 0) {
        return '';
    }

    // 獲取第一個匹配的原價
    $price_text = trim($price_spans->item(0)->nodeValue);
    // 清理價格文本，只保留數字和逗號
    $price_text = preg_replace('/[^0-9,]/', '', $price_text);
    
    return $price_text;
}

// 獲取商品圖片連結
function crawler_get_product_images($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $images = $xpath->query('//img[contains(@src, ".png")]');

    $image_links = [];
    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        if (!empty($src)) {
            // 排除包含 /footer_icon/、/logos/ 和 /ICON/ 的圖片連結
            if (strpos($src, '/footer_icon/') === false &&
                strpos($src, '/logos/') === false &&
                strpos($src, '/ICON/') === false) {
                $image_links[] = $src;
            }
        }
    }

    // 用逗號分隔所有圖片連結
    return implode(',', $image_links);
}

// 處理 CSV 下載 (修改版 - 欄位重新排序)
function crawler_download_product_urls() {
    if (!isset($_POST['crawler_nonce']) || !wp_verify_nonce($_POST['crawler_nonce'], 'run_crawler_nonce')) {
        wp_die('安全檢查失敗', 403);
    }

    if (!current_user_can('manage_options')) {
        wp_die('權限不足', 403);
    }

    try {
        // 獲取爬蟲範圍 (1-based轉為0-based)
        $start_index = isset($_POST['start_index']) ? (intval($_POST['start_index']) - 1) : 0;
        $end_index = isset($_POST['end_index']) ? (intval($_POST['end_index']) - 1) : 19;
        
        // 確保區間合理
        if ($start_index < 0) $start_index = 0;
        if ($end_index < $start_index) $end_index = $start_index;
        
        $output_dir = __DIR__ . '/output';
        if (!wp_mkdir_p($output_dir)) {
            throw new Exception('無法建立輸出目錄');
        }

        // 強制使用96每頁的URL
        $main_url = 'https://www.jarvis.com.tw/aqara%E6%99%BA%E8%83%BD%E5%B1%85%E5%AE%B6/?items_per_page=96';
        
        $all_product_urls = crawler_get_product_urls($main_url);
        if (empty($all_product_urls)) {
            throw new Exception('未找到任何產品連結');
        }
        
        // 根據範圍篩選URL
        $total_urls = count($all_product_urls);
        if ($start_index >= $total_urls) {
            throw new Exception('起始索引超出URL總數範圍');
        }
        if ($end_index >= $total_urls) {
            $end_index = $total_urls - 1;
        }
        
        $product_urls = array_slice($all_product_urls, $start_index, ($end_index - $start_index + 1));
        
        // 獲取所有產品數據（根據新的欄位順序）
        $product_names = [];
        $product_short_descriptions = [];
        $product_styled_descriptions = [];
        $product_actual_prices = [];
        $product_original_prices = [];
        $product_images = [];
        
        foreach ($product_urls as $url) {
            $product_names[] = crawler_get_product_name($url);
            $product_short_descriptions[] = crawler_get_product_short_description($url);
            $product_styled_descriptions[] = crawler_get_styled_description($url);
            $product_actual_prices[] = crawler_get_product_actual_price($url);
            $product_original_prices[] = crawler_get_original_price($url);
            $product_images[] = crawler_get_product_images($url);
        }

        // 寫入CSV文件（確保UTF-8編碼）
        $csv_file = "$output_dir/product_data.csv";
        $file_handle = fopen($csv_file, 'w');
        if (!$file_handle) {
            throw new Exception('無法建立CSV文件');
        }
        
        // 寫入UTF-8 BOM，確保Excel等軟件正確識別編碼
        fwrite($file_handle, "\xEF\xBB\xBF");
        
        // 寫入表頭（根據新的欄位順序）
        fputcsv($file_handle, [
            'URL', '名稱', '簡短內容說明', '描述', '特價', '原價', '圖片'
        ]);
        
        // 寫入數據（根據新的欄位順序）
        foreach ($product_urls as $index => $url) {
            fputcsv($file_handle, [
                $url, 
                $product_names[$index], 
                $product_short_descriptions[$index], 
                $product_styled_descriptions[$index],
                $product_actual_prices[$index], 
                $product_original_prices[$index],
                $product_images[$index]
            ]);
        }

        fclose($file_handle);

        // 直接輸出文件
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="product_data.csv"');
        readfile($csv_file);
        exit;

    } catch (Exception $e) {
        // 錯誤處理
        add_settings_error(
            'crawler_error',
            'crawler-error',
            '操作失敗: ' . $e->getMessage(),
            'error'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_redirect(admin_url('admin.php?page=smart_crawler'));
        exit;
    }
}
add_action('admin_post_download_product_urls', 'crawler_download_product_urls');

// 管理頁面
function crawler_admin_page() {
    // 獲取所有產品 URL 來計算總數
    $main_url = 'https://www.jarvis.com.tw/aqara%E6%99%BA%E8%83%BD%E5%B1%85%E5%AE%B6/?items_per_page=96';
    $all_product_urls = [];
    
    try {
        $all_product_urls = crawler_get_product_urls($main_url);
    } catch (Exception $e) {
        add_settings_error(
            'crawler_error',
            'crawler-error',
            '獲取產品URL失敗: ' . $e->getMessage(),
            'error'
        );
    }
    
    $total_urls = count($all_product_urls);
    ?>
    <div class="wrap">
        <h1>SmartSync Crawler</h1>
        <?php settings_errors('crawler_error'); ?>
        
        <p><strong>注意：</strong>操作筆數越多等待時間越長，請耐心等待，期間請勿進行任何操作，建議點擊兩次按鈕確保有觸發爬蟲。如果瀏覽器停止運轉，就再按一下按鈕，建議視伺服器效能適量調整爬取資料量，20筆約10分鐘</p>
        
        <h3>目前可爬取的產品總數：<?php echo $total_urls; ?> 筆</h3>
        
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="download_product_urls">
            <?php wp_nonce_field('run_crawler_nonce', 'crawler_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="start_index">從第幾筆開始：</label></th>
                    <td>
                        <input type="number" name="start_index" id="start_index" min="1" value="1" class="regular-text">
                        <p class="description">起始索引 (從1開始計算)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="end_index">到第幾筆結束：</label></th>
                    <td>
                        <input type="number" name="end_index" id="end_index" min="1" value="20" class="regular-text">
                        <p class="description">結束索引 (最大值：<?php echo $total_urls; ?>)</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" class="button button-primary" value="下載產品資料 CSV">
            </p>
        </form>
    </div>
    <?php
}

// 工具函數
function crawler_fetch_html($url) {
    $args = array(
        'timeout'    => 300, // 超時時間設置為 300 秒
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36'
    );
    
    $response = wp_remote_get($url, $args);
    
    if (is_wp_error($response)) {
        throw new Exception('無法取得網頁內容: ' . $response->get_error_message());
    }
    
    return wp_remote_retrieve_body($response);
}

function crawler_normalize_url($url) {
    return trim(htmlspecialchars_decode($url));
}