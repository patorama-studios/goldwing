<?php
require_once __DIR__ . '/app/Services/Env.php';
\App\Services\Env::load(__DIR__ . '/.env');
\App\Services\Env::load(__DIR__ . '/.env.local');

$config = require __DIR__ . '/config/database.php';
$port = $config['port'] ?? 3306;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $port, $config['database'], $config['charset']);

try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Create the table on the target database if it doesn't exist
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS honda_dealers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        state VARCHAR(50) NOT NULL,
        address TEXT,
        phone VARCHAR(100),
        website VARCHAR(255),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table verified.\n";
    
    // Clear the data first
    $pdo->exec("TRUNCATE TABLE honda_dealers");
    echo "Table truncated.\n";

    $urls = [
        'NSW' => 'https://www.goldwing.org.au/nsw-honda-dealers/',
        'VIC' => 'https://www.goldwing.org.au/vic-honda-dealers/',
        'QLD' => 'https://www.goldwing.org.au/queensland-honda-dealers/',
        'WA'  => 'https://www.goldwing.org.au/wa-honda-dealers/',
        'SA'  => 'https://www.goldwing.org.au/sa-honda-dealers/',
        'ACT' => 'https://www.goldwing.org.au/act-honda-dealers/',
    ];
    
    $stmt = $pdo->prepare("INSERT INTO honda_dealers (name, state, website) VALUES (?, ?, ?)");
    
    foreach ($urls as $state => $url) {
        echo "Fetching $state dealers from $url...\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $html = curl_exec($ch);
        curl_close($ch);
        
        if (!$html) {
            echo "Failed to fetch $state.\n";
            continue;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        $buttons = $xpath->query("//a[contains(@class, 'elementor-button')]");
        $count = 0;
        foreach ($buttons as $button) {
            $href = $button->getAttribute('href');
            if (strpos($href, 'pdf') !== false || substr($href, 0, 1) === '#' || $href === 'https://www.goldwing.org.au/chapters-area-reps/') continue;
            
            $textNode = $xpath->query(".//span[contains(@class, 'elementor-button-text')]", $button)->item(0);
            if ($textNode) {
                $innerHtml = $dom->saveHTML($textNode);
                $cleanText = strip_tags(str_replace('<br>', ', ', $innerHtml));
                $cleanText = trim(preg_replace('/\s+/', ' ', $cleanText));
                
                if (!empty($cleanText)) {
                    $stmt->execute([$cleanText, $state, $href]);
                    $count++;
                }
            }
        }
        echo "Found $count dealers for $state.\n";
    }
    
    echo "Migration completed.\n";
} catch (Throwable $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}
