1. 將此文件保存為 smartsync-crawler.php 並放置在您的 WordPress 插件目錄中：
2. 登錄到您的 WordPress 管理員儀表板並導航至插件部分
3. 激活「SmartSync Crawler」插件
4. 激活後，您將在 WordPress 管理側邊欄中看到一個名為「SmartSync」的新菜單項
5. 點擊「SmartSync」訪問爬蟲界面，然後點擊「開始爬蟲」按鈕運行爬蟲

```shell
Compress-Archive -Path ".\smartsync-crawler.php" -DestinationPath ".\smartsync-crawler.zip" -Force
```

```bash
zip smartsync-crawler.zip smartsync-crawler.php
```