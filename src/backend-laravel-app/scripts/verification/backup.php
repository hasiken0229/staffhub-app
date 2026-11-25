<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['app:', 'output:']);
if (empty($options['app']) || empty($options['output'])) { exit(2); }
require $options['app'].'/vendor/autoload.php';
$app = require $options['app'].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = $options['output'];
if (!is_dir($directory)) { mkdir($directory,0700,true); }
$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql') { throw new RuntimeException('This backup requires MySQL/MariaDB.'); }
$path = $directory.'/database.sql.gz';
if (file_exists($path)) { throw new RuntimeException('Backup already exists; use another directory.'); }
$pdo = $db->getPdo();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->beginTransaction();
$file = gzopen($path,'wb9');
gzwrite($file,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
$counts = [];
try {
    $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/',$table)) { throw new RuntimeException('Unsupported table name'); }
        $definition = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite($file,'DROP TABLE IF EXISTS `'.$table."`;\n".$definition.";\n");
        $count = 0;
        $rows = $pdo->query('SELECT * FROM `'.$table.'`');
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = '`'.implode('`,`',array_keys($row)).'`';
            $values = array_map(fn ($value) => $value === null ? 'NULL' : $pdo->quote((string)$value),array_values($row));
            gzwrite($file,'INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.implode(',',$values).");\n");
            $count++;
        }
        $counts[$table] = $count;
    }
    gzwrite($file,"SET FOREIGN_KEY_CHECKS=1;\n");
    $pdo->commit();
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    gzclose($file);
}
chmod($path,0600);
file_put_contents($directory.'/database-manifest.json',json_encode([
    'time'=>date(DATE_ATOM),'version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),
    'sha256'=>hash_file('sha256',$path),'tables'=>$counts,
],JSON_PRETTY_PRINT));
echo json_encode(['tables'=>count($counts),'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)])."\n";
