<?php
error_reporting(0);
ini_set('display_errors', 0);
if(session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
require_once __DIR__ . '/../config.php';

function cadSiteApiRespond($payload, $statusCode = 200){
    ApiResponse::fromLegacy($payload, $statusCode, $statusCode);
    exit;
}

function cadSiteApiFail($msg, $statusCode = 400, $logMessage = ''){
    if($logMessage){
        error_log('[CAD site_api] '.$logMessage);
    }
    cadSiteApiRespond(['ok'=>false, 'msg'=>$msg], $statusCode);
}

// 로그인 체크
if(!isset($_SESSION['cad_user'])){
    cadSiteApiRespond(['ok'=>false, 'msg'=>'로그인 필요'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$allowedActions = ['search', 'detail', 'drawings'];
if(!in_array($action, $allowedActions, true)){
    cadSiteApiRespond(['ok'=>false, 'msg'=>'잘못된 요청'], 400);
}

try {
    $pdo = cadGetDB();
} catch(Exception $e){
    cadSiteApiFail('DB 연결 실패', 500, 'DB connect failed: '.$e->getMessage());
}

try {
    switch($action){

        case 'search':
            $q = trim($_GET['q'] ?? '');
            if(strlen($q) < 1){
                cadSiteApiRespond(['ok'=>false, 'msg'=>'검색어를 입력하세요'], 400);
            }

            $isNum = preg_match('/^\d+$/', $q);
            $sql = "SELECT TOP 30
                        s.idx, s.site_number, s.name, s.address,
                        s.construction_date, s.completion_date,
                        s.deadline_status, s.construction,
                        s.external_employee,
                        m.name as member_name,
                        e.name as employee_name,
                        ISNULL((SELECT name FROM Tb_SiteGroup WHERE idx = s.group_idx),'') as group_name,
                        ISNULL((SELECT TOP 1 name FROM Tb_PhoneBook WHERE member_idx = s.member_idx),'') as phonebook_name
                    FROM Tb_Site s
                    LEFT JOIN Tb_Members m ON s.member_idx = m.idx
                    LEFT JOIN Tb_Employee e ON s.employee_idx = e.idx
                    WHERE " . ($isNum ? "s.site_number LIKE ?" : "s.name LIKE ?") . "
                    ORDER BY s.idx DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['%'.$q.'%']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $deadlineMap = [0=>'입력', 1=>'진행', 2=>'완료', 3=>'마감'];
            $result = [];
            foreach($rows as $r){
                $result[] = [
                    'idx'       => $r['idx'],
                    'no'        => $r['site_number'] ?? '',
                    'name'      => $r['name'] ?? '',
                    'addr'      => $r['address'] ?? '',
                    'order'     => $r['member_name'] ?? '',
                    'start'     => $r['construction_date'] ?? '',
                    'end'       => $r['completion_date'] ?? '',
                    'status'    => $deadlineMap[$r['deadline_status']] ?? '',
                    'manager'   => $r['employee_name'] ?? '',
                    'builder'   => $r['construction'] ?? '',
                    'orderMgr'  => $r['phonebook_name'] ?? '',
                    'type'      => $r['group_name'] ?? '',
                    'qty'       => $r['external_employee'] ?? '',
                ];
            }
            cadSiteApiRespond(['ok'=>true, 'data'=>$result]);

        case 'detail':
            $idx = intval($_GET['idx'] ?? 0);
            if(!$idx){
                cadSiteApiRespond(['ok'=>false, 'msg'=>'idx 필요'], 400);
            }
            $sql = "SELECT
                        s.idx, s.site_number, s.name, s.address,
                        s.construction_date, s.completion_date,
                        s.deadline_status, s.construction,
                        s.external_employee, s.member_idx,
                        m.name as member_name,
                        e.name as employee_name,
                        ISNULL((SELECT name FROM Tb_SiteGroup WHERE idx = s.group_idx),'') as group_name,
                        ISNULL((SELECT TOP 1 name FROM Tb_PhoneBook WHERE member_idx = s.member_idx),'') as phonebook_name
                    FROM Tb_Site s
                    LEFT JOIN Tb_Members m ON s.member_idx = m.idx
                    LEFT JOIN Tb_Employee e ON s.employee_idx = e.idx
                    WHERE s.idx = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$idx]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if(!$r){
                cadSiteApiRespond(['ok'=>false, 'msg'=>'현장 없음'], 404);
            }
            $deadlineMap = [0=>'입력', 1=>'진행', 2=>'완료', 3=>'마감'];
            cadSiteApiRespond(['ok'=>true, 'data'=>[
                'idx'       => $r['idx'],
                'no'        => $r['site_number'] ?? '',
                'name'      => $r['name'] ?? '',
                'addr'      => $r['address'] ?? '',
                'order'     => $r['member_name'] ?? '',
                'start'     => $r['construction_date'] ?? '',
                'end'       => $r['completion_date'] ?? '',
                'status'    => $deadlineMap[$r['deadline_status']] ?? '',
                'manager'   => $r['employee_name'] ?? '',
                'builder'   => $r['construction'] ?? '',
                'orderMgr'  => $r['phonebook_name'] ?? '',
                'type'      => $r['group_name'] ?? '',
                'qty'       => $r['external_employee'] ?? '',
            ]]);

        case 'drawings':
            $siteIdx = intval($_GET['site_idx'] ?? 0);
            if(!$siteIdx){
                cadSiteApiRespond(['ok'=>false, 'msg'=>'site_idx 필요'], 400);
            }
            $result = [];
            $statusMap = [0=>'작성',1=>'산출',2=>'완료',3=>'반려',4=>'보류',5=>'승인'];

            try {
                $stmt = $pdo->prepare("SELECT d.idx, d.title, d.description, d.status, d.created_date, d.updated_date,
                    (SELECT name FROM Tb_Employee WHERE idx=d.employee_idx) as emp_name
                    FROM Tb_CAD_Drawing d WHERE d.site_idx=? ORDER BY d.idx DESC");
                $stmt->execute([$siteIdx]);
                $cadRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach($cadRows as $r){
                    $result[] = [
                        'idx'    => $r['idx'],
                        'type'   => 'cad',
                        'title'  => $r['title'] ?? '',
                        'desc'   => $r['description'] ?? '',
                        'status' => $statusMap[intval($r['status']??0)] ?? '작성',
                        'author' => $r['emp_name'] ?? '',
                        'date'   => $r['created_date'] ? date('Y-m-d H:i', strtotime($r['created_date'])) : '',
                    ];
                }
            } catch(Exception $e){
                error_log('[CAD site_api] drawings(cad) failed: '.$e->getMessage());
            }

            try {
                $stmt = $pdo->prepare("SELECT fp.idx, fp.memo, fp.regdate,
                    (SELECT name FROM Tb_Employee WHERE idx=fp.employee_idx) as emp_name,
                    (SELECT TOP 1 file_name FROM Tb_File WHERE parent_idx=fp.idx ORDER BY idx) as file_name
                    FROM Tb_FloorPlan fp WHERE fp.site_idx=? AND fp.plan_type LIKE '%도면%' ORDER BY fp.idx DESC");
                $stmt->execute([$siteIdx]);
                $drawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach($drawRows as $r){
                    $result[] = [
                        'idx'    => $r['idx'],
                        'type'   => 'file',
                        'title'  => $r['file_name'] ?? '파일없음',
                        'desc'   => $r['memo'] ?? '',
                        'status' => '파일',
                        'author' => $r['emp_name'] ?? '',
                        'date'   => $r['regdate'] ? date('Y-m-d H:i', strtotime($r['regdate'])) : '',
                    ];
                }
            } catch(Exception $e){
                error_log('[CAD site_api] drawings(file) failed: '.$e->getMessage());
            }
            cadSiteApiRespond(['ok'=>true, 'data'=>$result]);
    }
} catch(Exception $e){
    cadSiteApiFail('처리 중 오류가 발생했습니다', 500, 'Unhandled action='.$action.' error: '.$e->getMessage());
}
