<?php
$pdo = new PDO('sqlite:writable/tenderhub.sqlite');
echo "=== notice_documents ===\n";
foreach ($pdo->query("PRAGMA table_info(notice_documents)") as $r) {
    echo "{$r['name']} ({$r['type']})\n";
}
