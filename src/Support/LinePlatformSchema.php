<?php
declare(strict_types=1);

namespace Dormitory\Support;

use PDO;

/** Checks the additive LINE schema against its single, checked-in SQL source. */
final class LinePlatformSchema
{
    /** @return list<string> */
    public static function errors(PDO $pdo): array
    {
        $sql=file_get_contents(dirname(__DIR__,2).'/database/line_platform.sql');
        if(!is_string($sql))return ['Cannot read canonical LINE platform schema'];
        preg_match_all('/CREATE TABLE IF NOT EXISTS (line_[a-z_]+) \((.*?)\) ENGINE=/s',$sql,$tables,PREG_SET_ORDER);
        if(count($tables)!==5)return ['Canonical LINE platform table definitions are incomplete'];
        $columns=[];$unique=[];$foreign=[];$checks=[];$generated=[];
        foreach($tables as[$all,$table,$definition]){
            preg_match_all('/^    ([a-z_]+) ((?:VARCHAR|CHAR)\(\d+\)|BIGINT|SMALLINT|TINYINT|DATETIME\(6\)|TEXT|JSON|ENUM\([^)]*\))( UNSIGNED)?(.*)$/m',$definition,$matches,PREG_SET_ORDER);
            foreach($matches as$column){
                $key=$table.'.'.$column[1];$tail=$column[4];
                $columns[$key]=[strtolower($column[2].($column[3]??'')),str_contains($tail,'NOT NULL')?'NO':'YES'];
                if(preg_match('/GENERATED ALWAYS AS \((.*)\) STORED/',$tail,$expression))$generated[$key]=self::normalize($expression[1]);
            }
            preg_match_all('/UNIQUE KEY ([a-z_]+) \(([^)]+)\)/',$definition,$indexes,PREG_SET_ORDER);
            foreach($indexes as$index)$unique[$table.'.'.$index[1]]=explode(',',str_replace(' ','',$index[2]));
            preg_match_all('/CONSTRAINT ([a-z_]+) FOREIGN KEY \(([a-z_]+)\) REFERENCES ([a-z_]+)\(([a-z_]+)\)/',$definition,$keys,PREG_SET_ORDER);
            foreach($keys as$key)$foreign[$table.'.'.$key[1]]=[$key[2],$key[3],$key[4],'RESTRICT','RESTRICT'];
            preg_match_all('/CONSTRAINT (chk_[a-z_]+) CHECK/',$definition,$names);
            foreach($names[1]as$name)$checks[$name]=true;
        }
        $columns['notification_outbox.line_oa_id']=['bigint unsigned','NO'];
        $columns['notification_outbox.line_binding_id']=['bigint unsigned','YES'];
        $columns['notification_outbox.line_delivery_key']=['bigint unsigned','YES'];
        $generated['notification_outbox.line_delivery_key']='coalesceline_binding_id,0';
        $unique['notification_outbox.uq_notification_outbox_bill_binding']=['bill_id','purpose','line_delivery_key'];
        $foreign['notification_outbox.fk_notification_outbox_oa']=['line_oa_id','line_official_accounts','id','RESTRICT','RESTRICT'];
        $foreign['notification_outbox.fk_notification_outbox_line_binding']=['line_binding_id','line_room_bindings','id','RESTRICT','RESTRICT'];
        $errors=[];$found=[];
        foreach($pdo->query('SELECT table_name,column_name,column_type,is_nullable,extra,generation_expression FROM information_schema.columns WHERE table_schema=DATABASE()')->fetchAll()as$row){
            $row=array_change_key_case($row,CASE_UPPER);
            $key=$row['TABLE_NAME'].'.'.$row['COLUMN_NAME'];
            if(!isset($columns[$key]))continue;$found[$key]=true;
            if([strtolower($row['COLUMN_TYPE']),$row['IS_NULLABLE']]!==$columns[$key])$errors[]='LINE column type mismatch: '.$key;
            if(isset($generated[$key])&&(strtoupper($row['EXTRA'])!=='STORED GENERATED'||self::normalize($row['GENERATION_EXPRESSION'])!==$generated[$key]))$errors[]='LINE generated expression mismatch: '.$key;
            if($key==='line_official_accounts.id'&&str_contains(strtolower($row['EXTRA']),'auto_increment'))$errors[]='LINE legacy ID 0 requires a manually allocated primary key';
        }
        foreach(array_diff_key($columns,$found)as$key=>$ignored)$errors[]='Missing LINE column: '.$key;
        $found=[];
        foreach($pdo->query('SELECT table_name,index_name,column_name,non_unique,seq_in_index,sub_part FROM information_schema.statistics WHERE table_schema=DATABASE() ORDER BY table_name,index_name,seq_in_index')->fetchAll()as$row){
            $row=array_change_key_case($row,CASE_UPPER);
            $key=$row['TABLE_NAME'].'.'.$row['INDEX_NAME'];if(!isset($unique[$key]))continue;
            $found[$key][]=$row['COLUMN_NAME'];if((int)$row['NON_UNIQUE']!==0||$row['SUB_PART']!==null)$errors[]='LINE index must be full and unique: '.$key;
        }
        foreach($unique as$key=>$expected)if(($found[$key]??null)!==$expected)$errors[]='LINE unique index mismatch: '.$key;
        $found=[];
        foreach($pdo->query('SELECT k.table_name,k.constraint_name,k.column_name,k.referenced_table_name,k.referenced_column_name,r.update_rule,r.delete_rule FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.constraint_schema=DATABASE()')->fetchAll()as$row){
            $row=array_change_key_case($row,CASE_UPPER);
            $key=$row['TABLE_NAME'].'.'.$row['CONSTRAINT_NAME'];if(isset($foreign[$key]))$found[$key]=[$row['COLUMN_NAME'],$row['REFERENCED_TABLE_NAME'],$row['REFERENCED_COLUMN_NAME'],$row['UPDATE_RULE'],$row['DELETE_RULE']];
        }
        foreach($foreign as$key=>$expected)if(($found[$key]??null)!==$expected)$errors[]='LINE foreign key mismatch: '.$key;
        $found=[];
        foreach($pdo->query("SELECT constraint_name,enforced FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_type='CHECK'")->fetchAll()as$row){$row=array_change_key_case($row,CASE_UPPER);if($row['ENFORCED']==='YES')$found[$row['CONSTRAINT_NAME']]=true;}
        foreach(array_diff_key($checks,$found)as$key=>$ignored)$errors[]='Missing enforced LINE check: '.$key;
        if($errors===[]&&(int)$pdo->query('SELECT COUNT(*) FROM line_official_accounts WHERE id=0 AND basic_id IS NULL AND access_token_enc IS NULL AND channel_secret_enc IS NULL')->fetchColumn()!==1)$errors[]='Legacy LINE OA metadata row is missing or copied credentials';
        return array_values(array_unique($errors));
    }

    private static function normalize(string $expression): string
    {
        $expression=strtolower(str_replace("\\'","'",$expression));
        $expression=preg_replace("/_[a-z0-9_]+'/","'",$expression)??'';
        return preg_replace('/\s+/','',str_replace(['`','(',')'],'',$expression))??'';
    }
}
