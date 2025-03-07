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

    // 用換行符分隔所有段落和 span 的文字內容
    return implode("\n", $content);
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

    // 轉換為陣列並合併，使用換行符號
    return implode("\n", array_values($unique_content));
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

// 處理 CSV 下載 (修改版 - 移除筆數限制，1-based索引)
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
        // 移除 20 筆的限制
        
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
        
        // 獲取所有產品數據
        $product_names = [];
        $product_short_descriptions = [];
        $product_actual_prices = [];
        $product_descriptions = [];
        $product_images = [];
        $product_videos = [];
        $product_articles = [];
        $product_notes_data = [];
        $product_qa_data = [];
        $product_original_prices = [];
        
        foreach ($product_urls as $url) {
            $product_names[] = crawler_get_product_name($url);
            $product_short_descriptions[] = crawler_get_product_short_description($url);
            $product_actual_prices[] = crawler_get_product_actual_price($url);
            $product_descriptions[] = crawler_get_product_description($url);
            $product_images[] = crawler_get_product_images($url);
            $product_videos[] = crawler_get_product_video($url);
            $product_articles[] = crawler_get_product_articles($url);
            
            // 獲取第二個CSV中的數據
            $raw_notes = crawler_get_product_notes($url);
            
            // 處理注意事項和QA數據
            $notes = '';
            $qa = '';
            
            // 處理內容分割
            $first_notes_pos = mb_strpos($raw_notes, '產品注意事項', 0, 'UTF-8');
            $first_qa_pos = mb_strpos($raw_notes, '產品QA', 0, 'UTF-8');

            if ($first_notes_pos !== false) {
                $notes_end_pos = ($first_qa_pos !== false) ? $first_qa_pos : null;
                $notes = ($notes_end_pos !== null) 
                    ? mb_substr($raw_notes, $first_notes_pos, $notes_end_pos - $first_notes_pos, 'UTF-8')
                    : mb_substr($raw_notes, $first_notes_pos, null, 'UTF-8');
            }

            if ($first_qa_pos !== false) {
                $qa = mb_substr($raw_notes, $first_qa_pos, null, 'UTF-8');
            }

            // 檢查是否包含"因改良而有變更時"，如果有則截斷
            $cutoff_pos = mb_strpos($notes, '因改良而有變更時', 0, 'UTF-8');
            if ($cutoff_pos !== false) {
                $notes = mb_substr($notes, 0, $cutoff_pos, 'UTF-8');
            }

            $cutoff_pos = mb_strpos($qa, '因改良而有變更時', 0, 'UTF-8');
            if ($cutoff_pos !== false) {
                $qa = mb_substr($qa, 0, $cutoff_pos, 'UTF-8');
            }
            
            // 去重處理
            if (!empty($notes)) {
                $notes_array = explode("\n", $notes);
                $unique_notes = [];
                foreach ($notes_array as $note) {
                    $trimmed = trim($note);
                    if (!empty($trimmed)) {
                        $unique_notes[$trimmed] = $trimmed;
                    }
                }
                $notes = implode("\n", array_values($unique_notes));
            }
            
            if (!empty($qa)) {
                $qa_array = explode("\n", $qa);
                $unique_qa = [];
                foreach ($qa_array as $q) {
                    $trimmed = trim($q);
                    if (!empty($trimmed)) {
                        $unique_qa[$trimmed] = $trimmed;
                    }
                }
                $qa = implode("\n", array_values($unique_qa));
            }
            
            $product_notes_data[] = $notes;
            $product_qa_data[] = $qa;
            $product_original_prices[] = crawler_get_original_price($url);
        }

        // 寫入CSV文件（確保UTF-8編碼）
        $csv_file = "$output_dir/product_data.csv";
        $file_handle = fopen($csv_file, 'w');
        if (!$file_handle) {
            throw new Exception('無法建立CSV文件');
        }
        
        // 寫入UTF-8 BOM，確保Excel等軟件正確識別編碼
        fwrite($file_handle, "\xEF\xBB\xBF");
        
        // 寫入表頭（合併兩個CSV的欄位）
        fputcsv($file_handle, [
            'URL', 'Name', 'Short Description', 'ActualPrice', 'Description', 'Images', 'Video', 'Articles',
            '產品注意事項', '產品QA', '原價'
        ]);
        
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
                $product_articles[$index],
                $product_notes_data[$index],
                $product_qa_data[$index],
                $product_original_prices[$index]
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