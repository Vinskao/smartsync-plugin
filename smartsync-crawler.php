<?php
/*
Plugin Name: SmartSync Crawler
Description: 爬取 Jarvis 網站商品資訊
Version: 1.0
Author: VinsKao
*/

// 主要爬蟲函數：採用緩衝批次寫入以減少 I/O 操作
function crawler_crawl_data() {
    if (!isset($_POST['crawler_action']) || $_POST['crawler_action'] !== 'start_crawl') return;
    
    set_time_limit(600); // 增加到10分鐘
    ini_set('memory_limit', '512M'); // 增加記憶體限制
    
    try {
        // 創建輸出目錄
        $output_dir = __DIR__ . '/output';
        if (!file_exists($output_dir)) mkdir($output_dir, 0755, true);
        
        // 爬取分類連結 (同時設定 items_per_page 為 96)
        $main_url = 'https://www.jarvis.com.tw/aqara智能居家/';
        $category_urls = crawler_get_category_urls($main_url);
        // 使用緩衝批次寫入分類連結到檔案
        $categories_file = "$output_dir/category_urls.txt";
        $fp_cat = fopen($categories_file, 'w');
        $batch_size = 10; // 每 10 筆為一批寫入
        $buffer = [];
        foreach ($category_urls as $url) {
            $buffer[] = $url;
            if (count($buffer) >= $batch_size) {
                fwrite($fp_cat, implode("\n", $buffer) . "\n");
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            fwrite($fp_cat, implode("\n", $buffer) . "\n");
        }
        fclose($fp_cat);
        
        // 準備 CSV 檔案，並寫入表頭
        $csv_file = "$output_dir/jarvis_products_" . date('Y-m-d_H-i-s') . '.csv';
        $fp = fopen($csv_file, 'w');
        fputs($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($fp, ['名稱', '簡短內容說明', '原價', '特價', '描述', '圖片', '購買備註', '外部網址']);
        
        $total_products = 0;
        $batch_size = 5; // 每次處理的分類數量
        $buffer = [];    // 緩衝區：暫存本批次待寫入的資料列
        $start_time = time();
        $all_images = [];
        $processed_urls = []; // 追蹤已處理的URL，避免重複
        
        // 分批處理分類
        for ($i = 0; $i < count($category_urls); $i += $batch_size) {
            if (time() - $start_time > 540) { // 9分鐘超時，留出時間寫入資料
                throw new Exception("執行時間過長，已處理 $i 個分類，總共 " . count($category_urls) . " 個。");
            }
            
            $batch_categories = array_slice($category_urls, $i, $batch_size);
            $batch_results = [];
            
            // 使用多進程或多執行緒處理（如果環境支援）
            if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
                // 使用 PCNTL 進行並行處理（僅在 Linux/Unix 環境）
                $pids = [];
                $temp_files = [];
                
                foreach ($batch_categories as $index => $category_url) {
                    $pid = pcntl_fork();
                    if ($pid == -1) {
                        // 無法創建子進程，回退到串行處理
                        $category_product_data = crawler_process_page($category_url, $all_images, $processed_urls);
                        if (is_array($category_product_data)) {
                            $batch_results = array_merge($batch_results, $category_product_data);
                        }
                    } elseif ($pid == 0) {
                        // 子進程
                        $category_product_data = crawler_process_page($category_url, $all_images, $processed_urls);
                        $temp_file = tempnam(sys_get_temp_dir(), 'crawler_');
                        file_put_contents($temp_file, serialize($category_product_data));
                        exit(0);
                    } else {
                        // 父進程
                        $pids[$pid] = $index;
                        $temp_files[$index] = tempnam(sys_get_temp_dir(), 'crawler_');
                    }
                }
                
                // 等待所有子進程完成
                foreach ($pids as $pid => $index) {
                    pcntl_waitpid($pid, $status);
                    if (file_exists($temp_files[$index])) {
                        $data = unserialize(file_get_contents($temp_files[$index]));
                        if (is_array($data)) {
                            $batch_results = array_merge($batch_results, $data);
                        }
                        unlink($temp_files[$index]);
                    }
                }
            } else {
                // 串行處理（如果不支援多進程）
                foreach ($batch_categories as $category_url) {
                    if (in_array($category_url, $processed_urls)) continue;
                    $processed_urls[] = $category_url;
                    
                    $category_product_data = crawler_process_page($category_url, $all_images, $processed_urls);
                    if (is_array($category_product_data)) {
                        $batch_results = array_merge($batch_results, $category_product_data);
                    }
                }
            }
            
            // 處理批次結果
            foreach ($batch_results as $row) {
                // 準備 CSV 資料
                $notes = is_array($row['note']) ? implode('; ', $row['note']) : $row['note'];
                $description = (is_array($row['qa_text']) ? implode('; ', $row['qa_text']) : $row['qa_text']) . '; ' . 
                (is_array($row['intro_text']) ? implode('; ', $row['intro_text']) : $row['intro_text']);         
                $all_img = isset($row['images']) && is_array($row['images']) ? implode('; ', $row['images']) : '';
                $aqara_img = isset($row['aqara_images']) && is_array($row['aqara_images']) ? implode('; ', $row['aqara_images']) : '';
                $images = !empty($aqara_img) ? $aqara_img : $all_img;
                
                // 將每筆資料存入緩衝區
                $buffer[] = [
                    $row['title'],
                    $notes,
                    $row['price'],
                    $row['price_actual'],
                    $description,
                    $images,
                    $row['spec_image'],
                    $row['videolink_text']
                ];
                $total_products++;
            }
            
            // 批次寫入緩衝資料到 CSV 檔案
            foreach ($buffer as $csv_row) {
                fputcsv($fp, $csv_row);
            }
            fflush($fp);
            $buffer = []; // 清空緩衝區
            
            // 清理記憶體
            gc_collect_cycles();
        }
        
        fclose($fp);
        
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

// 獲取分類連結 (在進入主頁前設定 items_per_page 為 96)
/* 修改 crawler_get_category_urls 函數，加入深度限制和過濾機制 */
function crawler_get_category_urls($url) {
    $url = add_query_arg('items_per_page', '96', $url);
    $html = crawler_fetch_html($url);
    if (!$html) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $category_urls = [];

    // 擴展選擇器：抓取所有可能的分類連結
    $selectors = [
        '//a[contains(@href,"category") and contains(@href,"items_per_page=96")]',
        '//a[contains(@href,"/aqara") and contains(@href,"items_per_page=96")]',
        '//a[contains(@class, "ty-menu__submenu-link") and contains(@href, "/aqara")]',
        '//a[contains(@class, "ty-menu__item-link") and contains(@href, "/aqara")]',
        '//div[contains(@class, "ty-menu__submenu")]//a[contains(@href, "/aqara")]',
        '//div[contains(@class, "categories-menu")]//a[contains(@href, "/aqara")]',
        '//nav//a[contains(@href, "category") or contains(@href, "/aqara")]'
    ];

    foreach ($selectors as $selector) {
        $links = $xpath->query($selector);
        foreach ($links as $link) {
            $href = crawler_normalize_url($link->getAttribute('href'));
            if (!empty($href) && strpos($href, 'http') === 0) {
                $category_urls[] = $href;

                // 深度處理分頁：對於每個分類 URL，檢查其分頁
                $sub_html = crawler_fetch_html($href);
                if ($sub_html) {
                    $sub_dom = new DOMDocument();
                    @$sub_dom->loadHTML($sub_html);
                    $sub_xpath = new DOMXPath($sub_dom);

                    $pag_selectors = [
                        '//a[contains(@class, "ty-pagination__item") and contains(@href, "page=")]',
                        '//div[contains(@class, "pagination")]//a[contains(@href, "page=")]',
                        '//ul[contains(@class, "pagination")]//a[contains(@href, "page=")]',
                        '//a[contains(@href, "page=")]'
                    ];

                    foreach ($pag_selectors as $pag_selector) {
                        $pag_links = $sub_xpath->query($pag_selector);
                        foreach ($pag_links as $pag_link) {
                            $pag_href = crawler_normalize_url($pag_link->getAttribute('href'));
                            if (!empty($pag_href) && strpos($pag_href, 'page=') !== false) {
                                $category_urls[] = add_query_arg('items_per_page', '96', $pag_href);
                            }
                        }
                    }
                }
            }
        }
    }

    // 移除過濾條件，確保抓取所有層次
    $category_urls[] = $url;
    return array_values(array_unique($category_urls));
}

// 處理分類頁面，支援抓取分頁所有產品
/* 強化 crawler_process_page 的產品連結抓取邏輯 */
function crawler_process_page($url, &$all_images, &$processed_urls = []) {
    $all_product_data = [];
    
    // 避免重複處理
    if (in_array($url, $processed_urls)) {
        return $all_product_data;
    }
    $processed_urls[] = $url;
    
    // 解析原始 URL 保留所有參數
    $parsed_url = parse_url($url);
    $base_path = $parsed_url['path'] ?? '';
    $base_query = isset($parsed_url['query']) ? $parsed_url['query'] : '';
    
    // 合併原始查詢參數
    parse_str($base_query, $query_params);
    $query_params['items_per_page'] = 96; // 確保每頁顯示96
    
    $page = 1;
    $max_attempts = 3; // 最大嘗試次數
    $retry_count = 0;

    do {
        // 構建分頁參數
        $query_params['page'] = $page;
        $new_query = http_build_query($query_params);
        
        // 重建完整 URL
        $paged_url = "https://www.jarvis.com.tw{$base_path}?{$new_query}";
        
        error_log("[分頁請求] {$paged_url}");

        $html = crawler_fetch_html($paged_url);
        if (!$html) {
            if ($retry_count < $max_attempts) {
                $retry_count++;
                error_log("[分頁異常] 第 {$retry_count} 次重試...");
                sleep(1); // 短暫等待後重試
                continue;
            }
            break;
        }

        // 使用更高效的 HTML 解析
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // 抑制 HTML 解析錯誤
        @$dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        // 強化選擇器：精確匹配 product-title 且排除重複連結
        $product_links = $xpath->query('
            //a[
                contains(concat(" ", normalize-space(@class), " "), " product-title ")
                and not(contains(@href, "blog"))
                and not(contains(@href, "news"))
                and not(contains(@href, "category"))
            ]
        ');

        $current_count = $product_links->length;
        error_log("[商品統計] 本頁找到 {$current_count} 個商品連結");

        // 使用批次處理產品
        $product_links_array = [];
        foreach ($product_links as $link) {
            $href = crawler_normalize_url($link->getAttribute('href'));
            if (crawler_validate_product_url($href) && !in_array($href, $processed_urls)) {
                $product_links_array[] = $href;
                $processed_urls[] = $href;
            }
        }
        
        // 批次處理產品頁面
        $chunk_size = 5; // 每批處理的產品數
        for ($i = 0; $i < count($product_links_array); $i += $chunk_size) {
            $chunk = array_slice($product_links_array, $i, $chunk_size);
            $chunk_results = [];
            
            // 並行處理（如果支援）
            if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
                // 使用 PCNTL 進行並行處理
                $pids = [];
                $temp_files = [];
                
                foreach ($chunk as $index => $href) {
                    $pid = pcntl_fork();
                    if ($pid == -1) {
                        // 無法創建子進程，回退到串行處理
                        $product_data = crawler_process_product_page($href, $all_images);
                        if ($product_data) {
                            $chunk_results[] = $product_data;
                        }
                    } elseif ($pid == 0) {
                        // 子進程
                        $product_data = crawler_process_product_page($href, $all_images);
                        $temp_file = tempnam(sys_get_temp_dir(), 'product_');
                        file_put_contents($temp_file, serialize($product_data));
                        exit(0);
                    } else {
                        // 父進程
                        $pids[$pid] = $index;
                        $temp_files[$index] = tempnam(sys_get_temp_dir(), 'product_');
                    }
                }
                
                // 等待所有子進程完成
                foreach ($pids as $pid => $index) {
                    pcntl_waitpid($pid, $status);
                    if (file_exists($temp_files[$index])) {
                        $data = unserialize(file_get_contents($temp_files[$index]));
                        if ($data) {
                            $chunk_results[] = $data;
                        }
                        unlink($temp_files[$index]);
                    }
                }
            } else {
                // 串行處理
                foreach ($chunk as $href) {
                    $product_data = crawler_process_product_page($href, $all_images);
                    if ($product_data) {
                        $chunk_results[] = $product_data;
                    }
                }
            }
            
            // 合併結果
            foreach ($chunk_results as $product_data) {
                $all_product_data[] = $product_data;
            }
        }

        // 修正分頁條件判斷
        $has_next = false;
        if ($current_count > 0) {
            // 1. 檢查分頁按鈕（支援多種 class 名稱）
            $next_page_btn = $xpath->query('
                //a[contains(@class, "next") or contains(@class, "ty-pagination__next")]
            ');
            
            // 2. 檢查商品數量是否達標
            $items_count = $xpath->query('//div[contains(@class, "ty-pagination")]//text()[contains(., "商品")]');
            $items_text = $items_count->item(0)->nodeValue ?? '';
            $has_items_range = preg_match('/\d+-\d+/u', $items_text); // 改用短破折號
            
            // 3. 檢查分頁容器是否存在
            $pagination_container = $xpath->query('//div[@id="pagination_contents"]');
            
            // 4. 檢查隱藏分頁標記
            $hidden_next = $xpath->query('//link[@rel="next"]');
            
            // 綜合判斷條件
            $has_next = ($next_page_btn->length > 0)
                || $has_items_range
                || ($pagination_container->length > 0 && $current_count >= 76)
                || ($hidden_next->length > 0);
        }

        $page++;
        $retry_count = 0; // 重試計數重置
    } while ($has_next && $page <= 20); // 安全上限

    error_log("[分頁完成] 總共處理 " . ($page - 1) . " 個分頁");
    return $all_product_data;
}

// 處理產品頁面
function crawler_process_product_page($url, &$all_images) {
    $html = crawler_fetch_html($url);
    if (!$html) return false;

    // 使用更高效的 HTML 解析
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // 抑制 HTML 解析錯誤
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    
    // 定義選擇器 - 使用關聯數組提高可讀性
    $selectors = [
        'note' => ['//.ty-product-feature-list__item', '//div[contains(@class, "product-features")]//li'],
        'price' => ['//.ty-price-num', '//span[contains(@class, "price")]'],
        'price_actual' => ['//.ty-price-num[@class="actual"]', '//span[contains(@class, "sale-price")]'],
        'qa_text' => ['//.ty-qa__item-text', '//div[contains(@class, "qa")]//p'],
        'intro_text' => ['//.ty-product-feature-list__description', '//div[contains(@class, "product-description")]//p'],
        'product_image' => ['//.ty-product-feature-list__image img', '//div[contains(@class, "product-image")]//img'],
        'spec_image' => ['//.ty-product-spec-list__image img', '//div[contains(@class, "product-spec")]//img'],
        'videolink_text' => ['//.ty-product-video iframe', '//iframe[contains(@src, "youtube")]']
    ];
    
    // 尋找最佳選擇器
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

    // 收集圖片 - 使用更高效的選擇器
    $images = $xpath->query('//img[contains(@src, "aqara") or contains(@alt, "aqara") or contains(@src, "product")]');
    $product_images = [];
    $aqara_images = [];
    
    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        $alt = strtolower($img->getAttribute('alt'));
        
        if (!empty($src)) {
            $src = crawler_normalize_url($src);
            
            // 更精確地識別 Aqara 圖片
            if (stripos($alt, 'aqara') !== false || 
                stripos($src, 'aqara') !== false) {
                $aqara_images[] = $src;
            }
            
            $product_images[] = $src;
            
            // 避免重複添加圖片
            if (!in_array($src, $all_images)) {
                $all_images[] = $src;
            }
        }
    }

    // 提取產品資料 - 使用更簡潔的方式
    $product_data = [
        'title' => '',
        'note' => [],
        'price' => '',
        'price_actual' => '',
        'qa_text' => [],
        'intro_text' => [],
        'product_image' => '',
        'spec_image' => '',
        'videolink_text' => '',
        'images' => $product_images,
        'aqara_images' => $aqara_images
    ];
    
    // 標題處理 - 改進標題獲取邏輯
    $title_element = $xpath->query('//h1[contains(@class, "ty-product-block-title")]//bdi')->item(0);
    
    if ($title_element) {
        // 抓取 <bdi> 內的文字
        $product_data['title'] = trim($title_element->nodeValue);
    } else {
        // 備用方案：檢查其他可能的標題位置
        $fallback_titles = [
            '//h1[contains(@class, "product-title")]',
            '//title',
            '//meta[@property="og:title"]'
        ];
        
        foreach ($fallback_titles as $selector) {
            $element = $xpath->query($selector)->item(0);
            if ($element) {
                $product_data['title'] = trim($element->nodeValue);
                break;
            }
        }
        
        // 終極備用方案：從 URL 解析
        if (empty($product_data['title'])) {
            $path = parse_url($url, PHP_URL_PATH);
            $product_data['title'] = urldecode(
                str_replace(
                    ['-', '_', '+'],
                    ' ',
                    pathinfo($path, PATHINFO_FILENAME)
                )
            );
        }
    }

    // 清理標題
    $product_data['title'] = preg_replace([
        '/\|.*$/',              // 移除 | 之後的內容
        '/\s*-\s*Jarvis.*$/',   // 移除 - Jarvis 後綴 
        '/【.*】/',             // 移除【】內內容
        '/<.*?>/'               // 移除 HTML 標籤
    ], '', $product_data['title']);

    // 日誌記錄
    error_log("[Title Debug] URL: $url | Title: " . $product_data['title']);
    
    // 填充其他欄位
    foreach (['note', 'qa_text', 'intro_text'] as $text_field) {
        if (isset($best_selectors[$text_field])) {
            $product_data[$text_field] = crawler_get_texts($xpath, $best_selectors[$text_field]);
        }
    }
    
    foreach (['price', 'price_actual'] as $price_field) {
        if (isset($best_selectors[$price_field])) {
            $product_data[$price_field] = crawler_get_text($xpath, $best_selectors[$price_field]);
        }
    }
    
    // 如果沒有特價，使用原價
    if (empty($product_data['price_actual'])) {
        $product_data['price_actual'] = $product_data['price'];
    }
    
    foreach (['product_image', 'spec_image', 'videolink_text'] as $attr_field) {
        if (isset($best_selectors[$attr_field])) {
            $product_data[$attr_field] = crawler_get_attribute($xpath, $best_selectors[$attr_field], 'src');
        }
    }

    return $product_data;
}

// 獲取 HTML 內容 - 改進錯誤處理和效能
function crawler_fetch_html($url) {
    static $cache = []; // 靜態快取
    
    // 檢查快取
    if (isset($cache[$url])) {
        return $cache[$url];
    }
    
    // 添加重試機制
    $max_retries = 3;
    $retry_count = 0;
    
    while ($retry_count < $max_retries) {
        if (!function_exists('curl_init')) {
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'timeout' => 15
                ]
            ];
            $context = stream_context_create($opts);
            $html = @file_get_contents($url, false, $context);
            
            if ($html !== false) {
                // 儲存到快取
                $cache[$url] = $html;
                
                // 限制快取大小
                if (count($cache) > 100) {
                    array_shift($cache); // 移除最舊的項目
                }
                
                return $html;
            }
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING => '', // 自動處理壓縮內容
                CURLOPT_TCP_FASTOPEN => 1, // 啟用 TCP Fast Open (如果支援)
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0, // 嘗試使用 HTTP/2
            ]);
            $html = curl_exec($ch);
            
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_errno($ch);
            curl_close($ch);
            
            if (!$curl_error && $status_code < 400) {
                // 儲存到快取
                $cache[$url] = $html;
                
                // 限制快取大小
                if (count($cache) > 100) {
                    array_shift($cache); // 移除最舊的項目
                }
                
                return $html;
            }
        }
        
        // 重試前等待
        $retry_count++;
        if ($retry_count < $max_retries) {
            usleep(500000); // 等待0.5秒後重試
        }
    }
    
    return false;
}

// 輔助函數 - 優化文本處理
function crawler_get_text($xpath, $query) {
    $element = $xpath->query($query)->item(0);
    return $element ? preg_replace('/\s+/', ' ', trim($element->nodeValue)) : '';
}

function crawler_get_texts($xpath, $query) {
    $elements = $xpath->query($query);
    $texts = [];
    foreach ($elements as $element) {
        $text = preg_replace('/\s+/', ' ', trim($element->nodeValue));
        if (!empty($text)) {
            $texts[] = $text;
        }
    }
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
                    <li>爬蟲會處理所有找到的產品，每頁最多顯示 96 個產品。</li>
                    <li>分類連結將保存在 output/category_urls.txt 文件中。</li>
                    <li>如果處理時間超過 4 分鐘，爬蟲會自動中斷並下載已爬取的部分數據。</li>
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

// WP-CLI 命令（同樣採用緩衝批次寫入方式）
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('smartsync crawl', function() {
        try {
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
            
            $output = null;
            try {
                $output = fopen($file_path, 'w');
                if ($output === false) {
                    throw new Exception("無法建立 CSV 檔案：$file_path");
                }
                
                fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM
                fputcsv($output, ['名稱', '簡短內容說明', '原價', '特價', '描述', '圖片', '購買備註', '外部網址']);
                
                // 使用緩衝區批次寫入 CSV
                $batch_size = 50;
                $buffer = [];
                foreach ($all_product_data as $row) {
                    $notes = is_array($row['note']) ? implode('; ', $row['note']) : $row['note'];
                    $description = (is_array($row['qa_text']) ? implode('; ', $row['qa_text']) : $row['qa_text']) . '; ' . 
                                 (is_array($row['intro_text']) ? implode('; ', $row['intro_text']) : $row['intro_text']);
                    $all_img = isset($row['images']) && is_array($row['images']) ? implode('; ', $row['images']) : '';
                    $aqara_img = isset($row['aqara_images']) && is_array($row['aqara_images']) ? implode('; ', $row['aqara_images']) : '';
                    $images = !empty($aqara_img) ? $aqara_img : $all_img;
                    
                    $buffer[] = [
                        $row['title'],
                        $notes,
                        $row['price'],
                        $row['price_actual'],
                        $description,
                        $images,
                        $row['spec_image'],
                        $row['videolink_text']
                    ];
                    
                    if (count($buffer) >= $batch_size) {
                        foreach ($buffer as $csv_row) {
                            if (fputcsv($output, $csv_row) === false) {
                                throw new Exception('寫入 CSV 時發生錯誤');
                            }
                        }
                        if (fflush($output) === false) {
                            throw new Exception('清空緩衝區時發生錯誤');
                        }
                        $buffer = [];
                    }
                }
                
                // 寫入剩餘的資料
                if (!empty($buffer)) {
                    foreach ($buffer as $csv_row) {
                        if (fputcsv($output, $csv_row) === false) {
                            throw new Exception('寫入最後的 CSV 資料時發生錯誤');
                        }
                    }
                }
                
                WP_CLI::success('爬蟲已完成運行！共爬取 ' . count($all_product_data) . ' 筆資料，收集 ' . count($all_images) . ' 張圖片。CSV 文件已保存到：' . $file_path);
            } finally {
                if ($output !== null) {
                    fclose($output);
                }
            }
        } catch (Exception $e) {
            WP_CLI::error('執行過程中發生錯誤：' . $e->getMessage());
        }
    });
}

// 新增共用函數
function crawler_normalize_url($url) {
    static $cache = [];
    
    if (isset($cache[$url])) {
        return $cache[$url];
    }
    
    $result = $url;
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        $result = $url;
    } else if (strpos($url, 'http') !== 0 && !empty($url) && $url != '#') {
        $result = (($url[0] == '/') ? 'https://www.jarvis.com.tw' : 'https://www.jarvis.com.tw/') . $url;
    }
    
    // 儲存到快取
    $cache[$url] = $result;
    
    // 限制快取大小
    if (count($cache) > 1000) {
        array_shift($cache); // 移除最舊的項目
    }
    
    return $result;
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
        
        // 使用緩衝批次寫入分類連結到檔案
        $categories_file = "$output_dir/category_urls.txt";
        $fp_cat = fopen($categories_file, 'w');
        $batch_size = 10;
        $buffer = [];
        foreach ($category_urls as $url) {
            $buffer[] = $url;
            if (count($buffer) >= $batch_size) {
                fwrite($fp_cat, implode("\n", $buffer) . "\n");
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            fwrite($fp_cat, implode("\n", $buffer) . "\n");
        }
        fclose($fp_cat);
        
        update_option('crawler_last_run', current_time('mysql'));
        
        crawler_download_file($categories_file, 'text/plain');
    } catch (Exception $e) {
        wp_die('獲取分類連結時發生錯誤: ' . $e->getMessage(), '爬蟲錯誤', ['response' => 500]);
    }
}
add_action('admin_post_get_categories_only', 'crawler_get_categories_only');

/* 新增 URL 過濾函數 */
function crawler_validate_product_url($url) {
    $path = parse_url($url, PHP_URL_PATH);
    
    // 排除非產品路徑
    $exclude_patterns = [
        '/category/',
        '/brand/',
        '/blog/',
        '/news/',
        '/page/',
        '/tag/'
    ];
    
    foreach ($exclude_patterns as $pattern) {
        if (preg_match($pattern, $path)) {
            return false;
        }
    }

    // 必須包含產品特徵
    $include_patterns = [
        '/product/',
        '/-\d+\.html$/',
        '/_p\d+$/',
        '/item/'
    ];
    
    foreach ($include_patterns as $pattern) {
        if (preg_match($pattern, $path)) {
            return true;
        }
    }
    
    return false;
}
?>
