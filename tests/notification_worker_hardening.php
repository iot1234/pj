<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$service=file_get_contents($root.'/src/Domain/NotificationService.php');
$worker=file_get_contents($root.'/scripts/process_notifications.php');
$migration=file_get_contents($root.'/database/migrations/007_notification_worker_fencing.sql');
if(!is_string($service)||!is_string($worker)||!is_string($migration)){
    throw new RuntimeException('Cannot read notification worker hardening sources');
}

$requiredServiceContracts=[
    'private const CLAIM_LEASE_SECONDS = 120',
    'FOR UPDATE SKIP LOCKED',
    "claim_token=?",
    "lease_until>UTC_TIMESTAMP(6)",
    "AND claim_token=?",
    "claim_token=NULL,lease_until=NULL",
    'recoverExpiredClaims',
    'recordWorkerHeartbeat',
    'workerHealth',
    'lost_claims',
];
foreach($requiredServiceContracts as$contract){
    if(!str_contains($service,$contract))throw new RuntimeException('Missing service contract: '.$contract);
}
if(str_contains($service,"status='processing' AND updated_at < DATE_SUB")){
    throw new RuntimeException('Legacy timestamp-only claim recovery remains');
}
foreach([
    'recordWorkerHeartbeat',
    "'starting'","'running'","'error'",
    '$arguments===',
    "'--help must be used by itself'",
    "'Unknown or invalid argument'",
    "'Duplicate argument: --'",
]as$contract){
    if(!str_contains($worker,$contract))throw new RuntimeException('Missing worker heartbeat contract: '.$contract);
}
if(str_contains($worker,'max(1,min(100')||str_contains($worker,'max(1,min(300')){
    throw new RuntimeException('Worker CLI still silently clamps invalid arguments');
}
foreach([
    'claim_token','lease_until','chk_notification_outbox_claim_lease',
    'idx_notification_outbox_lease','notification_worker_heartbeats',
]as$contract){
    if(!str_contains($migration,$contract))throw new RuntimeException('Missing migration contract: '.$contract);
}
if(!str_contains($migration,"status = 'processing'")
    ||!str_contains($migration,"status <> ''processing''")){
    throw new RuntimeException('Claim/lease state constraint is incomplete');
}

fwrite(STDOUT,"PASS notification worker claim fencing and heartbeat contracts\n");
