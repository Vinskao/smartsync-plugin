<?php
/*
Plugin Name: SmartSync Crawler
Description: 爬取 Jarvis 網站商品資訊
Version: 1.7
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
    if (!$html) return '未知產品';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $title = $xpath->query('//title');

    if ($title->length > 0) {
        return trim($title->item(0)->nodeValue);
    }

    return '未知產品';
}

// 獲取產品簡短描述
function crawler_get_product_short_description($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '無描述';
        
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $description = $xpath->query('//meta[@name="description"]');

    if ($description->length > 0) {
        return trim($description->item(0)->getAttribute('content'));
    }

    return '無描述';
}

// 獲取產品實際價格
function crawler_get_product_actual_price($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '無價格';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $price = $xpath->query('//div[@itemprop="offers"]//meta[@itemprop="price"]');

    if ($price->length > 0) {
        return trim($price->item(0)->getAttribute('content'));
    }

    return '無價格';
}

// 獲取產品描述
function crawler_get_product_description($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '無介紹';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $intro = $xpath->query('//meta[@itemprop="description"]');

    if ($intro->length > 0) {
        return trim($intro->item(0)->getAttribute('content'));
    }

    return '無介紹';
}

// 獲取商品圖片連結
function crawler_get_product_images($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '無圖片';

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

// 獲取商品影片連結
function crawler_get_product_video($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $iframes = $xpath->query('//iframe');

    foreach ($iframes as $iframe) {
        $src = $iframe->getAttribute('src');
        if (strpos($src, 'https://www.youtube.com/embed') !== false) {
            return trim($src);
        }
    }

    return '';
}

// 獲取商品文章內容
function crawler_get_product_articles($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '無描述內容';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制HTML解析警告
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $description_div = $xpath->query('//div[@id="content_description"]');

    if ($description_div->length === 0) {
        return '無描述內容';
    }

    $content = [];
    // 抓取所有 <p> 和 <span> 的文字內容
    $paragraphs = $xpath->query('.//p', $description_div->item(0));
    $spans = $xpath->query('.//span', $description_div->item(0));

    foreach ($paragraphs as $p) {
        $text = trim($p->nodeValue);
        if (!empty($text)) {
            $content[] = $text;
        }
    }

    foreach ($spans as $span) {
        $text = trim($span->nodeValue);
        if (!empty($text)) {
            $content[] = $text;
        }
    }

    // 用 "++" 分隔所有段落和 span 的文字內容
    return implode('++', $content);
}

// 獲取產品注意事項與 QA（修改版 - 防止數據重複）
function crawler_get_product_notes($url) {
    $html = crawler_fetch_html($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $notes_div = $xpath->query('//div[contains(., "產品注意事項")]');

    if ($notes_div->length === 0) {
        return '';
    }

    // 使用關聯數組避免重複內容
    $unique_content = [];
    $paragraphs = $xpath->query('.//p | .//span', $notes_div->item(0));

    foreach ($paragraphs as $node) {
        $text = trim($node->nodeValue);
        if (!empty($text)) {
            // 使用內容作為鍵確保唯一性
            $unique_content[$text] = $text;
        }
    }

    // 轉換為陣列並合併
    return implode('++', array_values($unique_content));
}

// 處理 CSV 下載 (修正版)
function crawler_download_product_urls() {
    if (!isset($_POST['crawler_nonce']) || !wp_verify_nonce($_POST['crawler_nonce'], 'run_crawler_nonce')) {
        wp_die('安全檢查失敗', 403);
    }

    if (!current_user_can('manage_options')) {
        wp_die('權限不足', 403);
    }

    try {
        $output_dir = __DIR__ . '/output';
        if (!wp_mkdir_p($output_dir)) {
            throw new Exception('無法建立輸出目錄');
        }

        // 強制使用96每頁的URL
        $main_url = 'https://www.jarvis.com.tw/aqara%E6%99%BA%E8%83%BD%E5%B1%85%E5%AE%B6/?items_per_page=96';
        
        $product_urls = crawler_get_product_urls($main_url);
        if (empty($product_urls)) {
            throw new Exception('未找到任何產品連結');
        }

        // 獲取所有產品名稱、簡短描述、實際價格、描述、圖片、影片和文章內容
        $product_names = [];
        $product_short_descriptions = [];
        $product_actual_prices = [];
        $product_descriptions = [];
        $product_images = [];
        $product_videos = [];
        $product_articles = [];
        foreach ($product_urls as $url) {
            $product_names[] = crawler_get_product_name($url);
            $product_short_descriptions[] = crawler_get_product_short_description($url);
            $product_actual_prices[] = crawler_get_product_actual_price($url);
            $product_descriptions[] = crawler_get_product_description($url);
            $product_images[] = crawler_get_product_images($url);
            $product_videos[] = crawler_get_product_video($url);
            $product_articles[] = crawler_get_product_articles($url);
        }

        // 寫入CSV文件（確保UTF-8編碼）
        $csv_file = "$output_dir/product_urls.csv";
        $file_handle = fopen($csv_file, 'w');
        if (!$file_handle) {
            throw new Exception('無法建立CSV文件');
        }
        
        // 寫入UTF-8 BOM，確保Excel等軟件正確識別編碼
        fwrite($file_handle, "\xEF\xBB\xBF");
        
        // 寫入表頭（修改欄位名稱）
        fputcsv($file_handle, ['URL', 'Name', 'Short Description', 'ActualPrice', 'Description', 'Images', 'Video', 'Articles']);
        
        // 寫入數據
        foreach ($product_urls as $index => $url) {
            fputcsv($file_handle, [
                $url, 
                $product_names[$index], 
                $product_short_descriptions[$index], 
                $product_actual_prices[$index], 
                $product_descriptions[$index], 
                $product_images[$index], 
                $product_videos[$index],
                $product_articles[$index]
            ]);
        }

        fclose($file_handle);

        // 直接輸出文件
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="product_urls.csv"');
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

// 處理注意事項 CSV 下載（修改版 - 防止數據重複）
function crawler_download_product_notes() {
    if (!isset($_POST['crawler_nonce']) || !wp_verify_nonce($_POST['crawler_nonce'], 'run_crawler_nonce')) {
        wp_die('安全檢查失敗', 403);
    }

    if (!current_user_can('manage_options')) {
        wp_die('權限不足', 403);
    }

    try {
        $output_dir = __DIR__ . '/output';
        if (!wp_mkdir_p($output_dir)) {
            throw new Exception('無法建立輸出目錄');
        }

        // 強制使用96每頁的URL
        $main_url = 'https://www.jarvis.com.tw/aqara%E6%99%BA%E8%83%BD%E5%B1%85%E5%AE%B6/?items_per_page=96';
        
        $product_urls = crawler_get_product_urls($main_url);
        if (empty($product_urls)) {
            throw new Exception('未找到任何產品連結');
        }

        // 獲取所有產品 URL 和注意事項內容
        $product_notes_data = [];
        foreach ($product_urls as $url) {
            $raw_content = crawler_get_product_notes($url);
            $product_notes_data[] = $raw_content;
        }

        // 寫入CSV文件（確保UTF-8編碼）
        $csv_file = "$output_dir/product_notes.csv";
        $file_handle = fopen($csv_file, 'w');
        if (!$file_handle) {
            throw new Exception('無法建立CSV文件');
        }
        
        // 寫入UTF-8 BOM，確保Excel等軟件正確識別編碼
        fwrite($file_handle, "\xEF\xBB\xBF");
        
        // 寫入表頭
        fputcsv($file_handle, ['URL', '產品注意事項', '產品QA']);
        
        // 數據處理邏輯 - 數據去重
        foreach ($product_urls as $index => $url) {
            $content = $product_notes_data[$index];
            $notes = '';
            $qa = '';

            // 處理內容分割
            $first_notes_pos = mb_strpos($content, '產品注意事項', 0, 'UTF-8');
            $first_qa_pos = mb_strpos($content, '產品QA', 0, 'UTF-8');

            if ($first_notes_pos !== false) {
                $notes_end_pos = ($first_qa_pos !== false) ? $first_qa_pos : null;
                $notes = ($notes_end_pos !== null) 
                    ? mb_substr($content, $first_notes_pos, $notes_end_pos - $first_notes_pos, 'UTF-8')
                    : mb_substr($content, $first_notes_pos, null, 'UTF-8');
            }

            if ($first_qa_pos !== false) {
                $qa = mb_substr($content, $first_qa_pos, null, 'UTF-8');
            }

            // 去重邏輯處理
            if (!empty($notes) && !empty($qa)) {
                // 將內容拆分為數組
                $notes_array = explode('++', $notes);
                $qa_array = explode('++', $qa);
                
                // 使用關聯數組去重
                $unique_notes = [];
                foreach ($notes_array as $note) {
                    $trimmed = trim($note);
                    if (!empty($trimmed)) {
                        $unique_notes[$trimmed] = $trimmed;
                    }
                }
                
                $unique_qa = [];
                foreach ($qa_array as $q) {
                    $trimmed = trim($q);
                    if (!empty($trimmed)) {
                        $unique_qa[$trimmed] = $trimmed;
                    }
                }
                
                // 重新合併為字符串
                $notes = implode("\n", array_values($unique_notes));
                $qa = implode("\n", array_values($unique_qa));
            }

            fputcsv($file_handle, [
                $url,
                $notes,
                $qa
            ]);
        }

        fclose($file_handle);

        // 直接輸出文件
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="product_notes.csv"');
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
add_action('admin_post_download_product_notes', 'crawler_download_product_notes');

// 管理頁面
function crawler_admin_page() {
    ?>
    <div class="wrap">
        <h1>SmartSync Crawler</h1>
        <?php settings_errors('crawler_error'); ?>
        
        <p><strong>注意：</strong>此操作大約會等待 10-20 分鐘，請耐心等待，期間請勿進行任何操作，建議點擊兩次按鈕確保有觸發爬蟲。如果瀏覽器停止運轉，就再按一下按鈕，<strong>包含除了產品注意事項以及產品QA以外的所有內容</strong>
        
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="download_product_urls">
            <?php wp_nonce_field('run_crawler_nonce', 'crawler_nonce'); ?>
            <p class="submit">
                <input type="submit" class="button button-primary" value="下載產品 URL CSV">
            </p>
        </form>

        <p><strong>注意：</strong>此 CSV 文件開啟時可能會非常慢，建議使用CPU/記憶體優的電腦開啟。<strong>包含產品注意事項以及產品QA內容</strong></p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="download_product_notes">
            <?php wp_nonce_field('run_crawler_nonce', 'crawler_nonce'); ?>
            <p class="submit">
                <input type="submit" class="button button-primary" value="下載產品注意事項 CSV">
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