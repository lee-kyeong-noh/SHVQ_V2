<?php
/**
 * DWG ↔ DXF 변환 API (oda_watch.bat 백그라운드 감시 방식)
 * PHP는 convert_in에 파일만 넣고 → watch.bat가 변환 → convert_out에서 결과 수거
 */
if(session_status()===PHP_SESSION_NONE) session_start();
if(!isset($_SESSION['cad_user'])){
    http_response_code(401);
    echo json_encode(['ok'=>false,'msg'=>'로그인 필요']);
    exit;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
set_time_limit(120);
ini_set('memory_limit', '512M');

$CONVERT_IN  = 'D:\\SHV_ERP\\SHVQ_V2\\CAD\\cad_saves\\convert_in\\';
$CONVERT_OUT = 'D:\\SHV_ERP\\SHVQ_V2\\CAD\\cad_saves\\convert_out\\';

if(!is_dir($CONVERT_IN))  mkdir($CONVERT_IN, 0755, true);
if(!is_dir($CONVERT_OUT)) mkdir($CONVERT_OUT, 0755, true);

$mode = $_REQUEST['mode'] ?? 'import';

if($mode === 'export'){
    // ── DXF → DWG 변환 (저장용) ──
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $dxfContent = $input['dxf'] ?? '';
        $filename = preg_replace('/[^a-zA-Z0-9_\-\x{3131}-\x{D7A3}]/u', '_', $input['filename'] ?? 'export');

        if(empty($dxfContent)){
            throw new Exception('DXF 데이터가 비어있습니다.');
        }

        $uid = uniqid();
        $EXPORT_IN  = 'D:\\SHV_ERP\\SHVQ_V2\\CAD\\cad_saves\\export_in\\';
        $EXPORT_OUT = 'D:\\SHV_ERP\\SHVQ_V2\\CAD\\cad_saves\\export_out\\';

        if(!is_dir($EXPORT_IN))  mkdir($EXPORT_IN, 0755, true);
        if(!is_dir($EXPORT_OUT)) mkdir($EXPORT_OUT, 0755, true);

        // DXF 파일을 export_in에 저장 (oda_watch.bat이 감시)
        $dxfName = $uid . '_' . $filename . '.dxf';
        $dwgName = $uid . '_' . $filename . '.dwg';
        $dxfPath = $EXPORT_IN . $dxfName;
        // UTF-8 → EUC-KR 변환 (ANSI_949 = EUC-KR, ODA/스마트캐드 호환)
        $dxfEuckr = mb_convert_encoding($dxfContent, 'EUC-KR', 'UTF-8');
        file_put_contents($dxfPath, $dxfEuckr);

        // export_out에서 DWG 결과 대기 (최대 60초)
        $dwgPath = $EXPORT_OUT . $dwgName;
        $found = false;
        for($i = 0; $i < 60; $i++){
            sleep(1);
            if(file_exists($dwgPath)){
                $size1 = filesize($dwgPath);
                usleep(500000);
                clearstatcache(true, $dwgPath);
                $size2 = filesize($dwgPath);
                if($size1 === $size2 && $size1 > 0){
                    $found = true;
                    break;
                }
            }
        }

        if(!$found){
            $debug = 'dxf_exists:' . (file_exists($dxfPath)?'Y':'N(watch가 가져감)');
            $debug .= ', dwg_exists:' . (file_exists($dwgPath)?'Y':'N');
            $debug .= ', exp_out_files:' . implode(',', array_diff(@scandir($EXPORT_OUT)?:[], ['.','..']));
            $debug .= ', exp_in_files:' . implode(',', array_diff(@scandir($EXPORT_IN)?:[], ['.','..']));
            throw new Exception('DWG 변환 타임아웃(60초). oda_watch.bat 재시작 필요. ' . $debug);
        }

        // DWG 바이너리 반환
        $dwgBinary = file_get_contents($dwgPath);

        // 정리
        @unlink($dxfPath);
        @unlink($dwgPath);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '.dwg"');
        header('Content-Length: ' . strlen($dwgBinary));
        echo $dwgBinary;
        exit;

    } catch(Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }

} else {
    // ── DWG → DXF 변환 (열기용) ──
    header('Content-Type: application/json; charset=utf-8');

    try {
        if(!isset($_FILES['dwg_file']) || $_FILES['dwg_file']['error'] !== UPLOAD_ERR_OK){
            throw new Exception('DWG 파일 업로드 실패. error:'.($_FILES['dwg_file']['error']??'none'));
        }

        $file = $_FILES['dwg_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if(!in_array($ext, ['dwg','dxf'])){
            throw new Exception('DWG 또는 DXF 파일만 지원합니다.');
        }

        // DXF면 바로 반환
        if($ext === 'dxf'){
            echo json_encode(['ok'=>true, 'dxf'=>file_get_contents($file['tmp_name']), 'filename'=>$file['name']]);
            exit;
        }

        // DWG → convert_in에 넣기 (파일명에 고유ID 붙여서 충돌 방지)
        $uid = uniqid();
        $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
        $dwgName = $uid . '_' . $baseName . '.dwg';
        $dxfName = $uid . '_' . $baseName . '.dxf';

        $dwgPath = $CONVERT_IN . $dwgName;
        move_uploaded_file($file['tmp_name'], $dwgPath);

        // convert_out에서 결과 대기 (최대 90초)
        $dxfPath = $CONVERT_OUT . $dxfName;
        $found = false;
        for($i = 0; $i < 90; $i++){
            sleep(1);
            if(file_exists($dxfPath)){
                // 파일 쓰기 완료 대기 (크기 변화 없을 때까지)
                $size1 = filesize($dxfPath);
                usleep(500000);
                clearstatcache(true, $dxfPath);
                $size2 = filesize($dxfPath);
                if($size1 === $size2 && $size1 > 0){
                    $found = true;
                    break;
                }
            }
        }

        if(!$found){
            // 디버그
            $debug = 'dwg_exists:' . (file_exists($dwgPath)?'Y':'N(watch가 가져감)');
            $debug .= ', dxf_exists:' . (file_exists($dxfPath)?'Y':'N');
            $debug .= ', out_files:' . implode(',', array_diff(@scandir($CONVERT_OUT)?:[], ['.','..']));
            throw new Exception('DXF 변환 타임아웃(90초). oda_watch.bat 실행 중인지 확인하세요. ' . $debug);
        }

        $dxfContent = file_get_contents($dxfPath);

        // 정리
        @unlink($dwgPath);
        @unlink($dxfPath);

        echo json_encode([
            'ok' => true,
            'dxf' => $dxfContent,
            'filename' => $baseName . '.dxf'
        ]);

    } catch(Exception $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
}
