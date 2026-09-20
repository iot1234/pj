<?php
declare(strict_types=1);
use Dormitory\Domain\MeterReadiness;
$test('meter readiness distinguishes missing occupancy history from an actual vacant baseline',function()use($same):void{
 $row=['current_reading'=>null,'previous_reading'=>null,'selected_occupancy_id'=>7,'occupancy_id'=>null,'occupancy_move_in_period'=>'2026-07-01','occupancy_opening'=>'100.00','prior_current'=>null,'prior_period'=>null,'prior_occupancy_id'=>null,'occupancy_count'=>1,'opening_readings_pending'=>0,'is_billed'=>0,'has_later_reading'=>0];
 $missing=MeterReadiness::describe($row,'2026-09-01');$same('history_gap',$missing['baseline_state']);$same(true,$missing['locked']);$same(false,$missing['is_vacant_baseline']);$same(null,$missing['previous']);
 $first=MeterReadiness::describe($row,'2026-07-01');$same('100.00',$first['previous']);$same(false,$first['locked']);
 $vacant=MeterReadiness::describe(array_replace($row,['selected_occupancy_id'=>null,'occupancy_count'=>0]),'2026-09-01');$same(true,$vacant['is_vacant_baseline']);$same(false,$vacant['locked']);
});
$test('meter readiness rejects previous readings from another month or occupant',function()use($same):void{
 $row=['current_reading'=>null,'previous_reading'=>null,'selected_occupancy_id'=>7,'occupancy_id'=>null,'occupancy_move_in_period'=>'2026-07-01','occupancy_opening'=>'0.00','prior_current'=>'20.00','prior_period'=>'2026-08-01','prior_occupancy_id'=>7,'occupancy_count'=>1,'opening_readings_pending'=>0,'is_billed'=>0,'has_later_reading'=>0];
 $same(false,MeterReadiness::describe($row,'2026-09-01')['locked']);
 foreach([['prior_occupancy_id'=>8],['prior_occupancy_id'=>null],['prior_period'=>'2026-07-01']]as$bad){$result=MeterReadiness::describe(array_replace($row,$bad),'2026-09-01');$same('METER_HISTORY_GAP',$result['issue_code']);$same(null,$result['previous']);}
 $same('AMBIGUOUS_OCCUPANCY',MeterReadiness::describe(array_replace($row,['occupancy_count'=>2]),'2026-09-01')['issue_code']);
 $same('METER_ALREADY_BILLED',MeterReadiness::describe(array_replace($row,['is_billed'=>1]),'2026-09-01')['issue_code']);
});
$test('opaque meter edit versions change with saved values and cannot cross rooms or meter types',function()use($same):void{
 $row=['id'=>1,'occupancy_id'=>7,'previous_reading'=>'10.00','current_reading'=>'20.00','units_used'=>'10.00','updated_at'=>'2026-09-20 10:00:00'];
 $version=MeterReadiness::version(1,'2026-09','water',$row,'test-key');$same(64,strlen($version));
 $same(false,hash_equals($version,MeterReadiness::version(1,'2026-09','water',array_replace($row,['current_reading'=>'21.00']),'test-key')));
 $same(false,hash_equals($version,MeterReadiness::version(2,'2026-09','water',$row,'test-key')));
 $same(false,hash_equals($version,MeterReadiness::version(1,'2026-09','electric',$row,'test-key')));
});
