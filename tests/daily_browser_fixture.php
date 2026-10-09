<?php
declare(strict_types=1);
use Dormitory\Security\Password;
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^appj_browser_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE')))exit(64);
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
foreach(['admin_users','rooms','daily_bookings','payments']as$table)if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Fresh browser fixture required');
$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,active,auth_version) VALUES('daily_browser_owner',?,'owner',1,1)")->execute([Password::hash('Daily-Browser-Fixture-Only-2026!')]);
$app->rooms()->create(['room_code'=>'DAILY-PUBLIC','floor'=>1,'room_type'=>'รายวันสำหรับทดสอบ','rental_mode'=>'daily','daily_rate'=>'650.00','daily_deposit'=>'100.00','max_guests'=>2,'description'=>'พักรายวัน รวมค่าน้ำไฟ','image_key'=>'room-standard.jpg']);
$app->rooms()->create(['room_code'=>'DAILY-OWNER','floor'=>1,'room_type'=>'รายวันสำหรับทดสอบ','rental_mode'=>'daily','daily_rate'=>'450.00','daily_deposit'=>'100.00','max_guests'=>2,'image_key'=>'room-deluxe.jpg']);
$app->rooms()->create(['room_code'=>'MONTHLY-KEEP','floor'=>1,'room_type'=>'รายเดือนสำหรับทดสอบ','monthly_rent'=>'3500.00']);
echo "PASS isolated browser owner and daily/monthly rooms ready\n";
