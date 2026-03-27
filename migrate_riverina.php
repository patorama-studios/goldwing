<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();

$jsonFile = __DIR__ . '/riverina_members.json';
if (!file_exists($jsonFile)) {
    die("JSON file not found. Please ensure riverina_members.json exists in root.\n");
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data) {
    die("Failed to parse JSON data.\n");
}

echo "Found " . count($data) . " rows in JSON.\n";

// Map chapters
$stmt = $pdo->query("SELECT id, name FROM chapters");
$chapters = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $chapters[strtolower($row['name'])] = $row['id'];
}

$pdo->beginTransaction();

$countMain = 0;
$countAssoc = 0;

try {
    $insertMember = $pdo->prepare("
        INSERT INTO members (
            status, member_number_base, member_number_suffix, full_member_id,
            chapter_id, member_type, first_name, last_name, email, phone,
            address_line1, city, state, postal_code, country,
            privacy_level, created_at
        ) VALUES (
            :status, :member_number_base, :member_number_suffix, :full_member_id,
            :chapter_id, :member_type, :first_name, :last_name, :email, :phone,
            :address_line1, :city, :state, :postal_code, 'Australia',
            'A', :created_at
        )
    ");

    $insertUser = $pdo->prepare("
        INSERT INTO users (member_id, name, email, password_hash, is_active, created_at)
        VALUES (:member_id, :name, :email, :password_hash, 1, :created_at)
    ");

    foreach ($data as $i => $row) {
        $memberNum = $row['Member #'] ?? null;
        if (!$memberNum) continue;

        // Chapter
        $chapterName = $row['Chapter'] ?? '';
        $chapterId = $chapters[strtolower(trim($chapterName))] ?? null;
        if (!$chapterId) {
            echo "Warning: Chapter '$chapterName' not found for member $memberNum. Defaulting to NULL.\n";
        }

        // Status based on Expiry Date
        // Enum: 'PENDING','ACTIVE','LAPSED','INACTIVE'
        $status = 'LAPSED';
        $expiryDate = $row['Expiry Date'] ?? null;
        if ($expiryDate) {
            $ts = strtotime($expiryDate);
            if ($ts && $ts > time()) {
                $status = 'ACTIVE';
            }
        }

        // Joined Date
        $joinedTs = strtotime($row['Date Joined(M)'] ?? '');
        $createdAt = $joinedTs ? date('Y-m-d H:i:s', $joinedTs) : date('Y-m-d H:i:s');

        // Main Member
        $firstNameM = trim($row['First Name(M)'] ?? '');
        $lastNameM = trim($row['Surname(M)'] ?? '');
        if (!$firstNameM && !$lastNameM) {
            $firstNameM = "Member";
            $lastNameM = (string)$memberNum;
        }
        $nameM = trim($firstNameM . ' ' . $lastNameM);
        
        $emailM = trim($row['EMail(M)'] ?? '');
        if (!$emailM || $emailM == 'nan') {
            $emailM = "no-email-$memberNum-0@goldwing.org.au";
        }

        $isLife = ($row['Life Member'] ?? false) === true;
        
        $phoneM = trim($row['Phone(M)(M)'] ?? '');
        if ($phoneM == 'nan') $phoneM = '';

        $insertMember->execute([
            ':status' => $status,
            ':member_number_base' => (int)$memberNum,
            ':member_number_suffix' => 0,
            ':full_member_id' => null,
            ':chapter_id' => $chapterId,
            ':member_type' => $isLife ? 'LIFE' : 'FULL',
            ':first_name' => $firstNameM ?: 'Unknown',
            ':last_name' => $lastNameM ?: 'Unknown',
            ':email' => $emailM,
            ':phone' => $phoneM,
            ':address_line1' => trim($row['Postal Address 1'] ?? ''),
            ':city' => trim($row['Suburb'] ?? ''),
            ':state' => trim($row['State'] ?? ''),
            ':postal_code' => trim((string)($row['Postcode'] ?? '')),
            ':created_at' => $createdAt
        ]);
        
        $mainMemberId = $pdo->lastInsertId();
        $countMain++;

        // User for Main
        $insertUser->execute([
            ':member_id' => $mainMemberId,
            ':name' => $nameM ?: 'Unknown',
            ':email' => $emailM,
            ':password_hash' => password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT),
            ':created_at' => $createdAt
        ]);

        // Associate Member
        $firstNameA = trim($row['First Name(A)'] ?? '');
        if ($firstNameA && strtolower($firstNameA) !== 'nan') {
            $lastNameA = trim($row['Surname(A)'] ?? '');
            if (!$lastNameA || strtolower($lastNameA) == 'nan') $lastNameA = $lastNameM;

            $nameA = trim($firstNameA . ' ' . $lastNameA);

            $emailA = trim($row['EMail(A)'] ?? '');
            if (!$emailA || strtolower($emailA) == 'nan') {
                $emailA = "no-email-$memberNum-1@goldwing.org.au";
            }
            if ($emailA == $emailM) {
                 $emailA = "dup-email-a-$memberNum-1@goldwing.org.au";
            }

            $phoneA = trim($row['Phone(M)(A)'] ?? '');
            if ($phoneA == 'nan') $phoneA = '';
            
            $insertMember->execute([
                ':status' => $status,
                ':member_number_base' => (int)$memberNum,
                ':member_number_suffix' => 1,
                ':full_member_id' => $mainMemberId,
                ':chapter_id' => $chapterId, // inherit chapter
                ':member_type' => 'ASSOCIATE',
                ':first_name' => $firstNameA,
                ':last_name' => $lastNameA ?: 'Unknown',
                ':email' => $emailA,
                ':phone' => $phoneA,
                ':address_line1' => trim($row['Postal Address 1'] ?? ''),
                ':city' => trim($row['Suburb'] ?? ''),
                ':state' => trim($row['State'] ?? ''),
                ':postal_code' => trim((string)($row['Postcode'] ?? '')),
                ':created_at' => $createdAt
            ]);
            
            $assocMemberId = $pdo->lastInsertId();
            $countAssoc++;

            // User for Assoc
            $insertUser->execute([
                ':member_id' => $assocMemberId,
                ':name' => $nameA ?: 'Unknown',
                ':email' => $emailA,
                ':password_hash' => password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT),
                ':created_at' => $createdAt
            ]);
        }
    }

    $pdo->commit();
    echo "Migration Complete!\n";
    echo "Main Members Migrated: $countMain\n";
    echo "Associate Members Migrated: $countAssoc\n";

    // DB Hotfixes for live server
    echo "Applying DB Hotfixes...\n";
    $affected = $pdo->exec("UPDATE members m JOIN users u ON m.id = u.member_id SET m.user_id = u.id WHERE m.user_id IS NULL;");
    echo "Fixed user_id on $affected members.\n";
    
    $affectedRoles = $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_key, allowed) SELECT id, 'admin.roles.manage', 1 FROM roles WHERE name = 'admin';");
    echo "Added role management permission to admin role ($affectedRoles affected).\n";

} catch (Exception $e) {
    $pdo->rollBack();
    die("Migration failed: " . $e->getMessage() . "\n");
}
