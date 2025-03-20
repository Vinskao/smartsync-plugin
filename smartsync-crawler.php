<?php
/*
Plugin Name: SmartSync Crawler
Description: 爬取 Jarvis 網站商品資訊
Version: 2.2
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
    
    // 查找所有span元素
    $all_spans = $xpath_style->query('//span');
    
    // 查找恰好只包含"產品注意事項"和"產品QA"的span
    $notice_span = null;
    $qa_span = null;
    $notice_position = -1;
    $qa_position = -1;
    $all_spans_array = [];
    
    // 將所有span元素存入數組，方便後續處理
    for ($i = 0; $i < $all_spans->length; $i++) {
        $span = $all_spans->item($i);
        $text = trim($span->textContent);
        $all_spans_array[$i] = $span;
        
        // 檢查是否為恰好包含"產品注意事項"的span
        if ($text === '產品注意事項') {
            $notice_span = $span;
            $notice_position = $i;
        }
        
        // 檢查是否為恰好包含"產品QA"的span
        if ($text === '產品QA') {
            $qa_span = $span;
            $qa_position = $i;
        }
    }
    
    // 創建"產品注意事項"到"產品QA"之間的span內容
    $notice_to_qa_html = '';
    if ($notice_span !== null && $qa_span !== null && $notice_position < $qa_position) {
        // 首先添加"產品注意事項"標題
        $notice_to_qa_html = '<p>產品注意事項</p>' . "\n";
        
        // 收集"產品注意事項"到"產品QA"之間的span內容
        $spans_between = [];
        for ($i = $notice_position + 1; $i < $qa_position; $i++) {
            $span = $all_spans_array[$i];
            $text = trim($span->textContent);
            if (!empty($text)) {
                $spans_between[] = $text;
            }
        }
        
        // 將span內容用雙引號包裹並用<br>分隔
        if (!empty($spans_between)) {
            $notice_to_qa_html .= '<p>';
            foreach ($spans_between as $index => $span_text) {
                if ($index > 0) {
                    $notice_to_qa_html .= '<br>';
                }
                $notice_to_qa_html .= '"' . $span_text . '"';
            }
            $notice_to_qa_html .= '</p>';
        }
    }
    
    // 嘗試多種方法查找產品QA相關內容
    $style_html = '';
    
    // 方法1: 嘗試查找任何包含"產品QA"文本的元素，然後找到其父div
    $qa_elements = $xpath_style->query("//span[text()='產品QA']");
    
    if ($qa_elements->length > 0) {
        error_log('找到精確匹配"產品QA"文本的元素: ' . $qa_elements->length . '個');
        
        // 獲取包含產品QA的元素
        $qa_element = $qa_elements->item(0);
        
        // 先添加QA標題
        $style_html = '<p>產品QA</p>' . "\n";
        
        // 尋找最近的div父元素
        $parent = $qa_element;
        while ($parent && $parent->nodeName != 'div') {
            $parent = $parent->parentNode;
        }
        
        if ($parent && $parent->nodeName == 'div') {
            // 找到了包含產品QA的div
            error_log('找到包含產品QA的父div');
            
            // 獲取QA後的所有span
            $spans = $xpath_style->query('//span', $parent->parentNode);
            
            // 找到產品QA的位置
            $qa_position = -1;
            for ($i = 0; $i < $spans->length; $i++) {
                if (trim($spans->item($i)->textContent) === '產品QA') {
                    $qa_position = $i;
                    break;
                }
            }
            
            if ($qa_position >= 0) {
                // 按順序收集QA後的所有span
                $all_qa_spans = array();
                $titles = array();
                $contents = array();
                
                for ($i = $qa_position + 1; $i < $spans->length; $i++) {
                    $span = $spans->item($i);
                    $text = trim($span->textContent);
                    
                    if (empty($text)) continue;
                    
                    // 檢查是否為標題（帶有背景色的span）
                    $style_attr = $span->getAttribute('style');
                    $is_title = (strpos($style_attr, 'background-color') !== false);
                    
                    if ($is_title) {
                        $titles[] = array('index' => count($all_qa_spans), 'text' => $text);
                    } else {
                        $contents[] = array('index' => count($all_qa_spans), 'text' => $text);
                    }
                    
                    $all_qa_spans[] = array(
                        'text' => $text,
                        'is_title' => $is_title
                    );
                }
                
                // 準備手風琴結構
                $style_html .= '<div class="customerized-accordion-container customerized-accordion">' . "\n";
                
                // 逐對創建手風琴項目
                for ($i = 0; $i < count($all_qa_spans) - 1; $i++) {
                    if ($all_qa_spans[$i]['is_title'] && !$all_qa_spans[$i+1]['is_title']) {
                        $title = $all_qa_spans[$i]['text'];
                        $content = $all_qa_spans[$i+1]['text'];
                        
                        // 創建手風琴項目
                        $style_html .= '<div class="customerized-accordion-item">' . "\n";
                        
                        // 標題部分
                        $style_html .= '<div class="customerized-accordion-title" role="button" tabindex="0">' . "\n";
                        $style_html .= '<span class="customerized-accordion-icon">' . "\n";
                        $style_html .= '<span class="customerized-icon-closed"><i class="fas fa-plus"></i></span>' . "\n";
                        $style_html .= '<span class="customerized-icon-opened"><i class="fas fa-minus"></i></span>' . "\n";
                        $style_html .= '</span>' . "\n";
                        $style_html .= '<span class="customerized-title-text">' . $title . '</span>' . "\n";
                        $style_html .= '</div>' . "\n";
                        
                        // 內容部分
                        $style_html .= '<div class="customerized-accordion-content">' . "\n";
                        $style_html .= '<p>' . $content . '</p>' . "\n";
                        $style_html .= '</div>' . "\n";
                        
                        $style_html .= '</div>' . "\n"; // 結束 accordion-item
                        
                        // 跳過下一個元素，因為已經用掉了
                        $i++;
                    }
                }
                
                $style_html .= '</div>'; // 結束 customerized-accordion-container
            } else {
                error_log('未找到產品QA在span列表中的位置');
                $style_html .= '<p>未能正確解析產品QA內容</p>';
            }
        } else {
            // 如果找不到正確的div，使用正則表達式方法
            error_log('未找到包含產品QA元素的父div，使用備用方法');
            
            // 從HTML中提取產品QA後的內容
            preg_match('/<[^>]*>產品QA<\/[^>]*>(.*)/s', $html, $qa_content_match);
            
            if (!empty($qa_content_match[1])) {
                $qa_html = $qa_content_match[1];
                
                // 使用正則表達式提取帶有背景色的span和其後的span
                preg_match_all('/<span[^>]*style="[^"]*background-color[^"]*"[^>]*>(.*?)<\/span>.*?<span[^>]*>(?:<[^>]*>)*(.*?)(?:<\/[^>]*>)*<\/span>/s', $qa_html, $qa_pairs_match);
                
                // 添加QA標題
                $style_html = '<p>產品QA</p>' . "\n";
                
                // 準備手風琴結構
                $style_html .= '<div class="customerized-accordion-container customerized-accordion">' . "\n";
                
                // 構建手風琴HTML
                for ($i = 0; $i < count($qa_pairs_match[1]); $i++) {
                    $title = trim(strip_tags($qa_pairs_match[1][$i]));
                    $content = trim(strip_tags($qa_pairs_match[2][$i]));
                    
                    if (empty($title) || empty($content)) continue;
                    
                    $style_html .= '<div class="customerized-accordion-item">' . "\n";
                    
                    // 標題部分
                    $style_html .= '<div class="customerized-accordion-title" role="button" tabindex="0">' . "\n";
                    $style_html .= '<span class="customerized-accordion-icon">' . "\n";
                    $style_html .= '<span class="customerized-icon-closed"><i class="fas fa-plus"></i></span>' . "\n";
                    $style_html .= '<span class="customerized-icon-opened"><i class="fas fa-minus"></i></span>' . "\n";
                    $style_html .= '</span>' . "\n";
                    $style_html .= '<span class="customerized-title-text">' . $title . '</span>' . "\n";
                    $style_html .= '</div>' . "\n";
                    
                    // 內容部分
                    $style_html .= '<div class="customerized-accordion-content">' . "\n";
                    $style_html .= '<p>' . $content . '</p>' . "\n";
                    $style_html .= '</div>' . "\n";
                    
                    $style_html .= '</div>' . "\n"; // 結束 accordion-item
                }
                
                $style_html .= '</div>'; // 結束 customerized-accordion-container
            } else {
                // 如果完全找不到產品QA內容，加入基本的QA標題
                $style_html = '<p>產品QA</p>' . "\n";
            }
        }
    } else {
        error_log('未找到精確匹配"產品QA"文本的元素，嘗試使用正則表達式');
        
        // 方法2: 使用正則表達式直接從HTML中提取包含產品QA的部分
        if (preg_match('/<[^>]*>產品QA<\/[^>]*>/', $html, $matches)) {
            error_log('使用正則表達式找到產品QA標記');
            
            // 提取產品QA部分的HTML片段
            $qa_pos = strpos($html, $matches[0]);
            if ($qa_pos !== false) {
                // 截取產品QA後的一段HTML
                $qa_html = substr($html, $qa_pos, 5000); // 截取5000字符應該足夠
                
                // 使用正則表達式提取所有span標籤的內容
                preg_match_all('/<span[^>]*>(.*?)<\/span>/s', $qa_html, $span_matches);
                
                error_log('找到 ' . count($span_matches[1]) . ' 個span內容');
                
                foreach ($span_matches[1] as $span_content) {
                    $text_content = trim(strip_tags($span_content));
                    if (!empty($text_content)) {
                        $style_html .= '<p>' . $text_content . '</p>' . "\n";
                    }
                }
            }
        } else {
            error_log('使用正則表達式也未找到產品QA部分');
        }
    }
    
    // 合併內容：圖片 + 產品注意事項到產品QA之間的內容 + QA內容
    $css_js_links = '<link rel="stylesheet" href="style.css">
<!-- Add Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<!-- Add jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>';

    $js_code = '<script>
jQuery(document).ready(function($) {
    $(\'.customerized-accordion-container .customerized-accordion-title\').on(\'click\', function() {
        var $accordionItem = $(this).closest(\'.customerized-accordion-item\');
        var $content = $accordionItem.find(\'.customerized-accordion-content\');
        
        if ($(this).hasClass(\'active\')) {
            $(this).removeClass(\'active\');
            $content.slideUp(200, \'swing\');
        } else {
            // Close all other accordion items
            $(this).closest(\'.customerized-accordion-container\').find(\'.customerized-accordion-title\').removeClass(\'active\');
            $(this).closest(\'.customerized-accordion-container\').find(\'.customerized-accordion-content\').slideUp(200, \'swing\');
            
            // Open clicked accordion item
            $(this).addClass(\'active\');
            $content.slideDown(200, \'swing\');
        }
    });
});
</script>';

    // 檢查style_html是否包含手風琴結構，如果有則添加container包裹
    if (strpos($style_html, 'customerized-accordion-container') !== false) {
        // 在手風琴外層添加container div
        $style_html = preg_replace(
            '/(<div class="customerized-accordion-container customerized-accordion">.*?<\/div>)$/s',
            '<div class="container">$1</div>',
            $style_html
        );
    }

    $final_html = $css_js_links . "\n" . $js_code . "\n" . $png_html . '<br>' . $notice_to_qa_html . '<br>';

    // 嘗試從QA部分中移除已經在notice_to_qa_html包含的內容
    if (!empty($notice_to_qa_html) && !empty($style_html)) {
        // 提取所有在產品注意事項到產品QA之間的文本內容
        preg_match_all('/"([^"]+)"/', $notice_to_qa_html, $notice_matches);
        
        // 如果有匹配到注意事項內容
        if (!empty($notice_matches[1])) {
            // 對於每一個注意事項文本，從style_html中移除對應的<p>標籤
            foreach ($notice_matches[1] as $notice_text) {
                // 注意轉義正則表達式特殊字符
                $escaped_text = preg_quote($notice_text, '/');
                $style_html = preg_replace('/<p>' . $escaped_text . '<\/p>\s*/', '', $style_html);
            }
        }
    }

    // 添加QA內容
    $final_html .= $style_html;

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