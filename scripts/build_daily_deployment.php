<?php
declare(strict_types=1);

// Included by build_install_sql.php. Keep exact deployment metadata in sync
// with the canonical SQL; --check never changes the saved scripts.
$dailyTableNames=[];$dailyIndexRows=[];$dailyGeneratedNames=[];$dailyGeneratedDefinitions=[];
foreach(['daily_bookings','daily_payments']as$dailyName){
    $dailySource=(string)file_get_contents($root.'/database/'.$dailyName.'.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)\s*\((.*?)\)\s*ENGINE/is',$dailySource,$dailyTables,PREG_SET_ORDER);
    foreach($dailyTables as$dailyTable){
        $dailyTableNames[]=$dailyTable[1];
        preg_match_all('/UNIQUE KEY\s+([a-z0-9_]+)\s*\(([^)]+)\)/i',$dailyTable[2],$dailyIndexes,PREG_SET_ORDER);
        foreach($dailyIndexes as$dailyIndex){
            foreach(explode(',',$dailyIndex[2])as$dailySequence=>$dailyColumn)$dailyIndexRows[]=$dailyTable[1].'.'.$dailyIndex[1].'|'.trim($dailyColumn).'|0|'.($dailySequence+1).'|FULL';
        }
        preg_match_all('/([a-z0-9_]+)\s+(BIGINT UNSIGNED|DECIMAL\([0-9]+,[0-9]+\)|(?:VAR)?CHAR\([0-9]+\))(?:\s+CHARACTER SET\s+[a-z0-9_]+)?(?:\s+COLLATE\s+[a-z0-9_]+)?\s+GENERATED ALWAYS AS\s*\((.*?)\)\s*STORED/is',$dailyTable[2],$dailyColumns,PREG_SET_ORDER);
        foreach($dailyColumns as$dailyColumn){
            $dailyColumnName=$dailyTable[1].'.'.$dailyColumn[1];$dailyGeneratedNames[]=$dailyColumnName;
            $dailyExpression=strtolower(preg_replace('/\s+|[`()]/','',$dailyColumn[3]));
            $dailyGeneratedDefinitions[]=$dailyColumnName.'|'.strtolower($dailyColumn[2]).'|YES|STORED GENERATED|'.$dailyExpression;
        }
    }
}
$monthlyProjectionNames=['payments.active_slip_hmac','payments.credited_txn_ref'];
$monthlyProjectionDefinitions=[
    "payments.active_slip_hmac|char(64)|YES|STORED GENERATED|casewhenstatusin'pending','verified'thenslip_hmacelsenullend",
    "payments.credited_txn_ref|varchar(191)|YES|STORED GENERATED|casewhenstatus='verified'thentransaction_refelsenullend",
];
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)/i',$generatedSchema,$schemaTables);
$schemaTableNames=array_values(array_unique($schemaTables[1]));sort($schemaTableNames,SORT_STRING);
preg_match_all('/CREATE TRIGGER\s+([a-z0-9_]+)\s+(BEFORE|AFTER)\s+(INSERT|UPDATE|DELETE)\s+ON\s+([a-z0-9_]+)/i',$generatedSchema,$schemaTriggers,PREG_SET_ORDER);
$schemaTriggerRows=[];foreach($schemaTriggers as$schemaTrigger)$schemaTriggerRows[$schemaTrigger[1]]=$schemaTrigger[1].'|'.strtoupper($schemaTrigger[2]).'|'.strtoupper($schemaTrigger[3]).'|'.$schemaTrigger[4];
sort($schemaTriggerRows,SORT_STRING);
$dailyBootstrapFile=$root.'/scripts/bootstrap_database.sh';$dailyBootstrap=(string)file_get_contents($dailyBootstrapFile);
$dailyReadList=static function(string $source,string $name):array{
    if(!preg_match('/^'.preg_quote($name,'/').'=\$\x27([^\r\n]*)\x27$/m',$source,$match))throw new RuntimeException('Missing bootstrap metadata '.$name);
    return explode('\\n',$match[1]);
};
$dailyReplaceList=static function(string $source,string $name,array $rows):string{
    $rows=array_values(array_unique($rows));sort($rows,SORT_STRING);
    return preg_replace_callback('/^'.preg_quote($name,'/').'=\$\x27[^\r\n]*\x27$/m',static fn():string=>$name."=$'".implode('\\n',$rows)."'",$source);
};
$dailyStripTables=static fn(array $rows):array=>array_values(array_filter($rows,static fn(string $row):bool=>!in_array(explode('.',explode('|',$row)[0])[0],$dailyTableNames,true)));
$dailyBootstrap=$dailyReplaceList($dailyBootstrap,'expected_tables',$schemaTableNames);
$dailyBootstrap=$dailyReplaceList($dailyBootstrap,'expected_triggers',$schemaTriggerRows);
$dailyBootstrap=$dailyReplaceList($dailyBootstrap,'expected_generated_columns',array_merge($dailyStripTables($dailyReadList($dailyBootstrap,'expected_generated_columns')),$dailyGeneratedNames,$monthlyProjectionNames));
$dailyBaseIndexes=$dailyStripTables($dailyReadList($dailyBootstrap,'expected_unique_indexes'));
foreach($dailyBaseIndexes as&$dailyBaseIndex){
    if(str_starts_with($dailyBaseIndex,'payments.uq_payments_slip_hmac|'))$dailyBaseIndex='payments.uq_payments_slip_hmac|active_slip_hmac|0|1|FULL';
    if(str_starts_with($dailyBaseIndex,'payments.uq_payments_transaction_ref|'))$dailyBaseIndex='payments.uq_payments_transaction_ref|credited_txn_ref|0|1|FULL';
}unset($dailyBaseIndex);
$dailyBootstrap=$dailyReplaceList($dailyBootstrap,'expected_unique_indexes',array_merge($dailyBaseIndexes,$dailyIndexRows));
if(!preg_match('/^expected_generated_column_definitions="(.*?)"/ms',$dailyBootstrap,$dailyDefinitions))throw new RuntimeException('Missing generated definitions');
$dailyDefinitionRows=array_values(array_filter($dailyStripTables(explode("\n",$dailyDefinitions[1])),static fn(string $row):bool=>!in_array(explode('|',$row)[0],$monthlyProjectionNames,true)));
$dailyDefinitionRows=array_merge($dailyDefinitionRows,$dailyGeneratedDefinitions,$monthlyProjectionDefinitions);sort($dailyDefinitionRows,SORT_STRING);
$dailyBootstrap=preg_replace_callback('/^expected_generated_column_definitions=".*?"/ms',static fn():string=>'expected_generated_column_definitions="'.implode("\n",$dailyDefinitionRows).'"',$dailyBootstrap);
$dailyDefinitionPredicates=[];
foreach($dailyDefinitionRows as$dailyDefinition){
    [$dailyQualified]=explode('|',$dailyDefinition);[$dailyDefinitionTable,$dailyDefinitionColumn]=explode('.',$dailyQualified);
    if(!preg_match('/^[a-z0-9_]+$/D',$dailyDefinitionTable)||!preg_match('/^[a-z0-9_]+$/D',$dailyDefinitionColumn))throw new RuntimeException('Invalid schema metadata identifier');
    $dailyDefinitionPredicates[]="(table_name = '{$dailyDefinitionTable}' AND column_name = '{$dailyDefinitionColumn}')";
}
$dailyDefinitionPredicate=implode(' OR ',$dailyDefinitionPredicates);
$dailyBootstrap=preg_replace_callback('/^    actual_generated_column_definitions=".*"$/m',static function(array $match)use($dailyDefinitionPredicate):string{
    return preg_replace('/WHERE table_schema = DATABASE\(\) AND \(.*\)\) column_metadata\) normalized ORDER/','WHERE table_schema = DATABASE() AND ('.$dailyDefinitionPredicate.')) column_metadata) normalized ORDER',$match[0]);
},$dailyBootstrap);
$dailyTableCount=count($schemaTableNames);$dailyTriggerCount=count($schemaTriggerRows);
preg_match_all('/CONSTRAINT\s+([a-z0-9_]+)\s+CHECK/i',$generatedSchema,$dailyCanonicalChecks);
$dailyCheckCount=count(array_unique($dailyCanonicalChecks[1]));
$dailyBootstrap=preg_replace('/\[\[ "\$object_count" == [0-9]+ && "\$base_table_count" == [0-9]+ \]\]/','[[ "$object_count" == '.$dailyTableCount.' && "$base_table_count" == '.$dailyTableCount.' ]]',$dailyBootstrap);
$dailyBootstrap=preg_replace('/exactly the required [0-9]+ base tables/','exactly the required '.$dailyTableCount.' base tables',$dailyBootstrap);
$dailyBootstrap=preg_replace('/\[\[ "\$actual_trigger_count" == [0-9]+ \]\]/','[[ "$actual_trigger_count" == '.$dailyTriggerCount.' ]]',$dailyBootstrap);
$dailyBootstrap=preg_replace('/exactly the required [0-9]+ integrity triggers/','exactly the required '.$dailyTriggerCount.' integrity triggers',$dailyBootstrap);
$dailyProvisionFile=$root.'/scripts/provision_runtime_db_user.sh';$dailyProvision=(string)file_get_contents($dailyProvisionFile);
$dailyProvision=preg_replace_callback('/(\[\[\s*"\$readiness"\s*==\s*)\x27[0-9]+\|1\|1\x27/',static fn(array $match):string=>$match[1]."'".$dailyTableCount."|1|1'",$dailyProvision);
$dailyCiFile=$root.'/.github/workflows/ci.yml';$dailyCi=(string)file_get_contents($dailyCiFile);
$dailyCi=preg_replace_callback('/(\[\s*"\$install_shape"\s*=\s*)\x27[0-9]+\|[0-9]+\|[0-9]+\x27/',static fn(array $match):string=>$match[1]."'".$dailyTableCount.'|'.$dailyTriggerCount.'|'.$dailyCheckCount."'",$dailyCi);
foreach([$dailyBootstrapFile=>$dailyBootstrap,$dailyProvisionFile=>$dailyProvision,$dailyCiFile=>$dailyCi]as$dailyPath=>$dailyContents){
    $dailyOriginal=str_replace(["\r\n","\r"],"\n",(string)file_get_contents($dailyPath));
    $dailyContents=str_replace(["\r\n","\r"],"\n",$dailyContents);
    if($checkOnly){if($dailyOriginal!==$dailyContents){fwrite(STDERR,basename($dailyPath)." deployment metadata is out of date\n");exit(1);}}
    elseif(file_put_contents($dailyPath,$dailyContents,LOCK_EX)!==strlen($dailyContents))throw new RuntimeException('Cannot save deployment metadata');
}
